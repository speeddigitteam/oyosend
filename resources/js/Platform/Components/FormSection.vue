<script setup lang="ts">
/**
 * One part of a long form inside a card: its heading and what it is for on
 * the left, its fields on the right (stacked on a narrow screen). Separate
 * two of them with `FormRule`.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid gap-x-10 gap-y-4 md:grid-cols-[minmax(0,2fr)_minmax(0,5fr)]">
        <div class="min-w-0">
            <h2 class="text-[0.9375rem] font-semibold tracking-tight text-ink">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ description }}</p>
        </div>

        <div class="min-w-0">
            <slot />
        </div>
    </div>
</template>
