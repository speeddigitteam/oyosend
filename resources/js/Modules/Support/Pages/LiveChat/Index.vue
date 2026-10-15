<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronLeft, ChevronRight, History, LifeBuoy, MessageCircle, Search, Send, Settings, UserPlus, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChatTicketDialog from '../../Components/ChatTicketDialog.vue';
import SupportNav from '../../Components/SupportNav.vue';

defineOptions({ layout: TenantLayout });

/**
 * Website chats (ADR 0032): the list beside one open conversation, a composer and a close button. Visitor text is
 * untrusted and only ever interpolated as text. While a chat is open the page reloads the list and the thread every
 * 10 seconds, skipped while the tab is hidden; the server decides every permission, this only hides what is not
 * possible.
 */

type ChatState = 'open' | 'unassigned' | 'mine' | 'closed' | 'all';

interface ChatRow {
    id: string;
    title: string;
    email: string | null;
    preview: string | null;
    status: 'open' | 'closed';
    assignee: string | null;
    unread: number;
    lastMessageAt: string | null;
}

interface ChatMessageView {
    id: string;
    direction: 'in' | 'out';
    text: string;
    at: string;
    author: string | null;
}

interface ChatThread {
    id: string;
    title: string;
    name: string | null;
    email: string | null;
    pageUrl: string | null;
    status: 'open' | 'closed';
    assignee: string | null;
    startedAt: string;
    closedAt: string | null;
    mayReply: boolean;
    canReply: boolean;
    replyBlockedReason: string | null;
    canClose: boolean;
    /** The shared contact the visitor was saved as; `name` and `search` are null when it is outside the member's reach. */
    contact: { name: string | null; search: string | null } | null;
    canSaveContact: boolean;
    ticket: { id: string; number: string; canOpen: boolean } | null;
    canCreateTicket: boolean;
    ticketNeedsContact: boolean;
    ticketForm: { subject: string; priorities: string[]; queues: { id: string; name: string }[] } | null;
    hasOlder: boolean;
    limit: number;
    messages: ChatMessageView[];
}

const props = defineProps<{
    filters: { state: ChatState; search: string; open: string | null };
    enabled: boolean;
    canManageSettings: boolean;
    thread: ChatThread | null;
    conversations: { items: ChatRow[]; next: string | null; previous: string | null };
}>();

const POLL_INTERVAL = 10_000;
const MESSAGES_STEP = 50;
const MESSAGES_MAX = 200;
const MAX_TEXT = 2000;
const STATES: ChatState[] = ['open', 'unassigned', 'mine', 'closed', 'all'];

const { t } = useI18n();
const format = useFormat();

const state = ref<ChatState>(props.filters.state);
const search = ref(props.filters.search);
const text = ref('');
const error = ref<string | undefined>(undefined);
const sending = ref(false);
const confirmClose = ref(false);
const closing = ref(false);
const savingContact = ref(false);
const contactError = ref<string | undefined>(undefined);
const ticketOpen = ref(false);
const scroller = ref<HTMLElement | null>(null);

const stateOptions = computed(() => STATES.map((value) => ({ value, label: t(`support.liveChat.states.${value}`) })));
const canSend = computed(() => props.thread?.canReply === true && !sending.value && text.value.trim() !== '');
const subtitle = computed(() => {
    const thread = props.thread;

    if (!thread) return null;

    return [thread.name !== null ? thread.email : null, thread.pageUrl].filter((part): part is string => !!part).join(' · ') || null;
});

/** The page's address parameters, without empty values and without the default state. */
function query(extra: Record<string, string | number | null> = {}): Record<string, string | number> {
    const all: Record<string, string | number | null> = { state: state.value, search: search.value.trim(), open: props.filters.open, ...extra };
    const params: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(all)) {
        if (value !== null && value !== '' && !(key === 'state' && value === 'open')) {
            params[key] = value;
        }
    }

    return params;
}

function visit(params: Record<string, string | number>, only?: string[]): void {
    router.get(route('support.live_chat.index'), params, { preserveState: true, preserveScroll: true, replace: true, only });
}

function applyFilters(): void {
    visit(query());
}

watch(state, applyFilters);

function openUrl(id: string): string {
    return route('support.live_chat.index', query({ open: id }));
}

function page(cursor: string): void {
    visit({ ...query(), cursor }, ['conversations']);
}

function older(): void {
    if (!props.thread) return;
    const limit = Math.min(MESSAGES_MAX, props.thread.limit + MESSAGES_STEP);
    visit(query({ messages: limit }), ['thread']);
}

function scrollToEnd(): void {
    void nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    });
}

watch(() => [props.thread?.id, props.thread?.messages.at(-1)?.id] as const, ([id], [previousId]) => {
    if (id !== previousId) {
        text.value = '';
        error.value = undefined;
        contactError.value = undefined;
        ticketOpen.value = false;
    }

    scrollToEnd();
});

function send(): void {
    if (!props.thread || !canSend.value) return;
    sending.value = true;
    error.value = undefined;

    router.post(route('support.live_chat.reply', { conversation: props.thread.id }), { text: text.value }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { text.value = ''; },
        onError: (errors) => { error.value = errors.text ?? Object.values(errors)[0]; },
        onFinish: () => { sending.value = false; },
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

function close(): void {
    if (!props.thread) return;
    closing.value = true;

    router.post(route('support.live_chat.close', { conversation: props.thread.id }), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            closing.value = false;
            confirmClose.value = false;
        },
    });
}

function saveContact(): void {
    if (!props.thread || savingContact.value) return;
    savingContact.value = true;
    contactError.value = undefined;

    router.post(route('support.live_chat.contact', { conversation: props.thread.id }), {}, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => { contactError.value = errors.contact ?? Object.values(errors)[0]; },
        onFinish: () => { savingContact.value = false; },
    });
}

// ---- polling while the page is visible ---------------------------------------------------------------------------------

let timer: ReturnType<typeof setInterval> | null = null;
let busy = false;

function refresh(): void {
    if (busy || document.hidden) return;
    busy = true;

    router.reload({
        only: props.thread ? ['conversations', 'thread'] : ['conversations'],
        async: true,
        showProgress: false,
        onFinish: () => { busy = false; },
    });
}

function onVisibility(): void {
    if (!document.hidden) refresh();
}

onMounted(() => {
    timer = setInterval(refresh, POLL_INTERVAL);
    document.addEventListener('visibilitychange', onVisibility);
    scrollToEnd();
});

onBeforeUnmount(() => {
    if (timer !== null) clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.liveChat.title')" :description="t('support.liveChat.description')">
        <template v-if="canManageSettings" #actions>
            <Button :href="route('support.live_chat.settings')">
                <template #icon><Settings class="size-4" aria-hidden="true" /></template>
                {{ t('support.liveChat.widgetSettings') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="liveChat" />

    <div v-if="!enabled" class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-card border border-warn/40 bg-warn-soft px-4 py-3 text-sm text-ink" role="status">
        <p class="min-w-0">{{ canManageSettings ? t('support.liveChat.off') : t('support.liveChat.offNoAccess') }}</p>
        <Button v-if="canManageSettings" size="sm" :href="route('support.live_chat.settings')">{{ t('support.liveChat.setUp') }}</Button>
    </div>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div :class="thread ? 'hidden lg:block' : ''">
            <Card flush :aria-label="t('support.liveChat.list')">
                <form class="grid gap-2 border-b border-line p-3" role="search" @submit.prevent="applyFilters">
                    <Select v-model="state" :options="stateOptions" :label="t('support.liveChat.state')" />
                    <div class="flex items-end gap-2">
                        <Input v-model="search" class="min-w-0 flex-1" type="search" maxlength="100" :label="t('support.liveChat.search')" :placeholder="t('support.liveChat.searchPlaceholder')" />
                        <Button type="submit" icon :aria-label="t('support.liveChat.search')">
                            <template #icon><Search class="size-4" aria-hidden="true" /></template>
                        </Button>
                    </div>
                </form>

                <EmptyState v-if="conversations.items.length === 0" :title="t('support.liveChat.empty')" :description="t('support.liveChat.emptyHint')" compact>
                    <template #icon><MessageCircle /></template>
                </EmptyState>
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in conversations.items" :key="row.id">
                        <Link
                            :href="openUrl(row.id)"
                            preserve-state
                            preserve-scroll
                            :only="['thread', 'conversations', 'filters']"
                            :aria-current="row.id === filters.open ? 'true' : undefined"
                            :class="['block px-4 py-3 hover:bg-hover focus-visible:bg-hover', row.id === filters.open ? 'bg-brand-soft' : '']"
                        >
                            <div class="flex items-baseline justify-between gap-2">
                                <span :class="['min-w-0 truncate text-sm text-ink', row.unread > 0 ? 'font-semibold' : 'font-medium']">{{ row.title }}</span>
                                <time v-if="row.lastMessageAt" class="tabular shrink-0 text-xs text-ink-subtle" :datetime="row.lastMessageAt">{{ format.relative(row.lastMessageAt) }}</time>
                            </div>
                            <p v-if="row.preview" class="mt-0.5 line-clamp-2 text-[0.8125rem] wrap-anywhere text-ink-muted">{{ row.preview }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <Badge v-if="row.unread > 0" tone="brand">{{ t('support.liveChat.unread', { count: format.number(row.unread) }) }}</Badge>
                                <Badge v-if="row.status === 'closed'" tone="neutral">{{ t('support.liveChat.status.closed') }}</Badge>
                                <span class="truncate text-xs text-ink-subtle">{{ row.assignee ? t('support.liveChat.assignedTo', { name: row.assignee }) : t('support.liveChat.unassigned') }}</span>
                            </div>
                        </Link>
                    </li>
                </ul>

                <template v-if="conversations.previous || conversations.next" #footer>
                    <div class="flex justify-between gap-2">
                        <Button v-if="conversations.previous" size="sm" variant="ghost" @click="page(conversations.previous ?? '')">
                            <template #icon><ChevronLeft class="size-4" aria-hidden="true" /></template>
                            {{ t('support.liveChat.newer') }}
                        </Button>
                        <span v-else />
                        <Button v-if="conversations.next" size="sm" variant="ghost" @click="page(conversations.next ?? '')">
                            {{ t('support.liveChat.older') }}
                            <ChevronRight class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                </template>
            </Card>
        </div>

        <Card v-if="thread" flush :aria-labelledby="`chat-${thread.id}`" data-testid="live-chat-thread">
            <template #header>
                <div class="flex min-w-0 items-start gap-2">
                    <Button class="lg:hidden" variant="ghost" size="sm" icon :aria-label="t('support.liveChat.thread.back')" :href="route('support.live_chat.index', query({ open: null }))">
                        <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                    </Button>
                    <div class="min-w-0">
                        <h2 :id="`chat-${thread.id}`" class="truncate text-[0.9375rem] font-semibold tracking-tight text-ink">{{ thread.title }}</h2>
                        <p v-if="subtitle" class="mt-0.5 truncate text-[0.8125rem] text-ink-subtle">{{ subtitle }}</p>
                        <p class="mt-0.5 text-xs text-ink-subtle">
                            {{ t('support.liveChat.thread.started', { time: format.date(thread.startedAt, 'datetime') }) }}
                            · {{ thread.assignee ? t('support.liveChat.assignedTo', { name: thread.assignee }) : t('support.liveChat.unassigned') }}
                        </p>
                    </div>
                </div>
            </template>
            <template #actions>
                <Badge :tone="thread.status === 'open' ? 'ok' : 'neutral'">{{ t(`support.liveChat.status.${thread.status}`) }}</Badge>
                <Button v-if="thread.canClose" size="sm" @click="confirmClose = true">
                    <template #icon><X class="size-4" aria-hidden="true" /></template>
                    {{ t('support.liveChat.thread.close') }}
                </Button>
            </template>

            <div
                v-if="thread.contact || thread.ticket || thread.canSaveContact || thread.canCreateTicket || thread.ticketNeedsContact"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-4 py-2.5 text-sm"
                data-testid="live-chat-links"
            >
                <p v-if="thread.contact" class="min-w-0 text-ink-muted">
                    {{ t('support.liveChat.links.contact') }}
                    <Link v-if="thread.contact.search" :href="route('core.contacts.index', { search: thread.contact.search })" class="ms-1 font-medium text-brand-text underline">{{ thread.contact.name }}</Link>
                    <span v-else class="ms-1 text-ink-subtle">{{ t('support.liveChat.links.contactHidden') }}</span>
                </p>
                <Button v-else-if="thread.canSaveContact" size="sm" :loading="savingContact" @click="saveContact">
                    <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                    {{ t('support.liveChat.links.saveContact') }}
                </Button>
                <p v-if="thread.ticket" class="min-w-0 text-ink-muted">
                    {{ t('support.liveChat.links.ticket') }}
                    <Link v-if="thread.ticket.canOpen" :href="route('support.tickets.show', { ticket: thread.ticket.id })" class="ms-1 font-medium text-brand-text underline">{{ thread.ticket.number }}</Link>
                    <span v-else class="ms-1 font-medium text-ink">{{ thread.ticket.number }}</span>
                </p>
                <Button v-else-if="thread.canCreateTicket" size="sm" @click="ticketOpen = true">
                    <template #icon><LifeBuoy class="size-4" aria-hidden="true" /></template>
                    {{ t('support.liveChat.links.createTicket') }}
                </Button>
                <p v-else-if="thread.ticketNeedsContact" class="text-ink-subtle">{{ t('support.liveChat.links.ticketNeedsContact') }}</p>
                <p v-if="contactError" class="w-full text-danger" role="alert">{{ contactError }}</p>
            </div>

            <div ref="scroller" class="max-h-[min(60vh,40rem)] min-h-48 overflow-y-auto border-t border-line px-4 py-3">
                <div v-if="thread.hasOlder && thread.limit < MESSAGES_MAX" class="mb-3 flex justify-center">
                    <Button size="sm" variant="ghost" @click="older">
                        <template #icon><History class="size-4" aria-hidden="true" /></template>
                        {{ t('support.liveChat.thread.olderMessages') }}
                    </Button>
                </div>
                <ol class="grid gap-2.5" role="log" :aria-label="t('support.liveChat.thread.label')">
                    <li v-for="message in thread.messages" :key="message.id" :class="['flex', message.direction === 'in' ? 'justify-start' : 'justify-end']">
                        <div :class="['max-w-[85%] min-w-0 rounded-card px-3.5 py-2.5 text-sm text-ink', message.direction === 'in' ? 'glass border border-line' : 'bg-brand-soft']">
                            <p class="eyebrow mb-1 truncate">{{ message.author ?? t('support.liveChat.thread.visitor') }}</p>
                            <p class="whitespace-pre-wrap wrap-anywhere">{{ message.text }}</p>
                            <time class="tabular mt-1 block text-xs text-ink-subtle" :datetime="message.at">{{ format.date(message.at, 'datetime') }}</time>
                        </div>
                    </li>
                </ol>
            </div>

            <div class="border-t border-line p-3">
                <p v-if="!thread.mayReply" class="text-sm text-ink-muted">{{ t('support.liveChat.composer.readOnly') }}</p>
                <p v-else-if="!thread.canReply" class="text-sm text-ink-muted" role="status">{{ thread.replyBlockedReason ?? t('support.liveChat.thread.closedNote') }}</p>
                <form v-else class="grid gap-2" @submit.prevent="send">
                    <Textarea
                        v-model="text"
                        :label="t('support.liveChat.composer.label')"
                        :placeholder="t('support.liveChat.composer.placeholder')"
                        :hint="t('support.liveChat.composer.shortcut')"
                        :error="error"
                        :maxlength="MAX_TEXT"
                        :rows="3"
                        @keydown="onKeydown"
                    />
                    <div class="flex justify-end">
                        <Button type="submit" variant="primary" :loading="sending" :disabled="!canSend">
                            <template #icon><Send class="size-4" aria-hidden="true" /></template>
                            {{ t('support.liveChat.composer.send') }}
                        </Button>
                    </div>
                </form>
            </div>
        </Card>

        <div v-else class="hidden lg:block">
            <Card>
                <EmptyState :title="t('support.liveChat.pick')" :description="t('support.liveChat.pickHint')">
                    <template #icon><MessageCircle /></template>
                </EmptyState>
            </Card>
        </div>
    </div>

    <ChatTicketDialog
        v-if="thread && thread.ticketForm"
        v-model:open="ticketOpen"
        :conversation-id="thread.id"
        :subject="thread.ticketForm.subject"
        :priorities="thread.ticketForm.priorities"
        :queues="thread.ticketForm.queues"
        :can-close="thread.canClose"
    />

    <ConfirmDialog
        v-model:open="confirmClose"
        :title="t('support.liveChat.thread.close')"
        :description="t('support.liveChat.thread.closeConfirm')"
        :confirm-label="t('support.liveChat.thread.close')"
        tone="default"
        :loading="closing"
        @confirm="close"
    />
</template>
