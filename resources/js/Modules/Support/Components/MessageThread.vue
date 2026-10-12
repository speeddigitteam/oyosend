<script setup lang="ts">
import { Download, Lock, Send, UserRound } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { MessageKind, MessageRow } from './types';
import { useSupportFormat } from './useSupportFormat';

/**
 * The conversation. Each message says what it is in words and with an icon;
 * the stripe and tint only reinforce it. Bodies are user content and are
 * rendered as text with line breaks kept, never as HTML.
 */
defineProps<{
    ticketId: string;
    messages: MessageRow[];
    /** Show a checkbox on each message (the split flow). */
    selectable?: boolean;
}>();

const selected = defineModel<string[]>('selected', { default: () => [] });

const { t } = useI18n();
const format = useFormat();
const { bytes } = useSupportFormat();

const ICONS: Record<MessageKind, typeof Lock> = { internal_note: Lock, public_reply: Send, customer_reply: UserRound };
const STRIPES: Record<MessageKind, string> = {
    internal_note: 'border-l-warn bg-warn-soft',
    public_reply: 'border-l-brand bg-brand-soft',
    customer_reply: 'border-l-line-strong bg-hover',
};

function toggle(id: string, on: boolean): void {
    selected.value = on ? [...selected.value, id] : selected.value.filter((value) => value !== id);
}

function author(message: MessageRow): string {
    return message.author.name ?? message.author.email ?? t('support.thread.unknownAuthor');
}
</script>

<template>
    <ol class="grid gap-3" :aria-label="t('support.thread.label')">
        <li v-for="message in messages" :key="message.id">
            <article :class="['rounded-card border border-line border-l-4 px-4 py-3', STRIPES[message.kind]]">
                <header class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <Checkbox
                        v-if="selectable"
                        :model-value="selected.includes(message.id)"
                        :label="t('support.thread.select', { author: author(message) })"
                        class="shrink-0"
                        @update:model-value="toggle(message.id, $event)"
                    />
                    <span class="font-medium text-ink">{{ author(message) }}</span>
                    <Badge :tone="message.kind === 'internal_note' ? 'warn' : message.kind === 'public_reply' ? 'brand' : 'neutral'">
                        <component :is="ICONS[message.kind]" class="size-3.5" aria-hidden="true" />
                        {{ t(`support.messageKind.${message.kind}`) }}
                    </Badge>
                    <time class="tabular ms-auto text-xs text-ink-subtle" :datetime="message.createdAt">{{ format.date(message.createdAt, 'datetime') }}</time>
                </header>
                <p v-if="message.kind === 'internal_note'" class="mt-1 text-xs text-ink-muted">{{ t('support.thread.internalHint') }}</p>
                <p class="mt-2 text-sm whitespace-pre-wrap wrap-anywhere text-ink">{{ message.body }}</p>
                <ul v-if="message.attachments.length" class="mt-3 flex flex-wrap gap-2" :aria-label="t('support.attachments.label')">
                    <li v-for="attachment in message.attachments" :key="attachment.id">
                        <a
                            :href="route('support.tickets.attachments.download', { ticket: ticketId, attachment: attachment.id })"
                            class="inline-flex items-center gap-1.5 rounded-control border border-line-strong bg-control px-2.5 py-1 text-xs text-ink hover:bg-control-hover"
                            download
                        >
                            <Download class="size-3.5" aria-hidden="true" />
                            <span class="wrap-anywhere">{{ attachment.name }}</span>
                            <span class="tabular text-ink-subtle">{{ bytes(attachment.size) }}</span>
                        </a>
                    </li>
                </ul>
            </article>
        </li>
    </ol>
</template>
