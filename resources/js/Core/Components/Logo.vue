<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, useId } from 'vue';

/**
 * The Oyosend mark: two overlapping discs, the larger in the brand colour
 * and the smaller in the accent, with the overlap picked out - the same
 * lens-behind-glass idea the interface is built on.
 *
 * When an operator uploaded a logo (Super Admin > Website), that image is
 * shown instead, at the mark's height, with its dark-background version in
 * the dark theme when there is one.
 */
const props = withDefaults(
    defineProps<{
        /** Show the wordmark next to the mark. */
        wordmark?: boolean;
        size?: number;
    }>(),
    { wordmark: true, size: 28 },
);

const clipId = useId();
const page = usePage();

const uploaded = computed(() => page.props.app?.brand ?? null);
const name = computed(() => page.props.app?.name ?? 'Oyosend');
const height = computed(() => `${Math.round(props.size * 1.15)}px`);
</script>

<template>
    <span v-if="uploaded?.logo" class="inline-flex items-center select-none">
        <img
            :src="uploaded.logo"
            :alt="name"
            :style="{ height }"
            :class="['w-auto max-w-48 object-contain', uploaded.logoDark ? 'dark:hidden' : '']"
        />
        <img v-if="uploaded.logoDark" :src="uploaded.logoDark" alt="" aria-hidden="true" :style="{ height }" class="hidden w-auto max-w-48 object-contain dark:block" />
    </span>
    <span v-else class="inline-flex items-center gap-2.5 select-none">
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
