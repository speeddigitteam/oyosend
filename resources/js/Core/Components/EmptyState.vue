<script setup lang="ts">
/**
 * What a list, table or panel shows when it has nothing in it. Say what will
 * appear here and, where the person can do something about it, offer it.
 */
defineProps<{
    title: string;
    description?: string;
    /** Tighter spacing, for use inside small cards. */
    compact?: boolean;
}>();
</script>

<template>
    <div :class="['flex flex-col items-center text-center', compact ? 'px-4 py-8' : 'px-6 py-14']">
        <div
            v-if="$slots.icon"
            class="glass-well mb-4 flex size-11 items-center justify-center rounded-full text-ink-subtle [&>svg]:size-5"
            aria-hidden="true"
        >
            <slot name="icon" />
        </div>
        <!-- h3 sits under a card's h2 title; a full-size empty state follows the page h1 directly, so h2. -->
        <component :is="compact ? 'h3' : 'h2'" class="font-sans text-sm font-semibold tracking-normal text-ink">{{ title }}</component>
        <p v-if="description" class="mt-1 max-w-sm text-[0.8125rem] text-ink-subtle">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap items-center justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
