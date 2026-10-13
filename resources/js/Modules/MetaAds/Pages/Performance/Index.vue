<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Download, Megaphone } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import MetaStatusBadge from '../../Components/MetaStatusBadge.vue';
import ReadinessChecklist from '../../Components/ReadinessChecklist.vue';
import TrendChart from '../../Components/TrendChart.vue';
import type { CampaignPerformance, Range, ReadinessItem, SeriesPoint, Summary, Totals } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    range: Range;
    currencies: string[];
    currency: string | null;
    accountId: string | null;
    accounts: { id: string; name: string; externalId: string; currency: string }[];
    canExport: boolean;
    canSetup: boolean;
    readiness: ReadinessItem[];
    summary: Summary | null;
    series: SeriesPoint[];
    campaigns: CampaignPerformance[];
    syncedAt: string | null;
}>();

const { t } = useI18n();
const format = useFormat();

const from = ref(props.range.from);
const to = ref(props.range.to);
const currency = ref<string>(props.currency ?? '');
const account = ref<string>(props.accountId ?? '');
const invalid = computed(() => from.value === '' || to.value === '' || to.value < from.value);

const accountOptions = computed(() => [
    { value: '', label: t('metaAds.filters.allAccounts') },
    ...props.accounts.filter((item) => currency.value === '' || item.currency === currency.value).map((item) => ({ value: item.id, label: `${item.name} (${item.currency})` })),
]);

// A file download, not a page visit: a plain link, never the client router.
const downloadClass = 'inline-flex h-8 items-center gap-1.5 rounded-control border border-line-strong bg-control px-2.5 text-[0.8125rem] font-medium text-ink transition-colors hover:bg-control-hover';

const query = computed(() => ({
    from: from.value,
    to: to.value,
    ...(currency.value ? { currency: currency.value } : {}),
    ...(account.value ? { account: account.value } : {}),
}));

function apply(): void {
    router.get(route('meta_ads.performance.index'), query.value, { preserveState: true, replace: true });
}

function exportUrl(kind: 'campaigns' | 'daily'): string {
    return `${route('meta_ads.performance.export')}?${new URLSearchParams({ ...query.value, kind }).toString()}`;
}

/** A figure, or the words "not reported". A figure Meta did not return is never shown as zero. */
function count(value: number | null): string {
    return value === null ? t('metaAds.notReported') : format.number(value);
}

function money(value: number | null): string {
    return value === null || !props.currency ? t('metaAds.notReported') : format.money(value, props.currency);
}

function percent(basisPoints: number | null): string {
    return basisPoints === null ? t('metaAds.notReported') : `${format.number(basisPoints / 100, { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%`;
}

function delta(key: keyof Totals): string | undefined {
    const value = props.summary?.deltas[key] ?? null;

    if (value === null) {
        return t('metaAds.kpi.noComparison');
    }

    const text = `${value > 0 ? '+' : ''}${format.number(value / 100, { maximumFractionDigits: 1 })}%`;

    return t('metaAds.kpi.vsPrevious', { change: text });
}

const tiles = computed(() => {
    const totals = props.summary?.current;

    if (!totals) {
        return [];
    }

    return [
        { key: 'spend', value: money(totals.spend), accent: true },
        { key: 'leads', value: count(totals.leads) },
        { key: 'costPerLead', value: money(totals.costPerLead) },
        { key: 'impressions', value: count(totals.impressions) },
        { key: 'reach', value: count(totals.reach) },
        { key: 'clicks', value: count(totals.clicks) },
        { key: 'ctr', value: percent(totals.ctr) },
        { key: 'cpc', value: money(totals.cpc) },
        { key: 'cpm', value: money(totals.cpm) },
    ] as { key: keyof Totals; value: string; accent?: boolean }[];
});

const spendPoints = computed(() => props.series.map((point) => ({ date: point.date, value: point.spend })));
const leadPoints = computed(() => props.series.map((point) => ({ date: point.date, value: point.leads })));
const hasData = computed(() => props.summary !== null && props.series.some((point) => point.spend !== null || point.impressions !== null));
</script>

<template>
    <PageHeader :eyebrow="t('metaAds.eyebrow')" :title="t('metaAds.performance.title')" :description="t('metaAds.performance.description')" />

    <template v-if="currency === null">
        <EmptyState :title="t('metaAds.performance.emptyTitle')" :description="t('metaAds.performance.emptyBody')" data-testid="not-ready">
            <template #icon><Megaphone /></template>
            <Button v-if="canSetup" variant="primary" :href="route('meta_ads.accounts.index')">{{ t('metaAds.performance.openSetup') }}</Button>
        </EmptyState>
        <Card class="mt-3" :title="t('metaAds.readiness.title')" :description="t('metaAds.readiness.description')">
            <ReadinessChecklist :items="readiness" />
        </Card>
    </template>

    <template v-else>
        <Card class="mb-3">
            <form class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="apply">
                <Input v-model="from" type="date" :label="t('metaAds.filters.from')" class="w-full sm:w-40" />
                <Input v-model="to" type="date" :label="t('metaAds.filters.to')" class="w-full sm:w-40" />
                <Select
                    v-if="currencies.length > 1"
                    v-model="currency"
                    :label="t('metaAds.filters.currency')"
                    :options="currencies.map((code) => ({ value: code, label: code }))"
                    class="w-full sm:w-32"
                />
                <Select v-if="accounts.length > 1" v-model="account" :label="t('metaAds.filters.account')" :options="accountOptions" class="w-full sm:w-56" />
                <Button type="submit" size="sm" :disabled="invalid">{{ t('metaAds.filters.apply') }}</Button>
                <div v-if="canExport" class="flex flex-wrap gap-2 sm:ml-auto">
                    <a :href="exportUrl('campaigns')" :class="downloadClass" data-testid="export-campaigns" download>
                        <Download class="size-3.5" aria-hidden="true" />
                        {{ t('metaAds.export.campaigns') }}
                    </a>
                    <a :href="exportUrl('daily')" :class="downloadClass" data-testid="export-daily" download>
                        <Download class="size-3.5" aria-hidden="true" />
                        {{ t('metaAds.export.daily') }}
                    </a>
                </div>
            </form>
            <p class="mt-3 text-xs text-ink-subtle">
                {{ t('metaAds.performance.basis', { currency: currency!, days: format.number(range.days) }) }}
                <template v-if="syncedAt"> {{ t('metaAds.performance.syncedAt', { when: format.date(syncedAt, 'datetime') }) }}</template>
                <template v-else> {{ t('metaAds.performance.notSynced') }}</template>
            </p>
        </Card>

        <EmptyState v-if="!hasData" :title="t('metaAds.performance.noDataTitle')" :description="t('metaAds.performance.noDataBody')" data-testid="no-data">
            <template #icon><Megaphone /></template>
        </EmptyState>

        <template v-else>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="kpis">
                <StatTile v-for="tile in tiles" :key="tile.key" :label="t(`metaAds.kpi.${tile.key}`)" :value="tile.value" :hint="delta(tile.key)" :accent="tile.accent" />
            </div>
            <p class="mt-2 text-xs text-ink-subtle">{{ t('metaAds.kpi.reachNote') }}</p>

            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                <Card :title="t('metaAds.charts.spend')" :description="t('metaAds.charts.spendHint', { currency: currency! })">
                    <TrendChart :title="t('metaAds.charts.spend')" :points="spendPoints" kind="line" :format-value="(value) => format.money(value, currency!)" />
                </Card>
                <Card :title="t('metaAds.charts.leads')" :description="t('metaAds.charts.leadsHint')">
                    <TrendChart :title="t('metaAds.charts.leads')" :points="leadPoints" kind="bar" :format-value="(value) => format.number(value)" />
                </Card>
            </div>

            <Card class="mt-3" :title="t('metaAds.top.title')" :description="t('metaAds.top.description')" flush>
                <div v-if="campaigns.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-sm" data-testid="top-campaigns">
                        <caption class="sr-only">{{ t('metaAds.top.title') }}</caption>
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('metaAds.fields.campaign') }}</th>
                                <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.spend') }}</th>
                                <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.leads') }}</th>
                                <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.costPerLead') }}</th>
                                <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.clicks') }}</th>
                                <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.ctr') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in campaigns" :key="`${row.accountId}-${row.campaignId}`" class="border-b border-line last:border-b-0">
                                <th scope="row" class="max-w-xs px-5 py-2 font-normal">
                                    <span class="block truncate text-ink">{{ row.name ?? t('metaAds.top.unknownCampaign', { id: row.campaignId }) }}</span>
                                    <span class="flex items-center gap-2 text-xs text-ink-subtle">
                                        {{ row.accountName }}
                                        <MetaStatusBadge v-if="row.status" :status="row.status" />
                                    </span>
                                </th>
                                <td class="tabular px-5 py-2 text-right text-ink">{{ money(row.spend) }}</td>
                                <td class="tabular px-5 py-2 text-right text-ink-muted">{{ count(row.leads) }}</td>
                                <td class="tabular px-5 py-2 text-right text-ink-muted">{{ money(row.costPerLead) }}</td>
                                <td class="tabular px-5 py-2 text-right text-ink-muted">{{ count(row.clicks) }}</td>
                                <td class="tabular px-5 py-2 text-right text-ink-muted">{{ percent(row.ctr) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else :title="t('metaAds.top.empty')" compact />
                <p class="border-t border-line px-5 py-3 text-xs text-ink-subtle">
                    <Link :href="route('meta_ads.campaigns.index')" class="text-brand-text hover:underline">{{ t('metaAds.top.all') }}</Link>
                    · {{ t('metaAds.top.leadNote') }}
                </p>
            </Card>
        </template>
    </template>
</template>
