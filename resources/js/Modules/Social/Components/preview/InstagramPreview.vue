<script setup lang="ts">
import { Bookmark, Heart, MessageCircle, Send } from 'lucide-vue-next';
import PreviewAuthor from './PreviewAuthor.vue';
import PreviewCaption from './PreviewCaption.vue';
import PreviewMedia from './PreviewMedia.vue';
import { FOLD_AT } from './postPreview';
import type { AccountOption, MediaRow } from '../types';

/**
 * A square-media post: the account, the first photo or video framed square (a carousel marker when there are
 * more), the reaction icons, then the caption led by the account name. Links in a caption are plain text there,
 * so no link card is drawn.
 */
defineProps<{
    account: AccountOption;
    text: string;
    media: MediaRow[];
}>();
</script>

<template>
    <article class="overflow-hidden rounded-card border border-line bg-control" data-preview="instagram">
        <div class="px-3 py-2.5">
            <PreviewAuthor :account="account" size="sm" />
        </div>
        <PreviewMedia :media="media" shape="square" />
        <div class="grid gap-2 px-3 pt-2.5 pb-3.5">
            <div class="flex items-center gap-3.5 text-ink" aria-hidden="true">
                <Heart class="size-5" />
                <MessageCircle class="size-5" />
                <Send class="size-5" />
                <Bookmark class="ml-auto size-5" />
            </div>
            <PreviewCaption :text="text" :fold-at="FOLD_AT.instagram" :lead="account.handle ?? account.name" more-label="more" />
        </div>
    </article>
</template>
