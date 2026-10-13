<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';

/**
 * One measure over days: a line with a soft area (spend) or bars (counts). A single axis, no dual scale.
 * A day with no figure is a gap, never a zero. Every day is a focusable hit target that shows its value;
 * the same numbers are available as a table below.
 */
const props = defineProps<{
    title: string;
    points: { date: string; value: number | null }[];
    kind: 'line' | 'bar';
    /** Formats one value for the readout and the table. */
    formatValue: (value: number) => string;
}>();

const { t } = useI18n();
const format = useFormat();

const WIDTH = 640;
const HEIGHT = 170;
const PAD = { top: 12, right: 8, bottom: 22, left: 8 };

const active = ref<number | null>(null);

const max = computed(() => Math.max(1, ...props.points.map((point) => point.value ?? 0)));
const reported = computed(() => props.points.filter((point) => point.value !== null).length);
const step = computed(() => (props.points.length > 1 ? (WIDTH - PAD.left - PAD.right) / (props.points.length - 1) : 0));

function x(index: number): number {
    return props.points.length > 1 ? PAD.left + index * step.value : WIDTH / 2;
}

function y(value: number): number {
    return PAD.top + (1 - value / max.value) * (HEIGHT - PAD.top - PAD.bottom);
}

/** Line segments between consecutive reported days; a gap breaks the line. */
const segments = computed(() => {
    const result: string[][] = [];
    let current: string[] = [];

    props.points.forEach((point, index) => {
        if (point.value === null) {
            if (current.length > 0) {
                result.push(current);
                current = [];
            }

            return;
        }

        current.push(`${x(index).toFixed(1)},${y(point.value).toFixed(1)}`);
    });

    if (current.length > 0) {
        result.push(current);
    }

    return result;
});

const baseline = HEIGHT - PAD.bottom;
const barWidth = computed(() => Math.max(2, Math.min(18, (WIDTH - PAD.left - PAD.right) / Math.max(1, props.points.length) - 3)));

const readout = computed(() => {
    const point = active.value === null ? null : props.points[active.value];

    if (!point) {
        return null;
    }

    return {
        date: format.date(point.date, 'date'),
        value: point.value === null ? t('metaAds.notReported') : props.formatValue(point.value),
    };
});

const first = computed(() => props.points[0]?.date ?? null);
const last = computed(() => props.points[props.points.length - 1]?.date ?? null);
</script>

<template>
    <figure class="min-w-0">
        <figcaption class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span class="text-sm font-medium text-ink">{{ title }}</span>
            <span class="text-xs text-ink-muted" aria-live="polite" data-testid="readout">
                <template v-if="readout">{{ readout.date }}: <span class="tabular text-ink">{{ readout.value }}</span></template>
                <template v-else>{{ t('metaAds.chart.hover', { max: formatValue(max) }) }}</template>
            </span>
        </figcaption>

        <p v-if="reported === 0" class="rounded-card bg-hover px-4 py-6 text-center text-[0.8125rem] text-ink-muted">
            {{ t('metaAds.chart.empty') }}
        </p>

        <svg
            v-else
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            class="h-auto w-full overflow-visible text-brand-text"
            role="img"
            :aria-label="title"
            preserveAspectRatio="none"
            @mouseleave="active = null"
        >
            <line :x1="PAD.left" :x2="WIDTH - PAD.right" :y1="baseline" :y2="baseline" class="stroke-line" stroke-width="1" />
            <line :x1="PAD.left" :x2="WIDTH - PAD.right" :y1="PAD.top" :y2="PAD.top" class="stroke-line" stroke-width="1" stroke-dasharray="2 4" />

            <template v-if="kind === 'line'">
                <template v-for="(segment, index) in segments" :key="index">
                    <polygon
                        v-if="segment.length > 1"
                        :points="`${segment[0]!.split(',')[0]},${baseline} ${segment.join(' ')} ${segment[segment.length - 1]!.split(',')[0]},${baseline}`"
                        fill="currentColor"
                        fill-opacity="0.1"
                    />
                    <polyline :points="segment.join(' ')" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    <circle v-if="segment.length === 1" :cx="segment[0]!.split(',')[0]" :cy="segment[0]!.split(',')[1]" r="3" fill="currentColor" />
                </template>
            </template>

            <template v-else>
                <template v-for="(point, index) in points" :key="point.date">
                    <path
                        v-if="point.value !== null && point.value > 0"
                        :d="`M ${x(index) - barWidth / 2} ${baseline} V ${y(point.value) + 3} Q ${x(index) - barWidth / 2} ${y(point.value)} ${x(index) - barWidth / 2 + 3} ${y(point.value)} H ${x(index) + barWidth / 2 - 3} Q ${x(index) + barWidth / 2} ${y(point.value)} ${x(index) + barWidth / 2} ${y(point.value) + 3} V ${baseline} Z`"
                        fill="currentColor"
                        :fill-opacity="active === index ? 1 : 0.75"
                    />
                </template>
            </template>

            <circle v-if="active !== null && points[active]?.value != null" :cx="x(active)" :cy="y(points[active]!.value!)" r="4" fill="currentColor" class="stroke-surface" stroke-width="2" />

            <rect
                v-for="(point, index) in points"
                :key="`hit-${point.date}`"
                :x="x(index) - Math.max(step / 2, 6)"
                :y="PAD.top"
                :width="Math.max(step, 12)"
                :height="baseline - PAD.top"
                fill="transparent"
                tabindex="0"
                role="img"
                :aria-label="`${format.date(point.date, 'date')}: ${point.value === null ? t('metaAds.notReported') : formatValue(point.value)}`"
                class="outline-none focus-visible:stroke-brand-text"
                stroke-width="1"
                @mouseenter="active = index"
                @focus="active = index"
                @blur="active = null"
            />
        </svg>

        <div v-if="reported > 0" class="mt-1 flex justify-between text-xs text-ink-subtle">
            <span>{{ first ? format.date(first, 'date') : '' }}</span>
            <span>{{ last ? format.date(last, 'date') : '' }}</span>
        </div>

        <details v-if="reported > 0" class="mt-2 text-[0.8125rem]">
            <summary class="cursor-pointer text-ink-muted hover:text-ink">{{ t('metaAds.chart.table') }}</summary>
            <div class="mt-2 max-h-56 overflow-auto">
                <table class="w-full text-left">
                    <caption class="sr-only">{{ title }}</caption>
                    <tbody>
                        <tr v-for="point in points" :key="point.date" class="border-b border-line last:border-b-0">
                            <th scope="row" class="py-1 pr-3 font-normal text-ink-muted">{{ format.date(point.date, 'date') }}</th>
                            <td class="tabular py-1 text-right text-ink">{{ point.value === null ? t('metaAds.notReported') : formatValue(point.value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </figure>
</template>
