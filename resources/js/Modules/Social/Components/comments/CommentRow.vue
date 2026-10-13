<script setup lang="ts">
import { MessageCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Badge from '@/Core/Components/Badge.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { AudienceComment } from './commentTypes';

/** One line of the inbox list. The text is shown as plain text only. */
defineProps<{
    comment: AudienceComment;
    active: boolean;
    selectable: boolean;
}>();

defineEmits<{ open: [] }>();

const selected = defineModel<boolean>('selected', { default: false });
const { t } = useI18n();
const format = useFormat();

function initialsOf(name: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);

    return parts.length === 0 ? '?' : parts.slice(0, 2).map((part) => Array.from(part)[0]).join('').toUpperCase();
}
</script>

<template>
    <li
        class="flex items-start gap-2 border-b border-line px-3 py-3 last:border-b-0"
        :class="active ? 'bg-hover' : ''"
        :data-testid="`comment-row-${comment.id}`"
    >
        <Checkbox v-if="selectable" v-model="selected" class="mt-1 shrink-0"><span class="sr-only">{{ t('socialComments.list.select') }}</span></Checkbox>
        <button
            type="button"
            class="flex min-w-0 flex-1 items-start gap-3 rounded-control text-start focus-visible:outline-2 focus-visible:outline-brand"
            :aria-current="active ? 'true' : undefined"
            @click="$emit('open')"
        >
            <Avatar :initials="initialsOf(comment.authorName)" :name="comment.authorName ?? undefined" class="mt-0.5 shrink-0" />
            <span class="min-w-0 flex-1">
                <span class="flex items-baseline justify-between gap-2">
                    <span class="truncate text-sm" :class="comment.unread ? 'font-semibold text-ink' : 'font-medium text-ink'">
                        {{ comment.authorName ?? t('socialComments.list.unknownAuthor') }}
                    </span>
                    <span class="tabular shrink-0 text-xs text-ink-subtle">{{ format.relative(comment.commentedAt) }}</span>
                </span>
                <span class="mt-0.5 line-clamp-2 break-words text-[0.8125rem] whitespace-pre-line text-ink-muted">
                    {{ comment.message ?? t('socialComments.list.noText') }}
                </span>
                <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <Badge v-if="comment.unread" tone="brand" dot>{{ t('socialComments.status.unread') }}</Badge>
                    <Badge v-if="comment.needsAttention" tone="warn">{{ t('socialComments.status.needs_attention') }}</Badge>
                    <Badge v-if="comment.replied" tone="ok">{{ t('socialComments.status.replied') }}</Badge>
                    <Badge v-if="comment.hidden" tone="neutral">{{ t('socialComments.status.hidden') }}</Badge>
                    <Badge v-if="comment.deleted" tone="danger">{{ t('socialComments.status.deleted') }}</Badge>
                    <Badge v-else-if="comment.handled" tone="neutral">{{ t('socialComments.status.handled') }}</Badge>
                    <span v-if="comment.isReply" class="inline-flex items-center gap-1 text-xs text-ink-subtle">
                        <MessageCircle class="size-3" aria-hidden="true" />{{ t('socialComments.list.inThread') }}
                    </span>
                    <span v-if="comment.assignee" class="text-xs text-ink-subtle">{{ t('socialComments.list.assignedTo', { name: comment.assignee.name }) }}</span>
                    <Badge v-for="tag in comment.tags" :key="tag" tone="info">{{ tag }}</Badge>
                </span>
            </span>
        </button>
    </li>
</template>
