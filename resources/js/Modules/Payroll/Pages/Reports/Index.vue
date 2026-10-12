<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChartNoAxesCombined, Download } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { DeductionRow, DepartmentCostRow, NamedOption, PayslipRow, Period, ReportTotals } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    filters: { period_id: string; from: string; to: string; department_id: string };
    periods: Period[];
    departments: NamedOption[];
    currency: string;
    totals: ReportTotals;
    register: Paginated<PayslipRow>;
    departmentCost: DepartmentCostRow[];
    deductions: DeductionRow[];
    employerContributions: DeductionRow[];
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const period = ref(props.filters.period_id);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const department = ref(props.filters.department_id);
const loading = ref(false);

const periodOptions = computed(() => [
    { value: '', label: t('payroll.reports.dateRange') },
    ...props.periods.map((item) => ({ value: item.id, label: item.name })),
]);
const departmentOptions = computed(() => [
    { value: '', label: t('payroll.common.all') },
    ...props.departments.map((item) => ({ value: item.id, label: item.name })),
]);

/** The filters in force, without the empty ones. A chosen period replaces the date range. */
const query = computed(() => {
    const params: Record<string, string> = {};

    if (props.filters.period_id !== '') {
        params.period_id = props.filters.period_id;
    } else {
        params.from = props.filters.from;
        params.to = props.filters.to;
    }

    if (props.filters.department_id !== '') {
        params.department_id = props.filters.department_id;
    }

    return params;
});

function exportUrl(report: 'register' | 'departments' | 'deductions' | 'employer'): string {
    return route('payroll.reports.export', { report, ...query.value });
}

const tiles = computed(() => [
    { key: 'gross', label: t('payroll.fields.gross'), amount: props.totals.gross_amount },
    { key: 'deductions', label: t('payroll.fields.deductions'), amount: props.totals.deduction_amount },
    { key: 'reimbursements', label: t('payroll.fields.reimbursements'), amount: props.totals.reimbursement_amount },
    { key: 'employer', label: t('payroll.fields.employer'), amount: props.totals.employer_amount },
]);
const departmentColumns = computed<Column[]>(() => [
    { key: 'department', label: t('payroll.fields.department') },
    { key: 'employees', label: t('payroll.fields.employees'), align: 'right' },
    { key: 'gross', label: t('payroll.fields.gross'), align: 'right', hideable: true },
    { key: 'deductions', label: t('payroll.fields.deductions'), align: 'right', hideable: true },
    { key: 'net', label: t('payroll.fields.net'), align: 'right' },
    { key: 'employer', label: t('payroll.fields.employer'), align: 'right', hideable: true },
]);
const lineColumns = computed<Column[]>(() => [
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'code', label: t('payroll.fields.code'), hideable: true },
    { key: 'employees', label: t('payroll.fields.employees'), align: 'right' },
    { key: 'amount', label: t('payroll.fields.amount'), align: 'right' },
]);
const registerColumns = computed<Column[]>(() => [
    { key: 'number', label: t('payroll.fields.number') },
    { key: 'employee', label: t('payroll.fields.employee') },
    { key: 'period', label: t('payroll.fields.period'), hideable: true },
    { key: 'gross', label: t('payroll.fields.gross'), align: 'right', hideable: true },
    { key: 'deductions', label: t('payroll.fields.deductions'), align: 'right', hideable: true },
    { key: 'net', label: t('payroll.fields.net'), align: 'right' },
    { key: 'status', label: t('payroll.fields.paymentStatus'), hideable: true },
]);
const departmentRows = computed(() => props.departmentCost.map((row, index) => ({ ...row, id: String(index) })));
const deductionRows = computed(() => props.deductions.map((row) => ({ ...row, id: row.code })));
const employerRows = computed(() => props.employerContributions.map((row) => ({ ...row, id: row.code })));

function apply(): void {
    router.get(route('payroll.reports.index'), {
        period_id: period.value || undefined,
        from: period.value === '' ? from.value || undefined : undefined,
        to: period.value === '' ? to.value || undefined : undefined,
        department_id: department.value || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.reports.title')" :description="t('payroll.reports.description')" />

    <div class="grid gap-3">
        <Card>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <Select v-model="period" :options="periodOptions" :label="t('payroll.fields.period')" class="w-full sm:w-56" />
                <template v-if="period === ''">
                    <Input v-model="from" type="date" :label="t('payroll.fields.from')" class="w-full sm:w-40" />
                    <Input v-model="to" type="date" :label="t('payroll.fields.to')" class="w-full sm:w-40" />
                </template>
                <Select v-model="department" :options="departmentOptions" :label="t('payroll.fields.department')" class="w-full sm:w-52" />
                <Button type="submit" size="sm" :loading="loading">{{ t('payroll.common.apply') }}</Button>
            </form>
            <p class="mt-3 text-[0.8125rem] text-ink-subtle">{{ t('payroll.reports.approvedOnly') }}</p>
        </Card>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="report-totals">
            <div class="glass rounded-card px-5 py-4">
                <p class="eyebrow">{{ t('payroll.fields.net') }}</p>
                <p class="figure mt-2 text-2xl text-ink" data-testid="report-net">{{ pay.money(totals.net_amount, currency) }}</p>
                <p class="mt-1 text-xs text-ink-subtle">{{ t('payroll.reports.payslipCount', { count: format.number(totals.payslips) }) }}</p>
            </div>
            <div v-for="tile in tiles" :key="tile.key" class="glass rounded-card px-5 py-4">
                <p class="eyebrow">{{ tile.label }}</p>
                <p class="tabular mt-2 font-mono text-ink" :data-testid="`report-${tile.key}`">{{ pay.money(tile.amount, currency) }}</p>
            </div>
        </div>

        <Card flush :title="t('payroll.reports.departmentCost')">
            <template #actions>
                <a :href="exportUrl('departments')" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-text underline underline-offset-2" data-testid="export-departments">
                    <Download class="size-4" aria-hidden="true" />{{ t('payroll.reports.exportCsv') }}
                </a>
            </template>
            <DataTable :columns="departmentColumns" :rows="departmentRows" row-key="id" :caption="t('payroll.reports.departmentCost')">
                <template #cell-department="{ row }"><span class="text-ink">{{ row.department ?? t('payroll.reports.noDepartment') }}</span></template>
                <template #cell-employees="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.employees) }}</span></template>
                <template #cell-gross="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.gross_amount, currency) }}</span></template>
                <template #cell-deductions="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.deduction_amount, currency) }}</span></template>
                <template #cell-net="{ row }"><span class="tabular font-mono">{{ pay.money(row.net_amount, currency) }}</span></template>
                <template #cell-employer="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.employer_amount, currency) }}</span></template>
                <template #empty><EmptyState :title="t('payroll.reports.empty')" :description="t('payroll.reports.emptyBody')"><template #icon><ChartNoAxesCombined /></template></EmptyState></template>
            </DataTable>
        </Card>

        <div class="grid gap-3 lg:grid-cols-2">
            <Card flush :title="t('payroll.reports.deductions')">
                <template #actions>
                    <a :href="exportUrl('deductions')" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-text underline underline-offset-2" data-testid="export-deductions">
                        <Download class="size-4" aria-hidden="true" />{{ t('payroll.reports.exportCsv') }}
                    </a>
                </template>
                <DataTable :columns="lineColumns" :rows="deductionRows" row-key="id" :caption="t('payroll.reports.deductions')">
                    <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                    <template #cell-code="{ row }"><span class="font-mono text-ink-muted">{{ row.code }}</span></template>
                    <template #cell-employees="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.employees) }}</span></template>
                    <template #cell-amount="{ row }"><span class="tabular font-mono">{{ pay.money(row.amount, currency) }}</span></template>
                    <template #empty><p class="px-5 py-6 text-sm text-ink-subtle">{{ t('payroll.reports.noLines') }}</p></template>
                </DataTable>
            </Card>
            <Card flush :title="t('payroll.reports.employerContributions')">
                <template #actions>
                    <a :href="exportUrl('employer')" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-text underline underline-offset-2" data-testid="export-employer">
                        <Download class="size-4" aria-hidden="true" />{{ t('payroll.reports.exportCsv') }}
                    </a>
                </template>
                <DataTable :columns="lineColumns" :rows="employerRows" row-key="id" :caption="t('payroll.reports.employerContributions')">
                    <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                    <template #cell-code="{ row }"><span class="font-mono text-ink-muted">{{ row.code }}</span></template>
                    <template #cell-employees="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.employees) }}</span></template>
                    <template #cell-amount="{ row }"><span class="tabular font-mono">{{ pay.money(row.amount, currency) }}</span></template>
                    <template #empty><p class="px-5 py-6 text-sm text-ink-subtle">{{ t('payroll.reports.noLines') }}</p></template>
                </DataTable>
            </Card>
        </div>

        <Card flush :title="t('payroll.reports.register')">
            <template #actions>
                <a :href="exportUrl('register')" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-text underline underline-offset-2" data-testid="export-register">
                    <Download class="size-4" aria-hidden="true" />{{ t('payroll.reports.exportCsv') }}
                </a>
            </template>
            <DataTable :columns="registerColumns" :rows="register.data" row-key="id" :caption="t('payroll.reports.register')" :loading="loading" storage-key="payroll-register">
                <template #cell-number="{ row }">
                    <Button variant="ghost" size="sm" :href="route('payroll.payslips.show', { payslip: row.id })" class="font-mono">{{ row.number ?? t('payroll.payslips.draftNumber') }}</Button>
                    <Badge v-if="row.isReversal" tone="warn" class="ml-1">{{ t('payroll.payslips.reversal') }}</Badge>
                </template>
                <template #cell-employee="{ row }">
                    <span class="block max-w-56 truncate text-ink">{{ row.employeeName }}</span>
                    <span class="block text-xs text-ink-subtle">{{ row.employeeNumber }}</span>
                </template>
                <template #cell-period="{ row }"><span class="text-ink-muted">{{ row.periodName ?? t('payroll.common.dash') }}</span></template>
                <template #cell-gross="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.grossAmount, row.currency, row.isReversal) }}</span></template>
                <template #cell-deductions="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.deductionAmount, row.currency, row.isReversal) }}</span></template>
                <template #cell-net="{ row }"><span class="tabular font-mono">{{ pay.money(row.netAmount, row.currency, row.isReversal) }}</span></template>
                <template #cell-status="{ row }"><PayrollBadge kind="payment" :value="row.paymentStatus" /></template>
                <template #empty><EmptyState :title="t('payroll.reports.empty')" :description="t('payroll.reports.emptyBody')" /></template>
            </DataTable>
            <Pagination :meta="register" />
        </Card>
    </div>
</template>
