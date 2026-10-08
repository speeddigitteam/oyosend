<script setup lang="ts">
import { computed } from 'vue';

type Size = 'sm' | 'md' | 'lg';

/**
 * Initials on a tinted tile. The tint is derived from the name, so the same
 * person always gets the same colour without storing one.
 */
const props = withDefaults(
    defineProps<{
        initials: string;
        /** Seed for the tint; defaults to the initials. */
        name?: string;
        size?: Size;
        /** Rounded square instead of a circle: used for workspaces. */
        square?: boolean;
    }>(),
    { size: 'md', name: undefined },
);

const TINTS = [
    'bg-brand-soft text-brand-text',
    'bg-accent-soft text-accent-text',
    'bg-info-soft text-info',
    'bg-warn-soft text-warn',
    'bg-ok-soft text-ok',
] as const;

const SIZES: Record<Size, string> = {
    sm: 'size-7 text-[0.6875rem]',
    md: 'size-9 text-xs',
    lg: 'size-12 text-sm',
};

const tint = computed(() => {
    const seed = props.name ?? props.initials;
    let hash = 0;

    for (const character of seed) {
        hash = (hash * 31 + (character.codePointAt(0) ?? 0)) >>> 0;
    }

    return TINTS[hash % TINTS.length];
});
</script>

<template>
    <span
        :class="[
            'inline-flex shrink-0 items-center justify-center font-semibold tracking-wide uppercase select-none',
            'shadow-[inset_0_1px_0_var(--glass-edge)] ring-1 ring-line',
            square ? 'rounded-[0.5625rem]' : 'rounded-full',
            SIZES[size],
            tint,
        ]"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
