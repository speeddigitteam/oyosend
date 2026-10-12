<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Calculator, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import PrepareRunDialog from '../../Components/PrepareRunDialog.vue';
import type { Period, Run } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    runs: Paginated<Run>;
    filters: { status: string; kind: string; period_id: string };
    statuses: string[];
    kinds: string[];
    preparablePeriods: Period[];
    canPrepare: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status);
const kind = ref(props.filters.kind);
const loading = ref(false);
const preparing = ref(false);

const statusOptions = computed(() => [
    { value: '', label: t('payroll.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`payroll.status.run.${value}`) })),
]);
const kindOptions = computed(() => [
    { value: '', label: t('payroll.common.all') },
    ...props.kinds.map((value) => ({ value, label: t(`payroll.status.runKind.${value}`) })),
]);
const filtered = computed(() => status.value !== '' || kind.value !== '' || props.filters.period_id !== '');
const columns = computed<Column[]>(() => [
    { key: 'number', label: t('payroll.fields.number') },
    { key: 'period', label: t('payroll.fields.period') },
    { key: 'kind', label: t('payroll.fields.kind'), hideable: true },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'employees', label: t('payroll.fields.employees'), align: 'right', hideable: true },
    { key: 'net', label: t('payroll.fields.net'), align: 'right' },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('payroll.runs.index'), {
        status: status.value || undefined,
        kind: kind.value || undefined,
        period_id: props.filters.period_id || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    status.value = '';
    kind.value = '';
    router.get(route('payroll.runs.index'), {}, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.runs.title')" :description="t('payroll.runs.description')">
        <template v-if="canPrepare && preparablePeriods.length > 0" #actions>
            <Button variant="primary" @click="preparing = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.prepare') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="runs.data" row-key="id" :caption="t('payroll.runs.caption')" :loading="loading" storage-key="payroll-runs">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Select v-model="status" :options="statusOptions" :label="t('payroll.fields.status')" class="w-full sm:w-44" />
                    <Select v-model="kind" :options="kindOptions" :label="t('payroll.fields.kind')" class="w-full sm:w-44" />
                    <Button type="submit" size="sm">{{ t('payroll.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('payroll.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-number="{ row }">
                <Button variant="ghost" size="sm" :href="route('payroll.runs.show', { run: row.id })" class="font-mono">
                    {{ row.number ?? t('payroll.runs.draftNumber') }}
                </Button>
            </template>
            <template #cell-period="{ row }"><span class="text-ink">{{ row.periodName ?? t('payroll.common.dash') }}</span></template>
            <template #cell-kind="{ row }"><PayrollBadge kind="runKind" :value="row.kind" /></template>
            <template #cell-status="{ row }"><PayrollBadge kind="run" :value="row.status" /></template>
            <template #cell-employees="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.employeeCount) }}</span></template>
            <template #cell-net="{ row }">
                <span class="tabular font-mono">{{ format.money(row.kind === 'reversal' ? -row.netAmount : row.netAmount, row.currency) }}</span>
            </template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('payroll.runs.show', { run: row.id })">{{ t('payroll.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="filtered ? t('payroll.common.noMatches') : t('payroll.runs.empty')" :description="filtered ? t('payroll.common.noMatchesBody') : t(canPrepare ? 'payroll.runs.emptyBody' : 'payroll.runs.emptyViewOnly')">
                    <template #icon><Calculator /></template>
                    <Button v-if="canPrepare && !filtered && preparablePeriods.length > 0" variant="primary" @click="preparing = true">{{ t('payroll.runs.prepare') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="runs" />
    </Card>

    <PrepareRunDialog v-if="canPrepare" v-model:open="preparing" :periods="preparablePeriods" />
</template>
