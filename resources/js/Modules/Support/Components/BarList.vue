<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/Core/composables/useFormat';

/**
 * A short list of labelled counts with a proportional bar. The number is
 * always written out, so the bar is decoration and never the only carrier.
 */
const props = defineProps<{
    items: { key: string; label: string; value: number }[];
    /** Accessible name of the list. */
    label: string;
}>();

const format = useFormat();
const max = computed(() => Math.max(1, ...props.items.map((item) => item.value)));
</script>

<template>
    <ul class="grid gap-2" :aria-label="label">
        <li v-for="item in items" :key="item.key" class="grid grid-cols-[minmax(0,8rem)_1fr_auto] items-center gap-3 text-sm">
            <span class="text-ink-muted wrap-anywhere">{{ item.label }}</span>
            <span class="h-2 overflow-hidden rounded-chip bg-hover" aria-hidden="true">
                <span class="block h-full rounded-chip bg-brand" :style="{ width: `${Math.round((item.value / max) * 100)}%` }" />
            </span>
            <span class="tabular text-right font-medium text-ink">{{ format.number(item.value) }}</span>
        </li>
    </ul>
</template>
