<script setup lang="ts">
import { Film, Play } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { clockParts } from '../preflight';
import type { MediaRow } from '../types';

/**
 * The media of a previewed post. `grid` lays out up to four files like a feed does (the last tile counts the
 * rest); the other shapes show the first file, framed the way the platform frames it, with a carousel marker
 * when there are more. A video has no poster in the library, so it is a quiet frame with a play mark.
 */
const props = defineProps<{
    media: MediaRow[];
    shape: 'grid' | 'square' | 'wide' | 'portrait' | 'pin';
}>();

const { t } = useI18n();
const format = useFormat();

const first = computed(() => props.media[0] ?? null);
const tiles = computed(() => props.media.slice(0, 4));
const hidden = computed(() => Math.max(0, props.media.length - 4));

const FRAMES: Record<typeof props.shape, string> = {
    grid: '',
    square: 'aspect-square',
    wide: 'aspect-video',
    portrait: 'aspect-9/16',
    pin: '',
};

function clock(ms: number | null | undefined): string | null {
    if (!ms) {
        return null;
    }

    const { minutes, seconds } = clockParts(ms);

    return `${format.number(minutes)}:${seconds < 10 ? format.number(0) : ''}${format.number(seconds)}`;
}

function gridClass(index: number): string {
    const count = tiles.value.length;

    if (count === 1) {
        return 'col-span-2 aspect-4/3';
    }

    if (count === 3 && index === 0) {
        return 'col-span-2 aspect-2/1';
    }

    return 'aspect-square';
}
</script>

<template>
    <div v-if="media.length > 0" data-testid="preview-media" :data-count="media.length">
        <ul v-if="shape === 'grid'" class="grid grid-cols-2 gap-0.5 overflow-hidden">
            <li v-for="(item, index) in tiles" :key="item.id" :class="['relative overflow-hidden bg-hover', gridClass(index)]">
                <img v-if="item.kind === 'image'" :src="item.url" :alt="item.altText ?? item.name" loading="lazy" class="size-full object-cover" />
                <div v-else class="flex size-full items-center justify-center">
                    <Film class="size-6 text-ink-subtle" aria-hidden="true" />
                    <span class="absolute inset-0 m-auto flex size-10 items-center justify-center rounded-full bg-ink/70 text-canvas">
                        <Play class="size-4" aria-hidden="true" />
                    </span>
                    <span class="sr-only">{{ t('social.preview.video') }}</span>
                    <span v-if="clock(item.durationMs)" class="tabular absolute right-1.5 bottom-1.5 rounded-chip bg-ink/70 px-1.5 text-[0.6875rem] text-canvas">
                        {{ clock(item.durationMs) }}
                    </span>
                </div>
                <span
                    v-if="index === tiles.length - 1 && hidden > 0"
                    class="absolute inset-0 flex items-center justify-center bg-ink/60 text-xl font-semibold text-canvas"
                    data-testid="preview-more-media"
                >
                    {{ t('social.preview.moreMedia', { count: format.number(hidden) }) }}
                </span>
            </li>
        </ul>

        <div v-else-if="first" :class="['relative overflow-hidden bg-hover', FRAMES[shape]]">
            <img
                v-if="first.kind === 'image'"
                :src="first.url"
                :alt="first.altText ?? first.name"
                loading="lazy"
                :class="shape === 'pin' ? 'h-auto w-full' : 'size-full object-cover'"
            />
            <div v-else :class="['flex items-center justify-center', shape === 'pin' ? 'aspect-2/3' : 'size-full']">
                <Film class="size-7 text-ink-subtle" aria-hidden="true" />
                <span class="absolute inset-0 m-auto flex size-12 items-center justify-center rounded-full bg-ink/70 text-canvas">
                    <Play class="size-5" aria-hidden="true" />
                </span>
                <span class="sr-only">{{ t('social.preview.video') }}</span>
                <span v-if="clock(first.durationMs)" class="tabular absolute right-2 bottom-2 rounded-chip bg-ink/70 px-1.5 text-xs text-canvas">
                    {{ clock(first.durationMs) }}
                </span>
            </div>
            <span
                v-if="media.length > 1"
                class="tabular absolute top-2 right-2 rounded-chip bg-ink/70 px-2 py-0.5 text-xs text-canvas"
                data-testid="preview-carousel"
            >
                {{ t('social.preview.position', { index: format.number(1), count: format.number(media.length) }) }}
            </span>
        </div>
        <div v-if="shape !== 'grid' && media.length > 1" class="mt-2 flex justify-center gap-1" aria-hidden="true">
            <span v-for="(item, index) in media" :key="item.id" :class="['size-1.5 rounded-full', index === 0 ? 'bg-brand' : 'bg-line-strong']" />
        </div>
    </div>
</template>
