<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Spinner from './Spinner.vue';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        size?: Size;
        type?: 'button' | 'submit' | 'reset';
        /** Renders an Inertia link instead of a button. */
        href?: string;
        loading?: boolean;
        disabled?: boolean;
        /** Full width. */
        block?: boolean;
        /** Square button for a lone icon. Give it an `aria-label`. */
        icon?: boolean;
    }>(),
    {
        variant: 'secondary',
        size: 'md',
        type: 'button',
        href: undefined,
    },
);

const VARIANTS: Record<Variant, string> = {
    primary:
        'bg-brand text-brand-ink shadow-[inset_0_1px_0_rgb(255_255_255/0.22),0_1px_2px_rgb(0_0_0/0.16),0_8px_18px_-8px_var(--brand-shadow)] hover:bg-brand-hover active:shadow-[inset_0_1px_2px_rgb(0_0_0/0.25)]',
    secondary:
        'bg-control text-ink border border-line-strong shadow-[inset_0_1px_0_var(--glass-edge),0_1px_2px_rgb(0_0_0/0.05)] hover:bg-control-hover active:shadow-[inset_0_1px_2px_rgb(0_0_0/0.12)]',
    ghost: 'text-ink-muted hover:bg-hover hover:text-ink active:bg-hover',
    danger: 'bg-danger text-canvas shadow-[inset_0_1px_0_rgb(255_255_255/0.2),0_1px_2px_rgb(0_0_0/0.16)] hover:brightness-110 active:shadow-[inset_0_1px_2px_rgb(0_0_0/0.25)]',
};

const SIZES: Record<Size, { box: string; square: string }> = {
    sm: { box: 'h-8 px-3 text-[0.8125rem] gap-1.5', square: 'size-8' },
    md: { box: 'h-[2.375rem] px-4 text-sm gap-2', square: 'size-[2.375rem]' },
    lg: { box: 'h-11 px-5 text-[0.9375rem] gap-2', square: 'size-11' },
};

const classes = computed(() => [
    'relative inline-flex shrink-0 items-center justify-center rounded-control font-medium whitespace-nowrap select-none',
    'transition-[background-color,box-shadow,filter,transform] duration-150 active:translate-y-px',
    'disabled:pointer-events-none disabled:opacity-55 aria-disabled:pointer-events-none aria-disabled:opacity-55',
    VARIANTS[props.variant],
    props.icon ? SIZES[props.size].square : SIZES[props.size].box,
    props.block ? 'w-full' : '',
]);

const inactive = computed(() => props.disabled || props.loading);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes" :aria-disabled="inactive || undefined">
        <slot name="icon" />
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="inactive" :aria-busy="loading || undefined">
        <Spinner v-if="loading" class="absolute" />
        <span :class="['contents', loading ? '[&>*]:invisible' : '']">
            <slot name="icon" />
            <span v-if="$slots.default" :class="icon ? 'sr-only' : ''"><slot /></span>
        </span>
    </button>
</template>
