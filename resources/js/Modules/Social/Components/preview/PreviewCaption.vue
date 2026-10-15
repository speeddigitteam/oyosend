<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { excerpt } from './postPreview';

/**
 * A caption as a feed folds it: the first part, then a "See more" that opens the rest. Always plain text
 * (never HTML), keeping the line breaks people typed.
 */
const props = withDefaults(
    defineProps<{
        text: string;
        /** Characters shown before the fold; 0 never folds. */
        foldAt?: number;
        /** A bold name in front of the caption (Instagram, TikTok). */
        lead?: string;
        /** `more` is the short marker some apps print instead of "See more". */
        moreLabel?: 'seeMore' | 'more';
    }>(),
    { foldAt: 0, lead: undefined, moreLabel: 'seeMore' },
);

const { t } = useI18n();
const open = ref(false);
const folded = computed(() => excerpt(props.text, props.foldAt));

watch(() => props.text, () => {
    if (!folded.value.truncated) {
        open.value = false;
    }
});
</script>

<template>
    <p v-if="text !== '' || lead" class="text-sm leading-6 break-words whitespace-pre-wrap" data-testid="preview-text">
        <span v-if="lead" class="mr-1 font-semibold">{{ lead }}</span>
        <template v-if="open || !folded.truncated">{{ text }}</template>
        <template v-else>{{ folded.text }}&hellip; </template>
        <button
            v-if="folded.truncated"
            type="button"
            class="rounded-chip font-medium text-ink-muted hover:underline focus-visible:outline-2 focus-visible:outline-brand"
            :aria-expanded="open"
            data-testid="preview-more"
            @click="open = !open"
        >
            {{ open ? t('social.preview.seeLess') : t(`social.preview.${moreLabel}`) }}
        </button>
    </p>
</template>
