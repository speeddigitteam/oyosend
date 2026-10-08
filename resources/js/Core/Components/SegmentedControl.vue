<script setup lang="ts" generic="V extends string">
import type { Component } from 'vue';

export interface Segment<Value extends string = string> {
    value: Value;
    label: string;
    icon?: Component;
}

/**
 * A small set of mutually exclusive choices shown side by side (theme,
 * view mode, a status filter). A radio group underneath.
 */
defineProps<{
    options: Segment<V>[];
    /** Accessible name of the group. */
    label: string;
    /** Show icons only; labels become tooltips and screen-reader text. */
    iconOnly?: boolean;
}>();

const model = defineModel<V>({ required: true });
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="glass-well inline-flex items-center gap-0.5 rounded-control p-0.5">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="model === option.value"
            :title="iconOnly ? option.label : undefined"
            :class="[
                'inline-flex h-7 items-center justify-center gap-1.5 rounded-[0.4375rem] text-[0.8125rem] font-medium transition-colors',
                iconOnly ? 'w-8' : 'px-2.5',
                model === option.value
                    ? 'bg-active text-ink shadow-[inset_0_1px_0_var(--glass-edge),0_1px_2px_rgb(0_0_0/0.1)]'
                    : 'text-ink-subtle hover:text-ink',
            ]"
            @click="model = option.value"
        >
            <component :is="option.icon" v-if="option.icon" class="size-3.5" aria-hidden="true" />
            <span :class="iconOnly ? 'sr-only' : ''">{{ option.label }}</span>
        </button>
    </div>
</template>
