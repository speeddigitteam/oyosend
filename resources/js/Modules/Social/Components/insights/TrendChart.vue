<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';

/**
 * A small accessible line chart: one line per series, gaps where nothing was reported (a gap is
 * "not reported", never zero), a readout that follows the pointer or the arrow keys, and the same
 * numbers in a table below. No chart library.
 */
const props = defineProps<{
    /** `YYYY-MM-DD` per point. */
    dates: string[];
    series: { id: string; name: string; values: (number | null)[] }[];
    /** Accessible name of the chart (what it shows). */
    label: string;
}>();

const { t } = useI18n();
const format = useFormat();

const W = 640;
const H = 220;
const LEFT = 52;
const RIGHT = 12;
const TOP = 12;
const BOTTOM = 26;
const COLORS = ['text-brand', 'text-accent', 'text-info', 'text-ok', 'text-warn', 'text-danger', 'text-ink-muted', 'text-brand-text', 'text-accent-text', 'text-ink'];

const active = ref<number | null>(null);

const max = computed(() => {
    const values = props.series.flatMap((s) => s.values).filter((v): v is number => v !== null);
    const top = values.length > 0 ? Math.max(...values) : 0;

    if (top <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(top));
    const step = [1, 2, 2.5, 5, 10].find((m) => m * magnitude >= top / 4) ?? 10;

    return Math.ceil(top / (step * magnitude)) * step * magnitude;
});

const ticks = computed(() => [0, 0.5, 1].map((r) => Math.round(max.value * r)));
const count = computed(() => props.dates.length);

function x(index: number): number {
    return count.value <= 1 ? (LEFT + W - RIGHT) / 2 : LEFT + (index * (W - LEFT - RIGHT)) / (count.value - 1);
}

function y(value: number): number {
    return TOP + (1 - value / max.value) * (H - TOP - BOTTOM);
}

/** Path segments broken at every missing value. */
const lines = computed(() => props.series.map((s, i) => {
    const segments: string[] = [];
    const dots: { cx: number; cy: number }[] = [];
    let run: { cx: number; cy: number }[] = [];

    const flush = (): void => {
        if (run.length === 1) {
            dots.push(run[0]);
        } else if (run.length > 1) {
            segments.push(run.map((p, k) => `${k === 0 ? 'M' : 'L'}${p.cx.toFixed(1)} ${p.cy.toFixed(1)}`).join(' '));
        }

        run = [];
    };

    s.values.forEach((value, index) => {
        if (value === null) {
            flush();
        } else {
            run.push({ cx: x(index), cy: y(value) });
        }
    });
    flush();

    return { id: s.id, name: s.name, color: COLORS[i % COLORS.length], segments, dots };
}));

function dayLabel(date: string): string {
    return format.date(`${date}T12:00:00Z`, 'date');
}

const readout = computed(() => {
    if (active.value === null) {
        return null;
    }

    const index = active.value;

    return {
        date: dayLabel(props.dates[index] ?? ''),
        values: props.series.map((s, i) => ({
            name: s.name,
            color: COLORS[i % COLORS.length],
            value: s.values[index] === null || s.values[index] === undefined ? t('socialInsights.notReported') : format.number(s.values[index] as number),
        })),
    };
});

function locate(event: PointerEvent): void {
    const box = (event.currentTarget as SVGElement).getBoundingClientRect();
    const ratio = (event.clientX - box.left) / box.width;
    const px = ratio * W;
    const index = Math.round(((px - LEFT) / (W - LEFT - RIGHT)) * (count.value - 1));

    active.value = Math.min(count.value - 1, Math.max(0, index));
}

function key(event: KeyboardEvent): void {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        const next = (active.value ?? (event.key === 'ArrowLeft' ? count.value : -1)) + (event.key === 'ArrowLeft' ? -1 : 1);

        active.value = Math.min(count.value - 1, Math.max(0, next));
    } else if (event.key === 'Escape') {
        active.value = null;
    }
}

const hasData = computed(() => props.series.some((s) => s.values.some((v) => v !== null)));
</script>

<template>
    <div>
        <div
            tabindex="0"
            role="group"
            :aria-label="`${label}. ${t('socialInsights.chart.keys')}`"
            class="rounded-control focus-visible:outline-2 focus-visible:outline-offset-2"
            @keydown="key"
            @blur="active = null"
        >
            <svg :viewBox="`0 0 ${W} ${H}`" class="block h-auto w-full" role="img" :aria-label="label" @pointermove="locate" @pointerleave="active = null">
                <g class="text-line" aria-hidden="true">
                    <line v-for="tick in ticks" :key="tick" :x1="LEFT" :x2="W - RIGHT" :y1="y(tick)" :y2="y(tick)" stroke="currentColor" stroke-width="1" />
                </g>
                <g class="fill-ink-subtle" font-size="11" aria-hidden="true">
                    <text v-for="tick in ticks" :key="tick" :x="LEFT - 6" :y="y(tick) + 4" text-anchor="end">{{ format.number(tick, { notation: 'compact' }) }}</text>
                    <text v-if="count > 0" :x="LEFT" :y="H - 6" text-anchor="start">{{ dayLabel(dates[0]) }}</text>
                    <text v-if="count > 1" :x="W - RIGHT" :y="H - 6" text-anchor="end">{{ dayLabel(dates[count - 1]) }}</text>
                </g>
                <g v-for="line in lines" :key="line.id" :class="line.color" fill="none" stroke="currentColor" aria-hidden="true">
                    <path v-for="(segment, i) in line.segments" :key="i" :d="segment" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    <circle v-for="(dot, i) in line.dots" :key="`d${i}`" :cx="dot.cx" :cy="dot.cy" r="3.5" fill="currentColor" stroke="none" />
                </g>
                <g v-if="active !== null" aria-hidden="true">
                    <line :x1="x(active)" :x2="x(active)" :y1="TOP" :y2="H - BOTTOM" class="stroke-line-strong" stroke-width="1" />
                    <template v-for="(s, i) in series" :key="s.id">
                        <circle
                            v-if="s.values[active] !== null && s.values[active] !== undefined"
                            :cx="x(active)"
                            :cy="y(s.values[active] as number)"
                            r="4"
                            :class="[COLORS[i % COLORS.length], 'stroke-canvas']"
                            fill="currentColor"
                            stroke-width="2"
                        />
                    </template>
                </g>
            </svg>
        </div>

        <p v-if="!hasData" class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.chart.noData') }}</p>

        <ul v-if="series.length > 1" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted" :aria-label="t('socialInsights.chart.legend')">
            <li v-for="(s, i) in series" :key="s.id" class="inline-flex items-center gap-1.5">
                <span :class="['inline-block h-0.5 w-4 rounded-full bg-current', COLORS[i % COLORS.length]]" aria-hidden="true" />
                {{ s.name }}
            </li>
        </ul>

        <p class="mt-2 min-h-5 text-[0.8125rem] text-ink-muted" aria-live="polite" data-testid="readout">
            <template v-if="readout">
                <span class="font-medium text-ink">{{ readout.date }}</span>
                <span v-for="v in readout.values" :key="v.name" class="ml-3 inline-flex items-center gap-1.5">
                    <span :class="['inline-block size-2 rounded-full bg-current', v.color]" aria-hidden="true" />{{ series.length > 1 ? `${v.name}: ` : '' }}<span class="tabular text-ink">{{ v.value }}</span>
                </span>
            </template>
        </p>

        <details class="mt-1 text-[0.8125rem]">
            <summary class="cursor-pointer text-brand-text">{{ t('socialInsights.chart.table') }}</summary>
            <div class="mt-2 max-h-64 overflow-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ label }}</caption>
                    <thead>
                        <tr class="border-b border-line">
                            <th scope="col" class="eyebrow px-3 py-1.5 font-normal">{{ t('socialInsights.chart.date') }}</th>
                            <th v-for="s in series" :key="s.id" scope="col" class="eyebrow px-3 py-1.5 text-right font-normal">{{ s.name }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(date, index) in dates" :key="date" class="border-b border-line last:border-b-0">
                            <th scope="row" class="px-3 py-1 font-normal text-ink-muted">{{ dayLabel(date) }}</th>
                            <td v-for="s in series" :key="s.id" class="tabular px-3 py-1 text-right text-ink-muted">
                                {{ s.values[index] === null || s.values[index] === undefined ? t('socialInsights.notReported') : format.number(s.values[index] as number) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</template>
