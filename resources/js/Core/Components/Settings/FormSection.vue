<script setup lang="ts">
import { useId } from 'vue';

/**
 * One titled group of fields in a settings form. Several of them share a
 * card; `divided` draws the rule that separates a section from the one above,
 * edge to edge, so the card reads as one form rather than stacked panels.
 *
 * Fields flow in two columns from `sm` up. A field that should take the whole
 * row carries `sm:col-span-2`.
 */
defineProps<{
    title: string;
    description?: string;
    divided?: boolean;
}>();

const headingId = useId();
</script>

<template>
    <section :aria-labelledby="headingId" :class="divided ? '-mx-5 mt-6 border-t border-line px-5 pt-6' : ''">
        <h2 :id="headingId" class="text-[0.9375rem] font-semibold tracking-tight text-ink">{{ title }}</h2>
        <p v-if="description" class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ description }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <slot />
        </div>
    </section>
</template>
