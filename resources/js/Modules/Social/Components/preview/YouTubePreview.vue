<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAuthor from './PreviewAuthor.vue';
import PreviewCaption from './PreviewCaption.vue';
import PreviewMedia from './PreviewMedia.vue';
import { FOLD_AT, titleFromText } from './postPreview';
import type { AccountOption, MediaRow } from '../types';

/**
 * A video page: the 16:9 frame, the title taken from the first line of the text (100 characters; the post's
 * own title is internal and never published), the channel, and the rest of the text as the description.
 */
const props = defineProps<{
    account: AccountOption;
    text: string;
    media: MediaRow[];
}>();

const { t } = useI18n();
const parts = computed(() => titleFromText(props.text, 100));
</script>

<template>
    <article class="overflow-hidden rounded-card border border-line bg-control" data-preview="youtube">
        <PreviewMedia :media="media" shape="wide" />
        <div class="grid gap-2.5 px-4 pt-3 pb-4">
            <h4 v-if="parts.title !== ''" class="text-base leading-snug font-semibold break-words text-ink" data-testid="preview-title">{{ parts.title }}</h4>
            <p v-else class="text-sm text-ink-subtle" data-testid="preview-title-missing">{{ t('social.preview.titleFromFirstLine') }}</p>
            <p v-if="parts.titleCut" class="text-xs text-warn" data-testid="preview-title-cut">{{ t('social.preview.titleCut') }}</p>
            <PreviewAuthor :account="account" size="sm" />
            <div v-if="parts.description !== ''" class="rounded-control glass-well px-3 py-2">
                <PreviewCaption :text="parts.description" :fold-at="FOLD_AT.youtube" more-label="more" />
            </div>
        </div>
    </article>
</template>
