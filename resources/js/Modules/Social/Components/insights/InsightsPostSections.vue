<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { safeUrl } from '../socialFormat';
import BarChart from './BarChart.vue';
import type { InsightPostRow, InsightsDashboard, PostFigures } from './types';

/** The parts of the dashboard that come from our own published posts and their latest reported figures. */
const props = defineProps<{ posts: InsightsDashboard['posts']; periodDays: number }>();

const { t } = useI18n();
const format = useFormat();

const FIGURES = ['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'] as const;

const best = computed(() => props.posts.bestTimes);
const hourItems = computed(() => best.value.byHour.map((item) => ({ key: String(item.hour), label: format.number(item.hour), value: item.average, posts: item.posts })));
const weekdayItems = computed(() => best.value.byWeekday.map((item) => ({ key: String(item.weekday), label: t(`socialInsights.weekdays.${item.weekday}`), value: item.average, posts: item.posts })));

function topKeys(items: { key: string; value: number | null }[]): string[] {
    const peak = Math.max(0, ...items.map((item) => item.value ?? 0));

    return peak > 0 ? items.filter((item) => item.value === peak).map((item) => item.key) : [];
}

const highlightHours = computed(() => (best.value.sufficient ? topKeys(hourItems.value) : []));
const highlightWeekdays = computed(() => (best.value.sufficient ? topKeys(weekdayItems.value) : []));

function figure(value: number | null | undefined): string {
    return value === null || value === undefined ? t('socialInsights.notReported') : format.number(value);
}

function figures(row: PostFigures, key: (typeof FIGURES)[number]): string {
    return figure(row[key]);
}

function slot(slotRow: { weekday: number; hour: number }): string {
    return t('socialInsights.best.slot', { weekday: t(`socialInsights.weekdays.${slotRow.weekday}`), hour: format.number(slotRow.hour) });
}
</script>

<template>
    <Card class="mt-3" :title="t('socialInsights.best.title')" :description="t('socialInsights.best.hint', { days: format.number(best.windowDays), timezone: best.timezone })" data-testid="best-times">
        <p v-if="best.posts === 0" class="text-[0.8125rem] text-ink-muted" data-testid="best-empty">{{ t('socialInsights.best.empty') }}</p>
        <template v-else>
            <p class="mb-3 text-[0.8125rem] text-ink-muted" role="status">
                {{ best.sufficient
                    ? t('socialInsights.best.sample', { count: format.number(best.posts) })
                    : t('socialInsights.best.small', { count: format.number(best.posts), minimum: format.number(best.minimum) }) }}
            </p>
            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm">{{ t('socialInsights.best.byHour') }}</h3>
                    <BarChart :label="t('socialInsights.best.byHour')" :items="hourItems" :highlight="highlightHours" />
                </div>
                <div>
                    <h3 class="mb-2 text-sm">{{ t('socialInsights.best.byWeekday') }}</h3>
                    <BarChart :label="t('socialInsights.best.byWeekday')" :items="weekdayItems" :highlight="highlightWeekdays" />
                </div>
            </div>
            <div v-if="best.slots.length > 0" class="mt-4">
                <h3 class="mb-1 text-sm">{{ t('socialInsights.best.slots') }}</h3>
                <ul class="grid gap-1 text-[0.8125rem] text-ink-muted">
                    <li v-for="row in best.slots" :key="`${row.weekday}-${row.hour}`">
                        <span class="text-ink">{{ slot(row) }}</span>
                        · {{ t('socialInsights.best.slotFigures', { average: format.number(row.average, { maximumFractionDigits: 1 }), posts: format.number(row.posts) }) }}
                    </li>
                </ul>
            </div>
        </template>
    </Card>

    <div class="mt-3 grid gap-3 lg:grid-cols-2">
        <template v-for="group in ([['top', posts.top], ['bottom', posts.bottom]] as [string, InsightPostRow[]][])" :key="group[0]">
            <Card :title="t(`socialInsights.posts.${group[0]}`)" :description="t('socialInsights.posts.hint', { days: format.number(periodDays) })" flush :data-testid="`posts-${group[0]}`">
                <p v-if="group[1].length === 0" class="px-5 pb-4 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.posts.empty') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ t(`socialInsights.posts.${group[0]}`) }}</caption>
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('social.fields.post') }}</th>
                                <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.posts.engagement') }}</th>
                                <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.posts.reach') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in group[1]" :key="row.targetId" class="border-b border-line last:border-b-0">
                                <th scope="row" class="max-w-[16rem] px-5 py-2 font-normal">
                                    <Link :href="route('social.posts.show', { post: row.postId })" class="block truncate text-ink hover:text-brand-text">{{ row.title ?? t('social.common.untitled') }}</Link>
                                    <span class="block truncate text-xs text-ink-subtle">{{ row.accountName }} · {{ format.date(row.publishedAt, 'date') }}</span>
                                    <a v-if="safeUrl(row.url)" :href="safeUrl(row.url)!" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-text hover:underline">{{ t('social.show.viewOnPlatform') }}</a>
                                </th>
                                <td class="tabular px-4 py-2 text-right text-ink">{{ format.number(row.engagement) }}</td>
                                <td class="tabular px-4 py-2 text-right text-ink-muted">{{ figures(row.figures, 'reach') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </template>
    </div>

    <Card class="mt-3" :title="t('socialInsights.types.title')" :description="t('socialInsights.types.hint')" flush data-testid="content-types">
        <p v-if="posts.contentTypes.length === 0" class="px-5 pb-4 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.posts.empty') }}</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ t('socialInsights.types.title') }}</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('socialInsights.types.type') }}</th>
                        <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.types.posts') }}</th>
                        <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.types.reported') }}</th>
                        <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.types.average') }}</th>
                        <th v-for="key in FIGURES" :key="key" scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t(`social.analytics.figures.${key}`) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in posts.contentTypes" :key="row.type" class="border-b border-line last:border-b-0">
                        <th scope="row" class="px-5 py-2 font-normal text-ink">{{ t(`socialInsights.types.names.${row.type}`) }}</th>
                        <td class="tabular px-4 py-2 text-right text-ink-muted">{{ format.number(row.posts) }}</td>
                        <td class="tabular px-4 py-2 text-right text-ink-muted">{{ format.number(row.reported) }}</td>
                        <td class="tabular px-4 py-2 text-right text-ink">{{ row.average === null ? t('socialInsights.notReported') : format.number(row.average, { maximumFractionDigits: 1 }) }}</td>
                        <td v-for="key in FIGURES" :key="key" class="tabular px-4 py-2 text-right text-ink-muted">{{ figures(row.totals, key) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>

    <Card class="mt-3" :title="t('socialInsights.campaigns.title')" :description="t('socialInsights.campaigns.hint')" flush data-testid="campaigns">
        <p v-if="posts.campaigns.length === 0" class="px-5 pb-4 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.posts.empty') }}</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ t('socialInsights.campaigns.title') }}</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('socialInsights.campaigns.campaign') }}</th>
                        <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.types.posts') }}</th>
                        <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.types.reported') }}</th>
                        <th scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t('socialInsights.posts.engagement') }}</th>
                        <th v-for="key in FIGURES" :key="key" scope="col" class="eyebrow px-4 py-2 text-right font-normal">{{ t(`social.analytics.figures.${key}`) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in posts.campaigns" :key="row.id ?? 'none'" class="border-b border-line align-top last:border-b-0">
                        <th scope="row" class="px-5 py-2 font-normal text-ink">
                            {{ row.name ?? t('socialInsights.campaigns.none') }}
                            <details v-if="row.timeline.length > 0" class="mt-1 text-xs text-ink-muted">
                                <summary class="cursor-pointer text-brand-text">{{ t('socialInsights.campaigns.timeline') }}</summary>
                                <ul class="mt-1 grid gap-0.5">
                                    <li v-for="day in row.timeline" :key="day.date">
                                        {{ format.date(`${day.date}T12:00:00Z`, 'date') }}:
                                        {{ t('socialInsights.campaigns.day', { posts: format.number(day.posts), engagement: figure(day.engagement) }) }}
                                    </li>
                                </ul>
                            </details>
                        </th>
                        <td class="tabular px-4 py-2 text-right text-ink-muted">{{ format.number(row.posts) }}</td>
                        <td class="tabular px-4 py-2 text-right text-ink-muted">{{ format.number(row.reported) }}</td>
                        <td class="tabular px-4 py-2 text-right text-ink">{{ figure(row.engagement) }}</td>
                        <td v-for="key in FIGURES" :key="key" class="tabular px-4 py-2 text-right text-ink-muted">{{ figures(row.totals, key) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
