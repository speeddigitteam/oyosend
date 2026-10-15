<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ExternalLink, History, Info, MessageSquare, SendHorizontal, Sparkles, Undo2 } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import {
    attachmentIcon,
    chatItems,
    type ChatItem,
    COUNTER_FROM,
    type DayLabels,
    deliveryState,
    ENTER_SENDS_QUERY,
    type InboxThreadMessage,
    type InboxThreadView,
    isNearBottom,
    isNearTop,
    linkify,
    matchesMedia,
    MAX_TEXT,
    nextMessageLimit,
    planScroll,
    requestReplySuggestion,
    separatorLabel,
    shouldSend,
} from './inboxChat';
import InboxAvatar from './InboxAvatar.vue';
import InboxChannelIcon from './InboxChannelIcon.vue';
import InboxDeliveryTick from './InboxDeliveryTick.vue';

/**
 * One conversation open in the unified inbox (ADR 0030), drawn like a messenger: a header, the messages on a quiet
 * wallpaper with day separators, grouped bubbles and delivery ticks, and a composer. Message text is customer-controlled
 * and only ever rendered as text (links through {@see linkify}, http and https only). The composer posts to the inbox,
 * which hands the reply to the owning app; the server decides whether it may be sent, this only hides what the member
 * cannot use and shows the reply as "sending" until the server answers.
 *
 * "Suggest a reply" (when the thread offers `aiDrafting`) asks the server for an AI draft and puts it into the composer,
 * marked as AI-generated: the member checks, edits and sends it like any reply. Nothing is sent or saved by asking.
 */
const props = withDefaults(defineProps<{ thread: InboxThreadView; olderLoading?: boolean }>(), { olderLoading: false });
const emit = defineEmits<{ close: []; older: [limit: number] }>();

type MessageItem = Extract<ChatItem, { kind: 'message' }>;

const { t, te } = useI18n();
const format = useFormat();
const page = usePage();
const composerId = useId();

const text = ref('');
const error = ref<string | undefined>(undefined);
const sending = ref(false);
const pending = ref<InboxThreadMessage[]>([]);
const scroller = ref<HTMLElement | null>(null);
const field = ref<HTMLTextAreaElement | null>(null);
const atBottom = ref(true);
const unseen = ref(0);
const enterSends = ref(false);
const now = ref(Date.now());

const timezone = computed(() => page.props.app.timezone);
const nextLimit = computed(() => nextMessageLimit(props.thread.limit));
const canSend = computed(() => props.thread.canReply && !sending.value && text.value.trim() !== '');
const blockedReason = computed(() => props.thread.replyBlockedReason ?? t('communication.inbox.chat.cannotReply'));
const remaining = computed(() => MAX_TEXT - text.value.length);

// ---- "Suggest a reply" ------------------------------------------------------------------------------------------------

const suggesting = ref(false);
// The composer holds an AI draft the member has not sent yet; editing it keeps the label until it is sent or cleared.
const aiDraft = ref(false);
// What the member had typed before the draft replaced it, so it can be put back.
const previousDraft = ref<string | null>(null);
// Why no suggestion came (the server's safe message, or the page's own wording).
const suggestNotice = ref<string | null>(null);
const canSuggest = computed(() => props.thread.aiDrafting === true && props.thread.canReply);
let suggestRequest: AbortController | null = null;

async function suggest(): Promise<void> {
    if (!canSuggest.value || suggesting.value) return;

    const thread = props.thread.key;
    suggesting.value = true;
    suggestNotice.value = null;
    suggestRequest = new AbortController();

    try {
        const result = await requestReplySuggestion(route('communication.inbox.suggest', { source: props.thread.source, id: props.thread.id }), suggestRequest.signal);

        if (props.thread.key !== thread) return;

        if (result.state === 'ok' && result.text.trim() !== '') {
            previousDraft.value = text.value.trim() === '' || aiDraft.value ? previousDraft.value : text.value;
            text.value = result.text.slice(0, MAX_TEXT);
            aiDraft.value = true;
            error.value = undefined;
            void nextTick(() => field.value?.focus());
        } else {
            suggestNotice.value = result.message !== '' ? result.message : t('communication.inbox.chat.suggestFailed');
        }
    } finally {
        suggesting.value = false;
        suggestRequest = null;
    }
}

function restoreDraft(): void {
    text.value = previousDraft.value ?? '';
    previousDraft.value = null;
    aiDraft.value = false;
    void nextTick(() => field.value?.focus());
}

function resetSuggestion(): void {
    suggestRequest?.abort();
    aiDraft.value = false;
    previousDraft.value = null;
    suggestNotice.value = null;
}

// A cleared composer no longer holds the draft.
watch(text, (value) => {
    if (value.trim() === '') {
        aiDraft.value = false;
        previousDraft.value = null;
    }
});

// The page's own replies on their way, until the stored message arrives with the thread (or the server refuses).
const shownPending = computed(() => pending.value.filter((local) => !props.thread.messages.some((message) =>
    message.direction === 'out' && message.text === local.text && (Date.parse(message.at) || 0) >= (Date.parse(local.at) || 0) - 120_000)));
const messages = computed<InboxThreadMessage[]>(() => [...props.thread.messages, ...shownPending.value]);
const items = computed(() => chatItems(messages.value, timezone.value));

const labels = computed<DayLabels>(() => ({
    today: t('communication.inbox.chat.today'),
    yesterday: t('communication.inbox.chat.yesterday'),
    time: (value) => format.date(value, 'time'),
    weekday: (value) => format.date(value, 'weekday'),
    short: (value) => format.date(value, 'short'),
    date: (value) => format.date(value, 'date'),
}));

/** A comment's state ("Hidden", "Deleted"): shown as a word, not a tick. */
function note(message: InboxThreadMessage): string | null {
    if (message.status === null || deliveryState(message) !== null) {
        return null;
    }

    const key = `communication.inbox.chat.status.${message.status}`;

    return te(key) ? t(key) : null;
}

function bubble(item: MessageItem): string[] {
    const out = item.message.direction === 'out';
    const failed = deliveryState(item.message)?.tick === 'failed';

    return [
        'relative min-w-0 max-w-[min(85%,38rem)] rounded-[0.875rem] px-3 pt-1.5 pb-2 text-sm text-ink shadow-[0_1px_0_var(--line-strong)]',
        out ? 'mr-2 bg-chat-out' : 'ml-2 bg-chat-in',
        failed ? 'ring-1 ring-danger/50' : '',
        // The first bubble of a group carries the tail, drawn as a small triangle of the bubble's own colour.
        item.first && out ? 'rounded-tr-sm before:absolute before:top-0 before:-right-2 before:size-0 before:border-t-8 before:border-r-8 before:border-t-chat-out before:border-r-transparent' : '',
        item.first && !out ? 'rounded-tl-sm before:absolute before:top-0 before:-left-2 before:size-0 before:border-t-8 before:border-l-8 before:border-t-chat-in before:border-l-transparent' : '',
    ];
}

// ---- scrolling -------------------------------------------------------------------------------------------------------

function scrollToEnd(): void {
    const element = scroller.value;

    if (element) {
        element.scrollTop = element.scrollHeight;
        atBottom.value = true;
        unseen.value = 0;
    }
}

/** The first message at least partly in view, and how far below the top of the log it starts. */
function measureAnchor(element: HTMLElement): { id: string; offset: number } | null {
    for (const node of element.querySelectorAll<HTMLElement>('[data-message-id]')) {
        if (node.offsetTop + node.offsetHeight > element.scrollTop) {
            return { id: node.dataset.messageId ?? '', offset: node.offsetTop - element.scrollTop };
        }
    }

    return null;
}

function restoreAnchor(element: HTMLElement, anchor: { id: string; offset: number }): void {
    for (const node of element.querySelectorAll<HTMLElement>('[data-message-id]')) {
        if (node.dataset.messageId === anchor.id) {
            element.scrollTop = node.offsetTop - anchor.offset;

            return;
        }
    }
}

let shownKey: string | null = null;
let shownMessages: readonly InboxThreadMessage[] | null = null;
let ownReply = false;
let olderAsked = false;

// Measured before the log re-renders: stay at the newest message when the reader was there (or just replied),
// otherwise keep the message in view where it is (older ones loading above, newer ones below) and count the new ones.
watch(messages, (next) => {
    const element = scroller.value;
    const plan = planScroll(shownKey === props.thread.key ? shownMessages : null, next, element === null || isNearBottom(element), ownReply);

    ownReply = false;
    shownKey = props.thread.key;
    shownMessages = next;

    if (plan.action === 'bottom') {
        void nextTick(scrollToEnd);

        return;
    }

    unseen.value += plan.unseen;
    const anchor = element === null ? null : measureAnchor(element);

    if (element !== null && anchor !== null) {
        void nextTick(() => restoreAnchor(element, anchor));
    }
}, { flush: 'pre' });

// Another conversation starts with an empty composer.
watch(() => props.thread.key, () => {
    resetSuggestion();
    text.value = '';
    error.value = undefined;
    pending.value = [];
    unseen.value = 0;
    olderAsked = false;
});

watch(() => props.olderLoading, (loading) => {
    if (!loading) olderAsked = false;
});

function onScroll(): void {
    const element = scroller.value;

    if (element === null) return;

    atBottom.value = isNearBottom(element);

    if (atBottom.value) {
        unseen.value = 0;
    }

    // Scrolling to the top asks for older messages, once per load.
    if (isNearTop(element) && element.scrollHeight > element.clientHeight && props.thread.hasOlder && nextLimit.value !== null && !props.olderLoading && !olderAsked) {
        olderAsked = true;
        emit('older', nextLimit.value);
    }
}

function older(): void {
    if (nextLimit.value !== null) {
        olderAsked = true;
        emit('older', nextLimit.value);
    }
}

let clock: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    shownKey = props.thread.key;
    shownMessages = messages.value;
    enterSends.value = matchesMedia(ENTER_SENDS_QUERY);
    scrollToEnd();
    // "Today" becomes "Yesterday" at midnight without a reload.
    clock = setInterval(() => { now.value = Date.now(); }, 60_000);
});

onBeforeUnmount(() => {
    if (clock !== null) clearInterval(clock);
    suggestRequest?.abort();
});

// ---- composer ------------------------------------------------------------------------------------------------------------

/** Grows with its text up to six lines, then scrolls. */
function grow(): void {
    const element = field.value;

    if (element === null) return;

    element.style.height = 'auto';

    if (element.scrollHeight === 0) {
        element.style.removeProperty('height');

        return;
    }

    const style = getComputedStyle(element);
    const line = Number.parseFloat(style.lineHeight) || 20;
    const box = (Number.parseFloat(style.paddingTop) || 0) + (Number.parseFloat(style.paddingBottom) || 0);
    const border = (Number.parseFloat(style.borderTopWidth) || 0) + (Number.parseFloat(style.borderBottomWidth) || 0);
    const most = line * 6 + box + border;
    const wanted = element.scrollHeight + border;

    element.style.height = `${Math.min(wanted, most)}px`;
    element.style.overflowY = wanted > most ? 'auto' : 'hidden';
}

watch(text, () => { void nextTick(grow); });

let sequence = 0;

function send(): void {
    const body = text.value;

    if (!props.thread.canReply || sending.value || body.trim() === '') return;

    const thread = props.thread.key;
    const local: InboxThreadMessage = { id: `pending-${++sequence}`, direction: 'out', text: body, at: new Date().toISOString(), author: null, status: 'sending', attachment: null, attachmentKind: null };

    sending.value = true;
    error.value = undefined;
    suggestNotice.value = null;
    text.value = '';
    ownReply = true;
    pending.value = [...pending.value, local];

    router.post(route('communication.inbox.reply', { source: props.thread.source, id: props.thread.id }), { text: body }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            if (props.thread.key !== thread) return;
            error.value = errors.text ?? Object.values(errors)[0];
            // The text stays the member's: back in the composer, unless they already started another one.
            if (text.value === '') text.value = body;
        },
        onFinish: () => {
            sending.value = false;
            pending.value = pending.value.filter((message) => message.id !== local.id);
        },
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (shouldSend(event, matchesMedia(ENTER_SENDS_QUERY))) {
        event.preventDefault();
        send();
    }
}
</script>

<template>
    <section class="flex min-h-0 min-w-0 flex-1 flex-col" :aria-labelledby="`inbox-thread-${thread.id}`" data-testid="inbox-thread">
        <header class="flex min-h-16 items-center gap-2 border-b border-line px-2 py-2 sm:gap-3 sm:px-4">
            <Button class="md:hidden" variant="ghost" size="sm" icon @click="emit('close')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('communication.inbox.chat.back') }}
            </Button>
            <InboxAvatar :title="thread.title" :seed="thread.key" size="md" />
            <div class="min-w-0 flex-1">
                <h2 :id="`inbox-thread-${thread.id}`" class="truncate font-sans text-[0.9375rem] font-semibold tracking-normal text-ink">{{ thread.title }}</h2>
                <p class="mt-0.5 flex min-w-0 items-center gap-1.5 text-[0.8125rem] text-ink-subtle">
                    <InboxChannelIcon :channel="thread.channel" :source-label="thread.sourceLabel" />
                    <span class="shrink-0">{{ thread.sourceLabel }}</span>
                    <template v-if="thread.subtitle">
                        <span class="size-1 shrink-0 rounded-full bg-current" aria-hidden="true" />
                        <span class="truncate">{{ thread.subtitle }}</span>
                    </template>
                </p>
            </div>
            <Button variant="ghost" size="sm" :href="thread.url">
                <template #icon><ExternalLink class="size-4" aria-hidden="true" /></template>
                <span class="sr-only sm:not-sr-only">{{ t('communication.inbox.chat.openInApp') }}</span>
            </Button>
        </header>

        <div class="chat-wallpaper min-h-0 flex-1">
            <div
                ref="scroller"
                class="absolute inset-0 overflow-y-auto overscroll-contain px-2 py-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand sm:px-6 lg:px-10"
                role="log"
                tabindex="0"
                :aria-label="t('communication.inbox.chat.messages')"
                data-testid="inbox-scroller"
                @scroll.passive="onScroll"
            >
                <!-- A short conversation sits above the composer, as in a messenger, not at the top of the pane. -->
                <div class="flex min-h-full flex-col justify-end">
                    <div v-if="thread.hasOlder" class="mb-1 flex justify-center">
                        <Button v-if="nextLimit !== null" size="sm" variant="secondary" :loading="olderLoading" @click="older">
                            <template #icon><History class="size-4" aria-hidden="true" /></template>
                            {{ t('communication.inbox.chat.older') }}
                        </Button>
                        <p v-else class="rounded-chip bg-chat-in px-3 py-1 text-xs text-ink-subtle">{{ t('communication.inbox.chat.olderInApp') }}</p>
                    </div>

                    <EmptyState v-if="messages.length === 0" :title="t('communication.inbox.chat.empty')" compact><template #icon><MessageSquare /></template></EmptyState>
                    <ol v-else class="flex flex-col pb-1">
                        <template v-for="item in items" :key="item.key">
                            <li v-if="item.kind === 'day'" class="sticky top-0 z-1 flex justify-center pt-3 pb-1.5" data-day>
                                <span class="rounded-chip bg-chat-in px-3 py-1 text-xs font-medium text-ink-muted shadow-[0_1px_0_var(--line-strong)]">{{ separatorLabel(item.at, now, timezone, labels) }}</span>
                            </li>
                            <li
                                v-else
                                :class="['flex', item.message.direction === 'in' ? 'justify-start' : 'justify-end', item.first ? 'mt-2' : 'mt-0.5']"
                                :data-direction="item.message.direction"
                                :data-message-id="item.message.id"
                                :data-pending="item.message.status === 'sending' ? 'true' : undefined"
                            >
                                <div :class="bubble(item)">
                                    <p v-if="item.showAuthor" class="mb-0.5 truncate text-xs font-semibold text-brand-text">{{ item.message.author }}</p>
                                    <div v-if="item.message.attachment" class="mt-0.5 mb-1 flex w-fit max-w-full items-center gap-1.5 rounded-chip bg-hover px-2 py-1 text-xs text-ink-muted" :data-attachment="item.message.attachmentKind ?? 'other'">
                                        <component :is="attachmentIcon(item.message.attachmentKind)" class="size-3.5 shrink-0" aria-hidden="true" />
                                        <span class="sr-only">{{ t('communication.inbox.chat.attachment', { label: item.message.attachment }) }}</span>
                                        <span class="truncate" aria-hidden="true">{{ item.message.attachment }}</span>
                                    </div>
                                    <p v-if="item.message.text" class="whitespace-pre-wrap wrap-anywhere">
                                        <template v-for="(token, index) in linkify(item.message.text)" :key="index">
                                            <a v-if="token.kind === 'link'" :href="token.href" target="_blank" rel="noopener noreferrer nofollow" class="text-brand-text underline underline-offset-2 hover:no-underline">{{ token.text }}</a>
                                            <template v-else>{{ token.text }}</template>
                                        </template>
                                        <span class="relative top-1 float-right ml-3 mt-1 flex items-center gap-1 whitespace-nowrap text-[0.6875rem] leading-4 text-ink-subtle">
                                            <span v-if="note(item.message)">{{ note(item.message) }}</span>
                                            <time class="tabular" :datetime="item.message.at" :title="format.date(item.message.at, 'datetime')">{{ format.date(item.message.at, 'time') }}</time>
                                            <InboxDeliveryTick v-if="deliveryState(item.message)" :state="deliveryState(item.message)!" />
                                        </span>
                                    </p>
                                    <div v-else class="flex items-end justify-between gap-3">
                                        <span v-if="!item.message.attachment" class="text-ink-subtle">{{ t('communication.inbox.chat.noText') }}</span>
                                        <span class="ml-auto flex items-center gap-1 whitespace-nowrap text-[0.6875rem] leading-4 text-ink-subtle">
                                            <span v-if="note(item.message)">{{ note(item.message) }}</span>
                                            <time class="tabular" :datetime="item.message.at" :title="format.date(item.message.at, 'datetime')">{{ format.date(item.message.at, 'time') }}</time>
                                            <InboxDeliveryTick v-if="deliveryState(item.message)" :state="deliveryState(item.message)!" />
                                        </span>
                                    </div>
                                </div>
                            </li>
                        </template>
                    </ol>
                </div>
            </div>

            <button
                v-if="!atBottom || unseen > 0"
                type="button"
                class="glass-solid absolute right-4 bottom-4 z-10 flex size-10 items-center justify-center rounded-full text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                data-testid="jump-to-latest"
                @click="scrollToEnd"
            >
                <ArrowDown class="size-4" aria-hidden="true" />
                <span class="sr-only">{{ t('communication.inbox.chat.jumpToLatest') }}</span>
                <template v-if="unseen > 0">
                    <span class="tabular absolute -top-1.5 -right-1 min-w-5 rounded-full bg-accent px-1.5 text-center text-[0.6875rem] leading-5 font-semibold text-accent-ink" aria-hidden="true">{{ format.number(unseen) }}</span>
                    <span class="sr-only">{{ t('communication.inbox.chat.newMessages', { count: format.number(unseen) }) }}</span>
                </template>
            </button>
        </div>

        <footer v-if="thread.replyAllowed" class="border-t border-line">
            <!-- A reply cannot be written now: the reason replaces the composer, with the way to the app that can act. -->
            <div v-if="!thread.canReply" class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-warn-soft px-4 py-3" role="status">
                <Info class="size-4 shrink-0 text-warn" aria-hidden="true" />
                <p class="min-w-0 flex-1 text-[0.8125rem] text-warn" data-testid="reply-blocked">{{ blockedReason }}</p>
                <Button size="sm" variant="secondary" :href="thread.url">
                    <template #icon><ExternalLink class="size-4" aria-hidden="true" /></template>
                    {{ t('communication.inbox.chat.openInApp') }}
                </Button>
            </div>
            <form v-else class="flex items-end gap-2 px-2 py-2.5 sm:px-4" :aria-label="t('communication.inbox.chat.composer')" @submit.prevent="send">
                <div class="min-w-0 flex-1">
                    <label :for="composerId" class="sr-only">{{ t('communication.inbox.chat.composer') }}</label>
                    <textarea
                        :id="composerId"
                        ref="field"
                        v-model="text"
                        rows="1"
                        :maxlength="MAX_TEXT"
                        :placeholder="t('communication.inbox.chat.placeholder')"
                        :aria-invalid="error ? 'true' : undefined"
                        :aria-describedby="error ? `${composerId}-error ${composerId}-keys` : aiDraft && !suggestNotice ? `${composerId}-ai ${composerId}-keys` : `${composerId}-keys`"
                        class="block max-h-40 min-h-11 w-full resize-none overflow-y-hidden rounded-[1.375rem] border border-line-strong bg-control-sunken px-4 py-2.5 text-sm leading-5 text-ink shadow-[inset_0_1px_2px_var(--line)] transition-[border-color,box-shadow] placeholder:text-ink-subtle focus:border-brand focus:bg-control-hover focus:shadow-[0_0_0_3px_var(--ring)] focus:outline-none aria-invalid:border-danger"
                        @keydown="onKeydown"
                    />
                    <p v-if="error" :id="`${composerId}-error`" class="mt-1.5 px-3 text-[0.8125rem] text-danger" role="alert">{{ error }}</p>
                    <p v-else-if="suggestNotice" class="mt-1.5 flex items-start gap-1.5 px-3 text-[0.8125rem] text-ink-muted" role="status" data-testid="suggest-notice">
                        <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        <span>{{ suggestNotice }}</span>
                    </p>
                    <div v-else-if="aiDraft" :id="`${composerId}-ai`" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 px-3 text-[0.8125rem] text-ink-muted" data-testid="suggest-hint">
                        <span class="flex items-center gap-1.5"><Sparkles class="size-3.5 shrink-0 text-brand-text" aria-hidden="true" />{{ t('communication.inbox.chat.suggestionHint') }}</span>
                        <button v-if="previousDraft !== null" type="button" class="inline-flex items-center gap-1 rounded-control font-medium text-brand-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" @click="restoreDraft">
                            <Undo2 class="size-3.5" aria-hidden="true" />{{ t('communication.inbox.chat.restoreDraft') }}
                        </button>
                    </div>
                    <p v-else-if="remaining <= COUNTER_FROM" class="tabular mt-1 px-3 text-xs text-ink-subtle" aria-live="polite">{{ t('communication.inbox.chat.remaining', { count: format.number(Math.max(remaining, 0)) }) }}</p>
                    <p :id="`${composerId}-keys`" class="sr-only">{{ enterSends ? t('communication.inbox.chat.shortcutEnter') : t('communication.inbox.chat.shortcut') }}</p>
                </div>
                <span v-if="canSuggest" class="shrink-0 [&>button]:rounded-full">
                    <Button variant="ghost" size="lg" icon :loading="suggesting" :disabled="sending" :title="t('communication.inbox.chat.suggest')" data-testid="suggest-reply" @click="suggest">
                        <template #icon><Sparkles class="size-4.5" aria-hidden="true" /></template>
                        {{ suggesting ? t('communication.inbox.chat.suggesting') : t('communication.inbox.chat.suggest') }}
                    </Button>
                </span>
                <span class="shrink-0 [&>button]:rounded-full">
                    <Button type="submit" variant="primary" size="lg" icon :disabled="!canSend">
                        <template #icon><SendHorizontal class="size-4.5" aria-hidden="true" /></template>
                        {{ t('communication.inbox.chat.send') }}
                    </Button>
                </span>
            </form>
        </footer>
    </section>
</template>
