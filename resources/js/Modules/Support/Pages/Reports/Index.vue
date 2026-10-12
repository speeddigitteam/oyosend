<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BarList from '../../Components/BarList.vue';
import SupportNav from '../../Components/SupportNav.vue';
import { useSupportFormat } from '../../Components/useSupportFormat';

defineOptions({ layout: TenantLayout });

interface SlaTarget {
    met: number;
    missed: number;
    pending: number;
    rateBps: number | null;
}

interface Report {
    range: { from: string; to: string; days: number };
    volume: { created: number; resolved: number; byDay: { date: string; created: number; resolved: number }[] };
    backlog: {
        total: number;
        unassigned: number;
        byStatus: Record<string, number>;
        byPriority: Record<string, number>;
        byQueue: { queueId: string | null; name: string | null; count: number }[];
        aging: Record<string, number>;
    };
    sla: { firstResponse: SlaTarget; resolution: SlaTarget };
    times: { firstResponseAverage: number | null; firstResponseMedian: number | null; resolutionAverage: number | null; resolutionMedian: number | null };
    csat: {
        requested: number;
        responses: number;
        responseRateBps: number | null;
        averageHundredths: number | null;
        satisfiedBps: number | null;
        distribution: Record<string, number>;
    };
}

const props = defineProps<{
    report: Report;
    filters: { from: string; to: string; queueId: string | null };
    queues: { id: string; name: string; assignmentMode: string }[];
    maxDays: number;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string | undefined>);
const { duration, percent, rating } = useSupportFormat();

const draft = reactive({ from: props.filters.from, to: props.filters.to, queue_id: props.filters.queueId ?? '' });
const queueOptions = computed(() => [{ value: '', label: t('support.filters.anyQueue') }, ...props.queues.map((queue) => ({ value: queue.id, label: queue.name }))]);

function apply(): void {
    const query: Record<string, string> = { from: draft.from, to: draft.to };

    if (draft.queue_id !== '') {
        query.queue_id = draft.queue_id;
    }

    router.get(route('support.reports.index'), query, { preserveState: true });
}

const statusItems = computed(() => Object.entries(props.report.backlog.byStatus).map(([key, value]) => ({ key, label: t(`support.status.${key}`), value })));
const priorityItems = computed(() => Object.entries(props.report.backlog.byPriority).map(([key, value]) => ({ key, label: t(`support.priority.${key}`), value })));
const queueItems = computed(() =>
    props.report.backlog.byQueue.map((row) => ({ key: row.queueId ?? 'none', label: row.name ?? t('support.reports.noQueue'), value: row.count })),
);
const agingItems = computed(() => Object.entries(props.report.backlog.aging).map(([key, value]) => ({ key, label: t(`support.reports.aging.${key}`), value })));
const ratingItems = computed(() =>
    [5, 4, 3, 2, 1].map((score) => ({ key: String(score), label: t('support.reports.stars', { count: format.number(score) }), value: props.report.csat.distribution[String(score)] ?? 0 })),
);

const volumeColumns = computed<Column[]>(() => [
    { key: 'date', label: t('support.reports.date') },
    { key: 'created', label: t('support.reports.created'), align: 'right' },
    { key: 'resolved', label: t('support.reports.resolved'), align: 'right' },
]);
const slaRows = computed(() => [
    { key: 'firstResponse', label: t('support.sla.firstResponse'), ...props.report.sla.firstResponse },
    { key: 'resolution', label: t('support.sla.resolution'), ...props.report.sla.resolution },
]);
const slaColumns = computed<Column[]>(() => [
    { key: 'label', label: t('support.reports.target') },
    { key: 'met', label: t('support.sla.state.met'), align: 'right' },
    { key: 'missed', label: t('support.sla.state.missed'), align: 'right' },
    { key: 'pending', label: t('support.reports.pending'), align: 'right' },
    { key: 'rate', label: t('support.reports.attainment'), align: 'right' },
]);
const timeRows = computed(() => [
    { key: 'first', label: t('support.sla.firstResponse'), average: props.report.times.firstResponseAverage, median: props.report.times.firstResponseMedian },
    { key: 'resolution', label: t('support.sla.resolution'), average: props.report.times.resolutionAverage, median: props.report.times.resolutionMedian },
]);
const timeColumns = computed<Column[]>(() => [
    { key: 'label', label: t('support.reports.target') },
    { key: 'average', label: t('support.reports.average'), align: 'right' },
    { key: 'median', label: t('support.reports.median'), align: 'right' },
]);
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.reports.title')" :description="t('support.reports.description', { days: format.number(maxDays) })" />
    <SupportNav current="reports" />

    <Card class="mb-3">
        <form class="grid gap-3 sm:grid-cols-4" @submit.prevent="apply">
            <Input v-model="draft.from" type="date" :label="t('support.reports.from')" :error="errors.from" required />
            <Input v-model="draft.to" type="date" :label="t('support.reports.to')" :error="errors.to" required />
            <Select v-model="draft.queue_id" :options="queueOptions" :label="t('support.fields.queue')" />
            <div class="flex items-end"><Button type="submit" variant="primary">{{ t('support.reports.run') }}</Button></div>
        </form>
        <p class="tabular mt-3 text-xs text-ink-subtle">
            {{ t('support.reports.showing', { from: format.date(report.range.from, 'date'), to: format.date(report.range.to, 'date'), days: format.number(report.range.days) }) }}
        </p>
    </Card>

    <h2 class="mb-2 text-lg font-semibold text-ink">{{ t('support.reports.volume') }}</h2>
    <div class="mb-6 grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 sm:grid-cols-2 lg:grid-cols-1">
            <StatTile :label="t('support.reports.created')" :value="format.number(report.volume.created)" />
            <StatTile :label="t('support.reports.resolved')" :value="format.number(report.volume.resolved)" />
        </div>
        <Card class="lg:col-span-2" :title="t('support.reports.perDay')" flush>
            <div class="max-h-96 overflow-auto">
                <DataTable :columns="volumeColumns" :rows="report.volume.byDay" row-key="date" :caption="t('support.reports.perDay')">
                    <template #cell-date="{ row }"><span class="tabular">{{ format.date(row.date, 'date') }}</span></template>
                    <template #cell-created="{ row }"><span class="tabular">{{ format.number(row.created) }}</span></template>
                    <template #cell-resolved="{ row }"><span class="tabular">{{ format.number(row.resolved) }}</span></template>
                </DataTable>
            </div>
        </Card>
    </div>

    <h2 class="mb-2 text-lg font-semibold text-ink">{{ t('support.reports.backlog') }}</h2>
    <div class="mb-6 grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 sm:grid-cols-2 lg:grid-cols-1">
            <StatTile :label="t('support.reports.openTickets')" :value="format.number(report.backlog.total)" />
            <StatTile :label="t('support.reports.unassigned')" :value="format.number(report.backlog.unassigned)" :accent="report.backlog.unassigned > 0" />
        </div>
        <Card :title="t('support.reports.byStatus')"><BarList :items="statusItems" :label="t('support.reports.byStatus')" /></Card>
        <Card :title="t('support.reports.byPriority')"><BarList :items="priorityItems" :label="t('support.reports.byPriority')" /></Card>
        <Card :title="t('support.reports.byQueue')">
            <BarList v-if="queueItems.length" :items="queueItems" :label="t('support.reports.byQueue')" />
            <p v-else class="text-sm text-ink-muted">{{ t('support.reports.noBacklog') }}</p>
        </Card>
        <Card :title="t('support.reports.agingTitle')"><BarList :items="agingItems" :label="t('support.reports.agingTitle')" /></Card>
    </div>

    <h2 class="mb-2 text-lg font-semibold text-ink">{{ t('support.reports.slaTitle') }}</h2>
    <div class="mb-6 grid gap-3 lg:grid-cols-2">
        <Card :title="t('support.reports.attainment')" :description="t('support.reports.slaHint')" flush>
            <DataTable :columns="slaColumns" :rows="slaRows" row-key="key" :caption="t('support.reports.attainment')">
                <template #cell-met="{ row }"><span class="tabular">{{ format.number(row.met) }}</span></template>
                <template #cell-missed="{ row }"><span class="tabular">{{ format.number(row.missed) }}</span></template>
                <template #cell-pending="{ row }"><span class="tabular">{{ format.number(row.pending) }}</span></template>
                <template #cell-rate="{ row }"><span class="tabular font-medium">{{ percent(row.rateBps) }}</span></template>
            </DataTable>
        </Card>
        <Card :title="t('support.reports.timesTitle')" :description="t('support.reports.timesHint')" flush>
            <DataTable :columns="timeColumns" :rows="timeRows" row-key="key" :caption="t('support.reports.timesTitle')">
                <template #cell-average="{ row }"><span class="tabular">{{ duration(row.average) }}</span></template>
                <template #cell-median="{ row }"><span class="tabular">{{ duration(row.median) }}</span></template>
            </DataTable>
        </Card>
    </div>

    <h2 class="mb-2 text-lg font-semibold text-ink">{{ t('support.reports.csatTitle') }}</h2>
    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 sm:grid-cols-2 lg:grid-cols-1">
            <StatTile :label="t('support.reports.csatAverage')" :value="rating(report.csat.averageHundredths)" :hint="t('support.reports.csatOutOf')" />
            <StatTile :label="t('support.reports.csatSatisfied')" :value="percent(report.csat.satisfiedBps)" :hint="t('support.reports.csatSatisfiedHint')" />
        </div>
        <Card :title="t('support.reports.csatResponses')">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="eyebrow">{{ t('support.reports.csatRequested') }}</dt>
                    <dd class="figure mt-1 text-2xl text-ink">{{ format.number(report.csat.requested) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('support.reports.csatResponded') }}</dt>
                    <dd class="figure mt-1 text-2xl text-ink">{{ format.number(report.csat.responses) }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="eyebrow">{{ t('support.reports.csatRate') }}</dt>
                    <dd class="figure mt-1 text-2xl text-ink">{{ percent(report.csat.responseRateBps) }}</dd>
                </div>
            </dl>
        </Card>
        <Card :title="t('support.reports.csatDistribution')"><BarList :items="ratingItems" :label="t('support.reports.csatDistribution')" /></Card>
    </div>
</template>
