<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChartNoAxesCombined } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SentimentSummary from '../../Components/SentimentSummary.vue';
import { FIGURES, isReported, safeUrl } from '../../Components/socialFormat';
import PageInsightsPanel from '../../Components/insights/PageInsightsPanel.vue';
import type { InsightsDashboard } from '../../Components/insights/types';
import type { AnalyticsReport, EngagementFigures } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    report: AnalyticsReport;
    insights?: InsightsDashboard;
    range: { from: string; to: string; timezone: string };
}>();

const { t, te } = useI18n();
const format = useFormat();

const from = ref(props.range.from);
const to = ref(props.range.to);
const rangeInvalid = computed(() => from.value === '' || to.value === '' || to.value < from.value);

const canReport = computed(() => props.report.platformsWithAnalytics.length > 0);
const hasFigures = computed(() => props.report.engagement.reportedPosts > 0);
const reporters = computed(() => props.report.platformsWithAnalytics
    .map((key) => (te(`social.platformNames.${key}`) ? t(`social.platformNames.${key}`) : key))
    .join(', '));

function apply(): void {
    router.get(route('social.analytics.index'), { from: from.value, to: to.value, days: props.insights?.period.days }, { preserveState: true, replace: true });
}

/** A number, or the words "not reported". A missing figure is never shown as zero. */
function figure(value: number | null | undefined): string {
    return isReported(value) ? format.number(value) : t('social.analytics.notReported');
}

function total(key: keyof EngagementFigures): string {
    return figure(props.report.engagement.totals[key]);
}
</script>

<template>
    <PageHeader
        :eyebrow="t('social.eyebrow')"
        :title="t('social.analytics.title')"
        :description="t('social.analytics.description', { timezone: range.timezone })"
    />

    <PageInsightsPanel v-if="insights" :insights="insights" :range="range" class="mb-8" />

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="apply">
            <Input v-model="from" type="date" :label="t('social.analytics.from')" class="w-full sm:w-44" />
            <Input v-model="to" type="date" :label="t('social.analytics.to')" class="w-full sm:w-44" />
            <Button type="submit" size="sm" :disabled="rangeInvalid">{{ t('social.common.apply') }}</Button>
        </form>
    </Card>

    <h2 class="mb-1 text-base">{{ t('social.analytics.publishing.title') }}</h2>
    <p class="mb-3 text-[0.8125rem] text-ink-muted">{{ t('social.analytics.publishing.hint') }}</p>

    <div class="grid gap-3 sm:grid-cols-3" data-testid="publishing">
        <StatTile :label="t('social.analytics.publishing.published')" :value="format.number(report.publishing.published)" />
        <StatTile :label="t('social.analytics.publishing.failed')" :value="format.number(report.publishing.failed)" :accent="report.publishing.failed > 0" />
        <StatTile :label="t('social.analytics.publishing.pending')" :value="format.number(report.publishing.pending)" />
    </div>

    <Card v-if="report.publishing.byPlatform.length > 0" class="mt-3" :title="t('social.analytics.byPlatform')" flush>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ t('social.analytics.byPlatform') }}</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('social.fields.platform') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('social.analytics.publishing.published') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('social.analytics.publishing.failed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in report.publishing.byPlatform" :key="row.platform" class="border-b border-line last:border-b-0">
                        <th scope="row" class="px-5 py-2 font-normal text-ink">{{ row.label }}</th>
                        <td class="tabular px-5 py-2 text-right text-ink-muted">{{ format.number(row.published) }}</td>
                        <td class="tabular px-5 py-2 text-right text-ink-muted">{{ format.number(row.failed) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>

    <h2 class="mt-8 mb-1 text-base">{{ t('social.analytics.engagement.title') }}</h2>
    <p class="mb-3 text-[0.8125rem] text-ink-muted">{{ t('social.analytics.engagement.hint') }}</p>

    <EmptyState
        v-if="!canReport"
        :title="t('social.analytics.engagement.noPlatformTitle')"
        :description="t('social.analytics.engagement.noPlatformBody')"
        data-testid="no-platform"
    >
        <template #icon><ChartNoAxesCombined /></template>
    </EmptyState>

    <EmptyState
        v-else-if="!hasFigures"
        :title="t('social.analytics.engagement.noFiguresTitle')"
        :description="t('social.analytics.engagement.noFiguresBody', { platforms: reporters })"
        data-testid="no-figures"
    >
        <template #icon><ChartNoAxesCombined /></template>
    </EmptyState>

    <template v-else>
        <p class="mb-3 text-[0.8125rem] text-ink-muted" data-testid="captured">
            {{ t('social.analytics.engagement.reported', { count: format.number(report.engagement.reportedPosts) }) }}
            <template v-if="report.engagement.capturedAt">
                {{ t('social.analytics.engagement.capturedAt', { when: format.date(report.engagement.capturedAt, 'datetime') }) }}
            </template>
        </p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="totals">
            <StatTile v-for="key in FIGURES" :key="key" :label="t(`social.analytics.figures.${key}`)" :value="total(key)" />
        </div>

        <Card v-if="report.engagement.byPlatform.length > 0" class="mt-3" :title="t('social.analytics.byPlatform')" flush>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ t('social.analytics.engagement.byPlatformCaption') }}</caption>
                    <thead>
                        <tr class="border-b border-line">
                            <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('social.fields.platform') }}</th>
                            <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('social.analytics.engagement.posts') }}</th>
                            <th v-for="key in FIGURES" :key="key" scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t(`social.analytics.figures.${key}`) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in report.engagement.byPlatform" :key="row.platform" class="border-b border-line last:border-b-0">
                            <th scope="row" class="px-5 py-2 font-normal text-ink">{{ row.label }}</th>
                            <td class="tabular px-5 py-2 text-right text-ink-muted">{{ format.number(row.posts) }}</td>
                            <td v-for="key in FIGURES" :key="key" class="tabular px-5 py-2 text-right text-ink-muted">{{ figure(row.totals[key]) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card v-if="report.engagement.topPosts.length > 0" class="mt-3" :title="t('social.analytics.engagement.topTitle')" :description="t('social.analytics.engagement.topHint')" flush>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ t('social.analytics.engagement.topTitle') }}</caption>
                    <thead>
                        <tr class="border-b border-line">
                            <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('social.fields.post') }}</th>
                            <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('social.fields.account') }}</th>
                            <th v-for="key in FIGURES" :key="key" scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t(`social.analytics.figures.${key}`) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in report.engagement.topPosts" :key="row.targetId" class="border-b border-line last:border-b-0">
                            <th scope="row" class="max-w-xs px-5 py-2 font-normal">
                                <Link :href="route('social.posts.show', { post: row.postId })" class="block truncate text-ink hover:text-brand-text">
                                    {{ row.title ?? t('social.common.untitled') }}
                                </Link>
                                <a
                                    v-if="safeUrl(row.url)"
                                    :href="safeUrl(row.url)!"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-xs text-brand-text hover:underline"
                                >{{ t('social.show.viewOnPlatform') }}</a>
                            </th>
                            <td class="px-5 py-2 text-ink-muted">{{ row.accountName }} <span class="text-xs text-ink-subtle">{{ row.platformLabel }}</span></td>
                            <td v-for="key in FIGURES" :key="key" class="tabular px-5 py-2 text-right text-ink-muted">{{ figure(row.figures[key]) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </template>

    <SentimentSummary :from="range.from" :to="range.to" />
</template>
