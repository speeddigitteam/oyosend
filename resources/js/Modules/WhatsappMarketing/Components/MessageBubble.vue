<script setup lang="ts">
import { Check, CheckCheck, Clock, FileText, Image as ImageIcon, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import type { MessageRow } from './types';
import { useWhatsappFormat } from './useWhatsappFormat';

/**
 * One message in a thread. The body is customer-controlled text: it is only ever rendered as an interpolated
 * string inside a pre-wrap element. Attachments are metadata; the file itself is never fetched.
 */
const props = defineProps<{ message: MessageRow }>();

const { t } = useI18n();
const format = useFormat();
const { reason } = useWhatsappFormat();

const inbound = computed(() => props.message.direction === 'inbound');
const failed = computed(() => props.message.status === 'failed' || props.message.status === 'blocked');
const sender = computed(() => {
    if (props.message.senderType === 'agent' && props.message.author) {
        return props.message.author;
    }

    return t(`whatsapp.sender.${props.message.senderType}`);
});
const when = computed(() => format.date(props.message.occurredAt, 'datetime'));
const attachmentKind = (kind: string | undefined): string => t(`whatsapp.attachment.${kind ?? 'unknown'}`);
const hasCaption = computed(() => props.message.body !== null && props.message.body !== '');
const status = computed(() => (inbound.value ? null : props.message.status));
</script>

<template>
    <li :class="['flex', inbound ? 'justify-start' : 'justify-end']" :data-direction="message.direction" :data-sender="message.senderType">
        <div
            :class="[
                'max-w-[85%] rounded-card px-3.5 py-2.5 text-sm',
                inbound ? 'glass border border-line text-ink' : failed ? 'border border-danger/40 bg-danger-soft text-ink' : 'bg-brand-soft text-ink',
            ]"
        >
            <p class="eyebrow mb-1">{{ sender }}</p>

            <p v-if="message.templateName" class="mb-1 text-xs text-ink-subtle">
                {{ t('whatsapp.thread.template', { name: message.templateName }) }}
            </p>

            <ul v-if="message.attachments.length" class="mb-1 grid gap-1">
                <li
                    v-for="(attachment, index) in message.attachments"
                    :key="index"
                    class="flex items-center gap-2 text-xs text-ink-muted"
                    data-testid="attachment"
                >
                    <ImageIcon v-if="attachment.kind === 'image' || attachment.kind === 'sticker'" class="size-4" aria-hidden="true" />
                    <FileText v-else class="size-4" aria-hidden="true" />
                    <span>{{ t('whatsapp.thread.attachment', { kind: attachmentKind(attachment.kind) }) }}</span>
                </li>
            </ul>

            <p v-if="hasCaption" class="whitespace-pre-wrap wrap-anywhere">{{ message.body }}</p>
            <p v-else-if="message.attachments.length === 0" class="text-ink-subtle">{{ t('whatsapp.thread.unsupported') }}</p>

            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-subtle">
                <time class="tabular">{{ when }}</time>
                <span v-if="status" class="inline-flex items-center gap-1" :class="failed ? 'text-danger' : ''" data-testid="delivery-status">
                    <Clock v-if="status === 'queued'" class="size-3.5" aria-hidden="true" />
                    <Check v-else-if="status === 'sent'" class="size-3.5" aria-hidden="true" />
                    <CheckCheck v-else-if="status === 'delivered' || status === 'read'" class="size-3.5" aria-hidden="true" />
                    <TriangleAlert v-else class="size-3.5" aria-hidden="true" />
                    {{ t(`whatsapp.messageStatus.${status}`) }}
                </span>
            </p>
            <p v-if="failed && message.failureCode" class="mt-1 text-xs text-danger" data-testid="failure-reason">
                {{ reason(message.failureCode) }}
            </p>
        </div>
    </li>
</template>
