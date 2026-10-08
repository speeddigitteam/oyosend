<script setup lang="ts">
export type BadgeTone = 'neutral' | 'brand' | 'accent' | 'ok' | 'warn' | 'danger' | 'info';

withDefaults(
    defineProps<{
        tone?: BadgeTone;
        /** Leading status dot. */
        dot?: boolean;
    }>(),
    { tone: 'neutral' },
);

const TONES: Record<BadgeTone, string> = {
    neutral: 'bg-hover text-ink-muted',
    brand: 'bg-brand-soft text-brand-text',
    accent: 'bg-accent-soft text-accent-text',
    ok: 'bg-ok-soft text-ok',
    warn: 'bg-warn-soft text-warn',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
};
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 rounded-chip px-2 py-0.5 text-xs leading-5 font-medium whitespace-nowrap',
            TONES[tone],
        ]"
    >
        <span v-if="dot" class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        <slot />
    </span>
</template>
