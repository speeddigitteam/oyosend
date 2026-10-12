<script setup lang="ts">
import { computed } from 'vue';
import { useTalentFormat } from './talentFormat';

/**
 * A horizontal bar for a share held in basis points (10 000 is full). It is
 * announced as a progress bar; the figure is always printed next to it so
 * length and colour are never the only carrier of the number.
 */
const props = withDefaults(defineProps<{ bp: number; label: string; compact?: boolean; hideValue?: boolean }>(), { compact: false, hideValue: false });

const talent = useTalentFormat();
const clamped = computed(() => Math.min(10_000, Math.max(0, props.bp)));
const width = computed(() => `${(clamped.value / 100).toFixed(2)}%`);
</script>

<template>
    <div class="flex min-w-24 items-center gap-2">
        <div
            role="progressbar"
            :aria-label="label"
            :aria-valuenow="Math.round(clamped / 100)"
            aria-valuemin="0"
            aria-valuemax="100"
            class="min-w-0 flex-1 overflow-hidden rounded-chip bg-hover"
            :class="compact ? 'h-1.5' : 'h-2'"
        >
            <div class="h-full rounded-chip bg-brand" :style="{ width }" />
        </div>
        <span v-if="!hideValue" class="tabular shrink-0 text-xs text-ink-muted">{{ talent.percent(clamped) }}</span>
    </div>
</template>
