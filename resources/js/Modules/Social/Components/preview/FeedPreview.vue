<script setup lang="ts">
import { Link2, MessageCircle, Repeat2, Send, Share2, ThumbsUp } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAuthor from './PreviewAuthor.vue';
import PreviewCaption from './PreviewCaption.vue';
import PreviewMedia from './PreviewMedia.vue';
import { FOLD_AT } from './postPreview';
import type { AccountOption, MediaRow } from '../types';

/**
 * A feed card (Facebook, LinkedIn, and any platform without a layout of its own): the author, the folded
 * text, the media or the link's domain, and the reaction row. The reaction row is a picture, not controls.
 */
const props = defineProps<{
    layout: 'facebook' | 'linkedin' | 'feed';
    account: AccountOption;
    text: string;
    media: MediaRow[];
    domain: string | null;
}>();

const { t } = useI18n();

const reactions = computed<{ key: string; icon: Component }[]>(() => (props.layout === 'linkedin'
    ? [
        { key: 'like', icon: ThumbsUp },
        { key: 'comment', icon: MessageCircle },
        { key: 'repost', icon: Repeat2 },
        { key: 'send', icon: Send },
    ]
    : [
        { key: 'like', icon: ThumbsUp },
        { key: 'comment', icon: MessageCircle },
        { key: 'share', icon: Share2 },
    ]));
</script>

<template>
    <article class="overflow-hidden rounded-card border border-line bg-control" :data-preview="layout">
        <div class="grid gap-3 px-4 pt-4 pb-3">
            <PreviewAuthor :account="account" />
            <PreviewCaption :text="text" :fold-at="FOLD_AT[layout]" />
        </div>

        <PreviewMedia v-if="media.length > 0" :media="media" shape="grid" />
        <div v-else-if="domain" class="mx-4 mb-3 flex items-center gap-2 rounded-control glass-well px-3 py-2.5" data-testid="preview-link">
            <Link2 class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            <span class="eyebrow truncate">{{ domain }}</span>
        </div>

        <div class="mx-4 flex justify-around border-t border-line py-1.5 text-[0.8125rem] font-medium text-ink-muted" aria-hidden="true">
            <span v-for="item in reactions" :key="item.key" class="inline-flex items-center gap-1.5 px-2 py-1">
                <component :is="item.icon" class="size-4" />
                <span class="hidden min-[400px]:inline">{{ t(`social.preview.reactions.${item.key}`) }}</span>
            </span>
        </div>
    </article>
</template>
