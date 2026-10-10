<script setup lang="ts">
import { computed } from 'vue';

/**
 * A thin bar showing how much of a limit is used. It turns amber when little
 * is left and red when the limit is reached. A visual aid only: always print
 * the figures beside it, which is what gets read out.
 */
const props = defineProps<{
    value: number;
    max: number;
}>();

// A limit of nothing is always reached.
const ratio = computed(() => (props.max > 0 ? Math.min(1, Math.max(0, props.value / props.max)) : 1));

const fill = computed(() => {
    if (ratio.value >= 1) {
        return 'bg-danger';
    }

    return ratio.value >= 0.8 ? 'bg-warn' : 'bg-brand';
});
</script>

<template>
    <span class="block h-1.5 overflow-hidden rounded-full bg-hover" aria-hidden="true">
        <span :class="['block h-full rounded-full transition-[width] duration-300 ease-soft', fill]" :style="{ width: `${ratio * 100}%` }" />
    </span>
</template>
