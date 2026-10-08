<script setup lang="ts">
/**
 * One headline number. The value is rendered as given - format it with
 * `useFormat()` first so digits follow the person's numeral setting.
 */
defineProps<{
    label: string;
    value: string;
    /** Secondary line under the value. */
    hint?: string;
    /** Draws the accent marker: use for the one tile that needs attention. */
    accent?: boolean;
}>();
</script>

<template>
    <div class="glass relative flex flex-col gap-3 overflow-hidden rounded-card px-5 py-4">
        <span
            v-if="accent"
            class="absolute top-4 right-4 size-2 rounded-full bg-accent shadow-[0_0_0_4px_var(--accent-soft)]"
            aria-hidden="true"
        />
        <p class="eyebrow">{{ label }}</p>
        <p class="figure text-[2.25rem] text-ink">{{ value }}</p>
        <p v-if="hint || $slots.hint" class="-mt-1 text-[0.8125rem] text-ink-subtle">
            <slot name="hint">{{ hint }}</slot>
        </p>
    </div>
</template>
