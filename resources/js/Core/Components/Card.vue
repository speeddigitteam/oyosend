<script setup lang="ts">
/**
 * The standard glass panel. Optional header (title, description, actions) and
 * footer; `flush` removes the body padding for tables and lists that should
 * run edge to edge.
 */
withDefaults(
    defineProps<{
        as?: string;
        title?: string;
        description?: string;
        flush?: boolean;
    }>(),
    { as: 'section', title: undefined, description: undefined },
);
</script>

<template>
    <component :is="as" class="glass flex flex-col overflow-hidden rounded-card">
        <header
            v-if="title || $slots.header || $slots.actions"
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 pt-4 pb-3"
        >
            <slot name="header">
                <div class="min-w-0">
                    <h2 class="text-[0.9375rem] font-semibold tracking-tight text-ink">{{ title }}</h2>
                    <p v-if="description" class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ description }}</p>
                </div>
            </slot>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div :class="['flex-1', flush ? '' : 'px-5 pb-5', !flush && !(title || $slots.header || $slots.actions) ? 'pt-5' : '']">
            <slot />
        </div>

        <footer v-if="$slots.footer" class="border-t border-line px-5 py-3">
            <slot name="footer" />
        </footer>
    </component>
</template>
