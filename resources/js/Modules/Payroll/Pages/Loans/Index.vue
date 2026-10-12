<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { HandCoins, Plus } from 'lucide-vue-next';
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
import LoanDialog from '../../Components/LoanDialog.vue';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { EmployeeOption, LoanRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    loans: Paginated<LoanRow>;
    filters: { status: string; employee_id: string };
    statuses: string[];
    kinds: string[];
    employees: EmployeeOption[];
    currency: string;
    today: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const status = ref(props.filters.status);
const loading = ref(false);
const creating = ref(false);

const statusOptions = computed(() => [{ value: '', label: t('payroll.common.all') }, ...props.statuses.map((value) => ({ value, label: t(`payroll.status.loan.${value}`) }))]);
const filtered = computed(() => status.value !== '' || props.filters.employee_id !== '');
const columns = computed<Column[]>(() => [
    { key: 'title', label: t('payroll.fields.title') },
    { key: 'employee', label: t('payroll.fields.employee'), hideable: true },
    { key: 'principal', label: t('payroll.fields.principal'), align: 'right' },
    { key: 'installments', label: t('payroll.fields.installments'), align: 'right', hideable: true },
    { key: 'firstDue', label: t('payroll.fields.firstDue'), hideable: true },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('payroll.loans.index'), {
        status: status.value || undefined,
        employee_id: props.filters.employee_id || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    status.value = '';
    router.get(route('payroll.loans.index'), {}, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.loans.title')" :description="t('payroll.loans.description')">
        <template v-if="canManage && employees.length > 0" #actions>
            <Button variant="primary" @click="creating = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.loans.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="loans.data" row-key="id" :caption="t('payroll.loans.caption')" :loading="loading" storage-key="payroll-loans">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Select v-model="status" :options="statusOptions" :label="t('payroll.fields.status')" class="w-full sm:w-44" />
                    <Button type="submit" size="sm">{{ t('payroll.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('payroll.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-title="{ row }">
                <Button variant="ghost" size="sm" :href="route('payroll.loans.show', { loan: row.id })">{{ row.title }}</Button>
                <span class="ml-1 text-xs text-ink-subtle">{{ t(`payroll.loanKinds.${row.kind}`) }}</span>
            </template>
            <template #cell-employee="{ row }">
                <span class="block max-w-56 truncate text-ink">{{ row.employee?.name ?? t('payroll.common.unknownEmployee') }}</span>
                <span v-if="row.employee" class="block text-xs text-ink-subtle">{{ row.employee.number }}</span>
            </template>
            <template #cell-principal="{ row }"><span class="tabular font-mono">{{ pay.money(row.principalAmount, row.currency) }}</span></template>
            <template #cell-installments="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.installmentCount) }}</span></template>
            <template #cell-firstDue="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.firstDueOn) }}</span></template>
            <template #cell-status="{ row }"><PayrollBadge kind="loan" :value="row.status" /></template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('payroll.loans.show', { loan: row.id })">{{ t('payroll.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="filtered ? t('payroll.common.noMatches') : t('payroll.loans.empty')" :description="filtered ? t('payroll.common.noMatchesBody') : t(canManage ? 'payroll.loans.emptyBody' : 'payroll.loans.emptyViewOnly')">
                    <template #icon><HandCoins /></template>
                    <Button v-if="canManage && !filtered && employees.length > 0" variant="primary" @click="creating = true">{{ t('payroll.loans.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="loans" />
    </Card>

    <LoanDialog v-if="canManage" v-model:open="creating" :employees="employees" :kinds="kinds" :currency="currency" :today="today" />
</template>
