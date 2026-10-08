<script setup lang="ts">
import { useId } from 'vue';

/**
 * The Oyosend mark: two overlapping discs, the larger in the brand colour
 * and the smaller in the accent, with the overlap picked out - the same
 * lens-behind-glass idea the interface is built on.
 */
withDefaults(
    defineProps<{
        /** Show the wordmark next to the mark. */
        wordmark?: boolean;
        size?: number;
    }>(),
    { wordmark: true, size: 28 },
);

const clipId = useId();
</script>

<template>
    <span class="inline-flex items-center gap-2.5 select-none">
        <svg :width="size" :height="size" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <defs>
                <clipPath :id="clipId">
                    <circle cx="12.5" cy="16" r="9.5" />
                </clipPath>
            </defs>
            <circle cx="12.5" cy="16" r="9.5" fill="var(--brand)" />
            <circle cx="22" cy="16" r="7" fill="var(--accent)" />
            <circle cx="22" cy="16" r="7" fill="var(--canvas)" fill-opacity="0.82" :clip-path="`url(#${clipId})`" />
        </svg>
        <span
            v-if="wordmark"
            class="font-display text-[1.1875rem] leading-none font-semibold tracking-[-0.035em] text-ink"
        >
            oyosend
        </span>
        <span v-else class="sr-only">Oyosend</span>
    </span>
</template>
