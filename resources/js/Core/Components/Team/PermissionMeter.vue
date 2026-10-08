<script setup lang="ts">
import { computed } from 'vue';

/**
 * A thin bar showing how much of a whole is covered. It is a visual aid only:
 * always print the figures beside it, which is what gets read out.
 */
const props = defineProps<{
    value: number;
    max: number;
}>();

const percent = computed(() => (props.max > 0 ? Math.min(100, Math.max(0, (props.value / props.max) * 100)) : 0));
</script>

<template>
    <span class="block h-1.5 overflow-hidden rounded-full bg-brand-soft" aria-hidden="true">
        <span class="block h-full rounded-full bg-brand transition-[width] duration-300 ease-soft" :style="{ width: `${percent}%` }" />
    </span>
</template>
