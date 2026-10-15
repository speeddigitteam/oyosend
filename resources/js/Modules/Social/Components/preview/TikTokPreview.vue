<script setup lang="ts">
import { Heart, MessageCircle, Share2 } from 'lucide-vue-next';
import PreviewAuthor from './PreviewAuthor.vue';
import PreviewCaption from './PreviewCaption.vue';
import PreviewMedia from './PreviewMedia.vue';
import { FOLD_AT } from './postPreview';
import type { AccountOption, MediaRow } from '../types';

/**
 * A vertical video: the 9:16 frame with the reaction icons on its edge, and under it the account and the
 * folded caption (kept outside the frame so it stays readable whatever the video shows).
 */
defineProps<{
    account: AccountOption;
    text: string;
    media: MediaRow[];
}>();
</script>

<template>
    <article class="mx-auto grid w-full max-w-[17rem] gap-3" data-preview="tiktok">
        <div class="relative overflow-hidden rounded-card border border-line">
            <PreviewMedia :media="media" shape="portrait" />
            <span class="absolute right-2 bottom-3 grid gap-2.5" aria-hidden="true">
                <span v-for="(icon, index) in [Heart, MessageCircle, Share2]" :key="index" class="flex size-9 items-center justify-center rounded-full bg-ink/70 text-canvas">
                    <component :is="icon" class="size-4" />
                </span>
            </span>
        </div>
        <div class="grid gap-1.5">
            <PreviewAuthor :account="account" size="sm" />
            <PreviewCaption :text="text" :fold-at="FOLD_AT.tiktok" more-label="more" />
        </div>
    </article>
</template>
