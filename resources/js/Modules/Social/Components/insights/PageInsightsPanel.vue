<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Download, ChartNoAxesCombined } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import InsightsPostSections from './InsightsPostSections.vue';
import TrendChart from './TrendChart.vue';
import { INSIGHT_FIGURES, type InsightFigure, type InsightKpi, type InsightsDashboard } from './types';

const props = defineProps<{
    insights: InsightsDashboard;
    /** The date range of the publishing report below, kept in the URL while this panel changes. */
    range: { from: string; to: string };
}>();

const { t } = useI18n();
const format = useFormat();

const days = ref(String(props.insights.period.days));
const chosen = ref<string[]>([...props.insights.selected]);
const figure = ref<InsightFigure>('impressions');

watch(() => props.insights.selected, (value) => {
    chosen.value = [...value];
});

const periodOptions = computed(() => props.insights.period.options.map((value) => ({ value: String(value), label: t('socialInsights.period.short', { count: format.number(value) }) })));
const figureOptions = computed(() => INSIGHT_FIGURES.map((key) => ({ value: key, label: t(`socialInsights.figures.${key}`) })));
const tooMany = computed(() => chosen.value.length > props.insights.maxPages);
const many = computed(() => props.insights.pages.length > 1);

const trend = computed(() => props.insights.trend[figure.value]);
const series = computed(() => {
    const t0 = trend.value;
    const selected = props.insights.comparison;

    if (selected.length > 1) {
        return t0.accounts.map((account) => ({ id: account.id, name: account.name, values: account.values }));
    }

    return [{ id: 'total', name: selected[0]?.name ?? t('socialInsights.total'), values: t0.total }];
});

function navigate(next: { days?: string; pages?: string[] }): void {
    const pages = next.pages ?? chosen.value;

    router.get(
        route('social.analytics.index'),
        { from: props.range.from, to: props.range.to, days: next.days ?? days.value, ...(pages.length > 0 ? { pages } : {}) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setDays(value: string): void {
    days.value = value;
    navigate({ days: value });
}

function toggle(id: string, on: boolean): void {
    chosen.value = on ? [...chosen.value, id] : chosen.value.filter((item) => item !== id);
}

function exportUrl(dataset: 'daily' | 'posts' | 'campaigns'): string {
    const query: Record<string, string> = { days: days.value, dataset };

    chosen.value.forEach((id, index) => {
        query[`pages[${index}]`] = id;
    });

    return route('social.analytics.export', query);
}

function value(kpi: InsightKpi): string {
    return kpi.value === null ? t('socialInsights.notReported') : format.number(kpi.value);
}

function signed(amount: number): string {
    return `${amount > 0 ? '+' : amount < 0 ? '−' : ''}${format.number(Math.abs(amount))}`;
}

/** Whether a rising number is good news (unfollows are the exception). */
function good(kpi: InsightKpi): boolean | null {
    if (kpi.delta === null || kpi.delta === 0) {
        return null;
    }

    return kpi.figure === 'unfollows' ? kpi.delta < 0 : kpi.delta > 0;
}

function note(kpi: InsightKpi): string {
    if (kpi.value === null) {
        return t('socialInsights.kpi.none');
    }

    if (kpi.delta !== null) {
        return '';
    }

    if (kpi.previous === null) {
        return t('socialInsights.kpi.noPrevious');
    }

    return t('socialInsights.kpi.differs', { days: format.number(kpi.days), previous: format.number(kpi.previousDays) });
}

function coverage(kpi: InsightKpi): string | null {
    if (kpi.level) {
        return kpi.asOf ? t('socialInsights.kpi.asOf', { date: format.date(`${kpi.asOf}T12:00:00Z`, 'date') }) : null;
    }

    return kpi.value !== null && kpi.days < kpi.expectedDays
        ? t('socialInsights.kpi.days', { days: format.number(kpi.days), total: format.number(kpi.expectedDays) })
        : null;
}

function statusLabel(status: string | null): string {
    return status === null ? t('socialInsights.status.never') : t(`socialInsights.status.${status}`);
}

const stateText = computed(() => props.insights.state);
</script>

<template>
    <section aria-labelledby="insights-title" data-testid="insights">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h2 id="insights-title" class="text-base">{{ t('socialInsights.title') }}</h2>
                <p class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.description') }}</p>
            </div>
            <SegmentedControl :model-value="days" :options="periodOptions" :label="t('socialInsights.period.label')" @update:model-value="setDays" />
        </div>

        <EmptyState
            v-if="stateText !== 'ready' && stateText !== 'waiting' && stateText !== 'permission_missing'"
            :title="t(`socialInsights.states.${stateText}.title`)"
            :description="t(`socialInsights.states.${stateText}.body`)"
            :data-testid="`state-${stateText}`"
        >
            <template #icon><ChartNoAxesCombined /></template>
        </EmptyState>

        <template v-else>
            <Card v-if="many" class="mb-3" :title="t('socialInsights.pages.title')" :description="t('socialInsights.pages.hint', { max: format.number(insights.maxPages) })">
                <form class="grid gap-3" novalidate @submit.prevent="navigate({ pages: chosen })">
                    <fieldset class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <legend class="sr-only">{{ t('socialInsights.pages.title') }}</legend>
                        <Checkbox
                            v-for="page in insights.pages"
                            :key="page.id"
                            :model-value="chosen.includes(page.id)"
                            :label="page.name"
                            @update:model-value="toggle(page.id, $event)"
                        />
                    </fieldset>
                    <p v-if="tooMany" class="text-[0.8125rem] text-warn" role="status">{{ t('socialInsights.pages.tooMany', { max: format.number(insights.maxPages) }) }}</p>
                    <div>
                        <Button type="submit" size="sm" :disabled="tooMany">{{ t('social.common.apply') }}</Button>
                    </div>
                </form>
            </Card>

            <div
                v-if="stateText === 'waiting' || stateText === 'permission_missing'"
                class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn"
                role="status"
                :data-testid="`state-${stateText}`"
            >
                <p class="font-medium">{{ t(`socialInsights.states.${stateText}.title`) }}</p>
                <p class="mt-1">{{ t(`socialInsights.states.${stateText}.body`) }}</p>
            </div>

            <p class="mb-2 text-[0.8125rem] text-ink-muted">
                {{ t('socialInsights.period.compare', {
                    from: format.date(`${insights.period.from}T12:00:00Z`, 'date'),
                    to: format.date(`${insights.period.to}T12:00:00Z`, 'date'),
                    previousFrom: format.date(`${insights.period.previousFrom}T12:00:00Z`, 'date'),
                    previousTo: format.date(`${insights.period.previousTo}T12:00:00Z`, 'date'),
                }) }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="kpis">
                <StatTile v-for="kpi in insights.kpis" :key="kpi.figure" :label="t(`socialInsights.figures.${kpi.figure}`)" :value="value(kpi)">
                    <template #hint>
                        <span v-if="kpi.delta !== null" class="inline-flex flex-wrap items-center gap-1" :data-testid="`delta-${kpi.figure}`">
                            <span :class="good(kpi) === true ? 'text-ok' : good(kpi) === false ? 'text-danger' : 'text-ink-muted'" class="inline-flex items-center gap-0.5 font-medium">
                                <ArrowUp v-if="kpi.delta > 0" class="size-3" aria-hidden="true" />
                                <ArrowDown v-else-if="kpi.delta < 0" class="size-3" aria-hidden="true" />
                                <span class="sr-only">{{ kpi.delta > 0 ? t('socialInsights.kpi.up') : kpi.delta < 0 ? t('socialInsights.kpi.down') : t('socialInsights.kpi.same') }}</span>
                                {{ signed(kpi.delta) }}<template v-if="kpi.deltaPct !== null"> ({{ signed(kpi.deltaPct) }}%)</template>
                            </span>
                            <span>{{ t('socialInsights.kpi.vsPrevious') }}</span>
                        </span>
                        <span v-else>{{ note(kpi) }}</span>
                        <span v-if="coverage(kpi)" class="block text-xs">{{ coverage(kpi) }}</span>
                    </template>
                </StatTile>
            </div>

            <Card class="mt-3" :title="t('socialInsights.trend.title')" :description="t('socialInsights.trend.hint')">
                <template #actions>
                    <Select v-model="figure" :label="t('socialInsights.trend.figure')" :options="figureOptions" class="w-48" />
                </template>
                <TrendChart :dates="insights.period.dates" :series="series" :label="t('socialInsights.trend.chart', { figure: t(`socialInsights.figures.${figure}`) })" />
            </Card>

            <Card v-if="insights.comparison.length > 1" class="mt-3" :title="t('socialInsights.compare.title')" :description="t('socialInsights.compare.hint')" flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" data-testid="compare">
                        <caption class="sr-only">{{ t('socialInsights.compare.title') }}</caption>
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('socialInsights.compare.page') }}</th>
                                <th v-for="key in INSIGHT_FIGURES" :key="key" scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t(`socialInsights.figures.${key}`) }}</th>
                                <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.compare.days') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in insights.comparison" :key="row.id" class="border-b border-line last:border-b-0">
                                <th scope="row" class="px-5 py-2 font-normal text-ink">
                                    {{ row.name }}
                                    <span class="block text-xs text-ink-subtle">{{ statusLabel(row.insightsStatus) }}</span>
                                </th>
                                <td v-for="key in INSIGHT_FIGURES" :key="key" class="tabular px-4 py-2 text-right text-ink-muted">
                                    {{ row.figures[key] === null || row.figures[key] === undefined ? t('socialInsights.notReported') : format.number(row.figures[key] as number) }}
                                </td>
                                <td class="tabular px-4 py-2 text-right text-ink-muted">{{ format.number(row.daysReported) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <InsightsPostSections :posts="insights.posts" :period-days="insights.period.days" />

            <Card class="mt-3" :title="t('socialInsights.coverage.title')" data-testid="coverage">
                <p class="text-[0.8125rem] text-ink-muted">
                    <template v-if="insights.coverage.lastUpdatedAt">
                        {{ t('socialInsights.coverage.updated', { when: format.date(insights.coverage.lastUpdatedAt, 'datetime') }) }}
                    </template>
                    <template v-else>{{ t('socialInsights.coverage.never') }}</template>
                    {{ t('socialInsights.coverage.days', { days: format.number(insights.coverage.daysReported), total: format.number(insights.coverage.expectedDays) }) }}
                </p>
                <ul v-if="insights.coverage.notes.length > 0" class="mt-2 grid list-disc gap-1 ps-5 text-[0.8125rem] text-ink-muted">
                    <li v-for="note in insights.coverage.notes" :key="note">{{ t(`socialInsights.coverage.notes.${note}`) }}</li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a
                        v-for="dataset in (['daily', 'posts', 'campaigns'] as const)"
                        :key="dataset"
                        :href="exportUrl(dataset)"
                        class="inline-flex h-8 items-center gap-1.5 rounded-control px-3 text-[0.8125rem] font-medium text-ink-muted hover:bg-hover hover:text-ink"
                        :data-testid="`export-${dataset}`"
                    >
                        <Download class="size-3.5" aria-hidden="true" />
                        {{ t(`socialInsights.export.${dataset}`) }}
                    </a>
                </div>
            </Card>
        </template>
    </section>
</template>
