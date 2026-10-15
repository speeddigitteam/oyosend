<script setup lang="ts">
import { ChartNoAxesColumn, Heart, Link2, MessageCircle, Repeat2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { lengthState } from '../socialFormat';
import PreviewAuthor from './PreviewAuthor.vue';
import PreviewMedia from './PreviewMedia.vue';
import { countedText } from './postPreview';
import type { AccountOption, MediaRow } from '../types';

/**
 * A short-text post: the author on one line, the text with any part past the platform's limit marked, the
 * count against that limit, the media or the link's domain, and the reaction icons.
 */
const props = defineProps<{
    account: AccountOption;
    text: string;
    media: MediaRow[];
    domain: string | null;
}>();

const { t } = useI18n();
const format = useFormat();

const counted = computed(() => countedText(props.text, props.account.capabilities.maxText ?? 0));
const tone = computed(() => {
    const state = lengthState(counted.value.length, counted.value.limit);

    return state === 'over' ? 'text-danger' : state === 'near' ? 'text-warn' : 'text-ink-subtle';
});
</script>

<template>
    <article class="grid gap-2.5 rounded-card border border-line bg-control px-4 py-3.5" data-preview="x">
        <PreviewAuthor :account="account" inline size="sm" />
        <p v-if="text !== ''" class="text-sm leading-6 break-words whitespace-pre-wrap" data-testid="preview-text">
            {{ counted.within }}<mark v-if="counted.over !== ''" class="rounded-sm bg-danger-soft text-danger" data-testid="preview-over">{{ counted.over }}</mark>
        </p>
        <PreviewMedia v-if="media.length > 0" :media="media" shape="grid" class="overflow-hidden rounded-control" />
        <div v-else-if="domain" class="flex items-center gap-2 rounded-control glass-well px-3 py-2" data-testid="preview-link">
            <Link2 class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            <span class="truncate text-xs text-ink-muted">{{ domain }}</span>
        </div>
        <div class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-5 text-ink-subtle" aria-hidden="true">
                <MessageCircle class="size-4" />
                <Repeat2 class="size-4" />
                <Heart class="size-4" />
                <ChartNoAxesColumn class="size-4" />
            </span>
            <span :class="['tabular text-xs', tone]" data-testid="preview-count">
                <template v-if="counted.limit > 0">{{ t('social.form.length', { count: format.number(counted.length), max: format.number(counted.limit) }) }}</template>
                <template v-else>{{ t('social.form.lengthNoLimit', { count: format.number(counted.length) }) }}</template>
            </span>
        </div>
        <p v-if="counted.over !== ''" class="text-xs text-danger" data-testid="preview-over-note">
            {{ t('social.preview.overLimit', { count: format.number(counted.length - counted.limit) }) }}
        </p>
    </article>
</template>
