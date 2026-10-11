<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/Core/composables/useFormat';
const props = withDefaults(defineProps<{ value: number; label?: string; compact?: boolean }>(), { compact: false });
const format = useFormat();
const percentage = computed(() => Math.min(100, Math.max(0, Math.round(props.value))));
</script>
<template>
    <div :aria-label="label" role="progressbar" :aria-valuenow="percentage" aria-valuemin="0" aria-valuemax="100" class="min-w-20">
        <div class="overflow-hidden rounded-chip bg-hover" :class="compact ? 'h-1.5' : 'h-2'">
            <div class="h-full rounded-chip bg-brand transition-[width]" :style="{ width: `${percentage}%` }" />
        </div>
        <span v-if="!compact" class="mt-1 block text-right font-mono text-xs text-ink-subtle">{{ format.number(percentage / 100, { style: 'percent', maximumFractionDigits: 0 }) }}</span>
    </div>
</template>

