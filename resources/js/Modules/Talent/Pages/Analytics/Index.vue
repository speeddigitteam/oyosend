<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BarMeter from '../../Components/BarMeter.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { CycleCompletion, Recruitment, TrainingStats, Workforce } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    from: string;
    to: string;
    recruitment: Recruitment;
    workforce: Workforce;
    reviews: CycleCompletion[];
    training: TrainingStats;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const from = ref(props.from);
const to = ref(props.to);

const invalidRange = computed(() => from.value !== '' && to.value !== '' && to.value < from.value);

const recruitmentTiles = computed(() => [
    { key: 'applications', label: t('talent.analytics.applications'), value: format.number(props.recruitment.applications.total) },
    { key: 'hired', label: t('talent.analytics.hired'), value: format.number(props.recruitment.applications.hired) },
    { key: 'hireRate', label: t('talent.analytics.hireRate'), value: talent.percent(props.recruitment.applications.hireRateBp) },
    { key: 'timeToFill', label: t('talent.analytics.timeToFill'), value: talent.days(props.recruitment.timeToFillDays) },
    { key: 'timeToHire', label: t('talent.analytics.timeToHire'), value: talent.days(props.recruitment.timeToHireDays) },
    { key: 'openOpenings', label: t('talent.analytics.openOpenings'), value: format.number(props.recruitment.openings.open) },
]);

const funnelColumns = computed<Column[]>(() => [
    { key: 'stage', label: t('talent.fields.stage') },
    { key: 'reached', label: t('talent.analytics.reached'), align: 'right' },
    { key: 'share', label: t('talent.analytics.shareOfFirst') },
]);
const funnelRows = computed(() => props.recruitment.funnel.map((step, index) => ({ ...step, key: `${index}-${step.stage}` })));

const monthColumns = computed<Column[]>(() => [
    { key: 'month', label: t('talent.analytics.month') },
    { key: 'headcount', label: t('talent.analytics.headcount'), align: 'right' },
    { key: 'joiners', label: t('talent.analytics.joiners'), align: 'right' },
    { key: 'leavers', label: t('talent.analytics.leavers'), align: 'right' },
    { key: 'attritionBp', label: t('talent.analytics.attrition'), align: 'right' },
]);

const reviewColumns = computed<Column[]>(() => [
    { key: 'name', label: t('talent.fields.cycle') },
    { key: 'status', label: t('talent.fields.status') },
    { key: 'total', label: t('talent.cycles.reviews'), align: 'right' },
    { key: 'completion', label: t('talent.cycles.completion') },
]);

function monthLabel(value: string): string {
    const [year, month] = value.split('-').map(Number) as [number, number];

    return format.date(new Date(Date.UTC(year, month - 1, 1)), 'short');
}

function shareOfFirst(reached: number): number {
    const first = props.recruitment.funnel[0]?.reached ?? 0;

    return first === 0 ? 0 : Math.min(10_000, Math.floor((reached * 10_000) / first));
}

function apply(): void {
    router.get(route('talent.analytics.index'), { from: from.value || undefined, to: to.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.analytics.title')" :description="t('talent.analytics.description')" />

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <Input v-model="from" type="date" :label="t('talent.fields.from')" class="w-full sm:w-40" />
            <Input v-model="to" type="date" :label="t('talent.fields.to')" class="w-full sm:w-40" :error="invalidRange ? t('talent.analytics.rangeInvalid') : undefined" />
            <Button type="submit" size="sm" :disabled="invalidRange">{{ t('talent.common.apply') }}</Button>
        </form>
        <p class="mt-2 text-xs text-ink-subtle">{{ t('talent.analytics.periodHint', { from: format.date(from), to: format.date(to) }) }}</p>
    </Card>

    <h2 class="eyebrow mb-2">{{ t('talent.analytics.recruitment') }}</h2>
    <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6" data-testid="recruitment-tiles">
        <StatTile v-for="tile in recruitmentTiles" :key="tile.key" :label="tile.label" :value="tile.value" />
    </div>

    <div class="mb-6 grid gap-3 lg:grid-cols-2">
        <Card flush :title="t('talent.analytics.funnel')" :description="t('talent.analytics.funnelHint')">
            <DataTable :columns="funnelColumns" :rows="funnelRows" row-key="key" :caption="t('talent.analytics.funnel')">
                <template #cell-stage="{ row }">
                    <span class="text-ink">{{ row.stage }}</span>
                    <span class="block text-xs text-ink-subtle">{{ t(`talent.status.stageType.${row.type}`) }}</span>
                </template>
                <template #cell-reached="{ row }"><span class="tabular text-ink">{{ format.number(row.reached) }}</span></template>
                <template #cell-share="{ row }">
                    <BarMeter :bp="shareOfFirst(row.reached)" :label="t('talent.analytics.funnelStep', { stage: row.stage })" compact />
                </template>
                <template #empty><EmptyState :title="t('talent.analytics.noFunnel')" compact /></template>
            </DataTable>
        </Card>

        <Card :title="t('talent.analytics.outcomes')">
            <dl class="grid grid-cols-2 gap-x-5 gap-y-4 text-sm">
                <div v-for="key in ['open', 'hired', 'rejected', 'withdrawn'] as const" :key="key">
                    <dt class="eyebrow">{{ t(`talent.status.application.${key}`) }}</dt>
                    <dd class="figure tabular mt-2 text-2xl text-ink">{{ format.number(recruitment.applications[key]) }}</dd>
                </div>
                <div v-for="key in ['draft', 'open', 'onHold', 'closed'] as const" :key="`o-${key}`">
                    <dt class="eyebrow">{{ t(`talent.analytics.openings.${key}`) }}</dt>
                    <dd class="figure tabular mt-2 text-2xl text-ink">{{ format.number(recruitment.openings[key]) }}</dd>
                </div>
            </dl>
        </Card>
    </div>

    <h2 class="eyebrow mb-2">{{ t('talent.analytics.workforce') }}</h2>
    <div class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <StatTile :label="t('talent.analytics.headcount')" :value="format.number(workforce.headcount)" />
        <StatTile :label="t('talent.analytics.leavers')" :value="format.number(workforce.leavers)" />
        <StatTile :label="t('talent.analytics.attritionRate')" :value="talent.percent(workforce.attritionBp)" :hint="t('talent.analytics.attritionHint')" />
    </div>
    <Card flush class="mb-6" :title="t('talent.analytics.monthly')">
        <DataTable :columns="monthColumns" :rows="workforce.months" row-key="month" :caption="t('talent.analytics.monthly')">
            <template #cell-month="{ row }"><span class="text-ink">{{ monthLabel(row.month) }}</span></template>
            <template #cell-headcount="{ row }"><span class="tabular text-ink">{{ format.number(row.headcount) }}</span></template>
            <template #cell-joiners="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.joiners) }}</span></template>
            <template #cell-leavers="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.leavers) }}</span></template>
            <template #cell-attritionBp="{ row }"><span class="tabular text-ink">{{ talent.percent(row.attritionBp) }}</span></template>
            <template #empty><EmptyState :title="t('talent.analytics.noMonths')" compact /></template>
        </DataTable>
    </Card>

    <div class="grid gap-3 lg:grid-cols-2">
        <div>
            <h2 class="eyebrow mb-2">{{ t('talent.analytics.performance') }}</h2>
            <Card flush>
                <DataTable :columns="reviewColumns" :rows="reviews" row-key="id" :caption="t('talent.analytics.performance')">
                    <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                    <template #cell-status="{ row }"><TalentBadge kind="cycle" :value="row.status" /></template>
                    <template #cell-total="{ row }"><span class="tabular text-ink">{{ format.number(row.total) }}</span></template>
                    <template #cell-completion="{ row }">
                        <BarMeter :bp="row.completionBp" :label="t('talent.cycles.completionOf', { name: row.name })" compact />
                    </template>
                    <template #empty><EmptyState :title="t('talent.analytics.noCycles')" compact /></template>
                </DataTable>
            </Card>
        </div>

        <div>
            <h2 class="eyebrow mb-2">{{ t('talent.analytics.training') }}</h2>
            <Card>
                <dl class="grid grid-cols-2 gap-x-5 gap-y-4 text-sm" data-testid="training-stats">
                    <div>
                        <dt class="eyebrow">{{ t('talent.analytics.completionRate') }}</dt>
                        <dd class="figure tabular mt-2 text-2xl text-ink">{{ talent.percent(training.completionRateBp) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.analytics.activeCourses') }}</dt>
                        <dd class="figure tabular mt-2 text-2xl text-ink">{{ format.number(training.courses) }}</dd>
                    </div>
                    <div v-for="key in ['enrolled', 'completed', 'failed', 'cancelled'] as const" :key="key">
                        <dt class="eyebrow">{{ t(`talent.status.enrolment.${key}`) }}</dt>
                        <dd class="figure tabular mt-2 text-xl text-ink">{{ format.number(training.enrolments[key]) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.analytics.certificatesExpiring') }}</dt>
                        <dd class="figure tabular mt-2 text-xl" :class="training.certificatesExpiring > 0 ? 'text-warn' : 'text-ink'">{{ format.number(training.certificatesExpiring) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.analytics.certificatesExpired') }}</dt>
                        <dd class="figure tabular mt-2 text-xl" :class="training.certificatesExpired > 0 ? 'text-danger' : 'text-ink'">{{ format.number(training.certificatesExpired) }}</dd>
                    </div>
                </dl>
            </Card>
        </div>
    </div>
</template>
