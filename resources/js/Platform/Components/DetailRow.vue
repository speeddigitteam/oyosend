<script setup lang="ts">
/**
 * One "label: value" pair of a definition list that runs edge to edge in a
 * flush card. Put it inside a `<dl>`; the value goes in the default slot.
 */
defineProps<{ label: string }>();
</script>

<template>
    <div class="grid grid-cols-1 gap-x-4 gap-y-1 border-t border-line px-5 py-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <dt class="text-[0.8125rem] leading-6 text-ink-subtle">{{ label }}</dt>
        <dd class="min-w-0 text-sm leading-6 wrap-break-word text-ink"><slot /></dd>
    </div>
</template>
