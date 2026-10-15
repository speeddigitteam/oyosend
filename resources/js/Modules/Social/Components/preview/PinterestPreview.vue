<script setup lang="ts">
import { computed } from 'vue';
import PreviewAuthor from './PreviewAuthor.vue';
import PreviewMedia from './PreviewMedia.vue';
import { titleFromText } from './postPreview';
import type { AccountOption, MediaRow } from '../types';

/**
 * A pin: the first photo or video at its own proportions, a title from the first line of the text, the rest
 * as the description, the link's domain and the board (the connected account).
 */
const props = defineProps<{
    account: AccountOption;
    text: string;
    media: MediaRow[];
    domain: string | null;
}>();

const parts = computed(() => titleFromText(props.text, 100));
</script>

<template>
    <article class="mx-auto grid w-full max-w-[18rem] gap-2.5" data-preview="pinterest">
        <PreviewMedia :media="media" shape="pin" class="overflow-hidden rounded-card border border-line" />
        <div class="grid gap-1.5 px-1">
            <h4 v-if="parts.title !== ''" class="text-sm leading-snug font-semibold break-words text-ink" data-testid="preview-title">{{ parts.title }}</h4>
            <p v-if="parts.description !== ''" class="line-clamp-4 text-[0.8125rem] break-words whitespace-pre-wrap text-ink-muted" data-testid="preview-text">{{ parts.description }}</p>
            <p v-if="domain" class="truncate text-xs text-ink-subtle" data-testid="preview-link">{{ domain }}</p>
            <PreviewAuthor :account="account" size="sm" timeless />
        </div>
    </article>
</template>
