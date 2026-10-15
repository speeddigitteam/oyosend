<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, RotateCcw, Send } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { ConversationThread, MessagesCan } from './messageTypes';

/**
 * One direct-message conversation and its composer. Every message is interpolated (escaped) text, never HTML; an
 * attachment is a label only. The composer is shown only to members who may answer, and is disabled with the server's
 * reason outside the 24-hour window. The server decides again on every reply.
 */
const props = defineProps<{
    thread: ConversationThread;
    can: MessagesCan;
    members: { id: string; name: string }[];
}>();

defineEmits<{ back: [] }>();

const { t } = useI18n();
const format = useFormat();

const reply = useForm({ text: '' });
const tooLong = computed(() => reply.text.length > props.thread.textMax);

const assignOptions = computed(() => [
    { value: '', label: t('social.messages.thread.unassigned') },
    ...props.members.map((member) => ({ value: member.id, label: member.name })),
]);
const assignee = computed({
    get: () => props.thread.owner?.id ?? '',
    set: (value: string | number | null | undefined) => {
        router.put(route('social.messages.assign', { conversation: props.thread.id }), { membership_id: value === '' || value == null ? null : String(value) }, { preserveScroll: true });
    },
});

function submit(): void {
    reply.post(route('social.messages.reply', { conversation: props.thread.id }), {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });
}

function setStatus(status: 'open' | 'closed'): void {
    router.put(route('social.messages.status', { conversation: props.thread.id }), { status }, { preserveScroll: true });
}

function onKeydown(event: KeyboardEvent): void {
    // Ctrl/Cmd + Enter sends; Enter alone makes a new line.
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && props.thread.canReply && reply.text.trim() !== '' && !tooLong.value) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <Card flush class="min-w-0" data-testid="message-thread">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
            <Button size="sm" variant="ghost" class="lg:hidden" @click="$emit('back')">
                <template #icon><ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" /></template>
                {{ t('social.messages.thread.back') }}
            </Button>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ thread.title }}</p>
                <p class="truncate text-xs text-ink-subtle">
                    {{ t(`social.messages.platforms.${thread.platform}`) }} · {{ t('social.messages.thread.onPage', { page: thread.accountName }) }}
                </p>
            </div>
            <Badge :tone="thread.status === 'closed' ? 'neutral' : 'brand'">{{ t(`social.messages.status.${thread.status}`) }}</Badge>
        </div>

        <p v-if="thread.hasOlder" class="px-4 pt-3 text-xs text-ink-subtle">{{ t('social.messages.thread.olderHidden') }}</p>

        <ol class="grid gap-3 px-4 py-4" :aria-label="t('social.messages.thread.conversation')">
            <li
                v-for="item in thread.messages"
                :key="item.id"
                class="max-w-[85%] rounded-card border border-line px-3 py-2"
                :class="item.direction === 'out' ? 'ms-auto bg-brand-soft' : 'bg-control'"
                :data-testid="`message-${item.id}`"
            >
                <p class="flex flex-wrap items-baseline gap-x-2 text-xs text-ink-subtle">
                    <span class="font-medium text-ink">{{ item.direction === 'out' ? (item.authorName ?? thread.accountName) : thread.title }}</span>
                    <span class="tabular">{{ format.date(item.at, 'datetime') }}</span>
                    <span v-if="item.direction === 'out' && item.status !== 'received'">{{ t(`social.messages.thread.statuses.${item.status}`) }}</span>
                </p>
                <p v-if="item.status === 'deleted'" class="mt-1 text-sm text-ink-muted italic">{{ t('social.messages.thread.statuses.deleted') }}</p>
                <template v-else>
                    <p v-if="item.text !== null" class="mt-1 break-words whitespace-pre-line text-sm text-ink">{{ item.text }}</p>
                    <p v-if="item.attachment !== null" class="mt-1 text-xs text-ink-subtle">{{ t('social.messages.thread.attachment', { type: item.attachment }) }}</p>
                    <p v-if="item.text === null && item.attachment === null" class="mt-1 text-sm text-ink-muted">{{ t('social.messages.thread.noText') }}</p>
                </template>
            </li>
        </ol>

        <div v-if="can.reply && thread.replyable" class="grid gap-3 border-t border-line px-4 py-4">
            <div class="flex flex-wrap items-end gap-2">
                <Button v-if="thread.status === 'open'" size="sm" @click="setStatus('closed')">
                    <template #icon><Check class="size-4" aria-hidden="true" /></template>
                    {{ t('social.messages.thread.markDone') }}
                </Button>
                <Button v-else size="sm" @click="setStatus('open')">
                    <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                    {{ t('social.messages.thread.reopen') }}
                </Button>
                <Select v-model="assignee" :options="assignOptions" :label="t('social.messages.thread.assignTo')" class="w-48" />
            </div>

            <p v-if="thread.blockedReason" role="status" class="rounded-control bg-warn-soft px-3 py-2 text-[0.8125rem] text-warn" data-testid="reply-blocked">
                {{ thread.blockedReason }}
            </p>
            <p v-else-if="thread.windowEndsAt" class="text-xs text-ink-subtle">{{ t('social.messages.thread.windowOpen', { time: format.date(thread.windowEndsAt, 'datetime') }) }}</p>

            <form class="grid gap-2" @submit.prevent="submit">
                <Textarea
                    v-model="reply.text"
                    :label="t('social.messages.thread.replyLabel')"
                    :error="reply.errors.text"
                    :rows="3"
                    :maxlength="thread.textMax"
                    :disabled="!thread.canReply"
                    :hint="t('social.messages.thread.replyHint', { count: format.number(reply.text.length), max: format.number(thread.textMax) })"
                    @keydown="onKeydown"
                />
                <div>
                    <Button type="submit" variant="primary" size="sm" :loading="reply.processing" :disabled="!thread.canReply || reply.text.trim() === '' || tooLong">
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('social.messages.thread.send') }}
                    </Button>
                </div>
            </form>
            <p class="text-xs text-ink-subtle">{{ t('social.messages.thread.tagsLater') }}</p>
        </div>
        <p v-else class="border-t border-line px-4 py-4 text-sm text-ink-muted">{{ t('social.messages.thread.noReply') }}</p>
    </Card>
</template>
