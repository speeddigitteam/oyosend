<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Receipt, Search } from 'lucide-vue-next';
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
import type { NamedOption, PayslipRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    payslips: Paginated<PayslipRow>;
    filters: { q: string; period_id: string; payment_status: string };
    periods: NamedOption[];
    paymentStatuses: string[];
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const search = ref(props.filters.q);
const period = ref(props.filters.period_id);
const status = ref(props.filters.payment_status);
const loading = ref(false);

const periodOptions = computed(() => [{ value: '', label: t('payroll.common.all') }, ...props.periods.map((item) => ({ value: item.id, label: item.name }))]);
const statusOptions = computed(() => [
    { value: '', label: t('payroll.common.all') },
    ...props.paymentStatuses.map((value) => ({ value, label: t(`payroll.status.payment.${value}`) })),
]);
const filtered = computed(() => search.value !== '' || period.value !== '' || status.value !== '');
const columns = computed<Column[]>(() => [
    { key: 'number', label: t('payroll.fields.number') },
    { key: 'employee', label: t('payroll.fields.employee'), hideable: true },
    { key: 'period', label: t('payroll.fields.period') },
    { key: 'net', label: t('payroll.fields.net'), align: 'right' },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'paidOn', label: t('payroll.fields.paidOn'), hideable: true },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('payroll.payslips.index'), {
        q: search.value || undefined,
        period_id: period.value || undefined,
        payment_status: status.value || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    search.value = '';
    period.value = '';
    status.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.payslips.title')" :description="t('payroll.payslips.description')" />

    <Card flush>
        <DataTable :columns="columns" :rows="payslips.data" row-key="id" :caption="t('payroll.payslips.caption')" :loading="loading" storage-key="payroll-payslips">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="search" type="search" :label="t('payroll.payslips.search')" class="min-w-48 flex-1">
                        <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    </Input>
                    <Select v-model="period" :options="periodOptions" :label="t('payroll.fields.period')" class="w-full sm:w-48" />
                    <Select v-model="status" :options="statusOptions" :label="t('payroll.fields.paymentStatus')" class="w-full sm:w-40" />
                    <Button type="submit" size="sm">{{ t('payroll.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('payroll.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-number="{ row }">
                <Button variant="ghost" size="sm" :href="route('payroll.payslips.show', { payslip: row.id })" class="font-mono">
                    {{ row.number ?? t('payroll.payslips.draftNumber') }}
                </Button>
                <Badge v-if="row.isReversal" tone="warn" class="ml-1">{{ t('payroll.payslips.reversal') }}</Badge>
            </template>
            <template #cell-employee="{ row }">
                <span class="block max-w-56 truncate text-ink">{{ row.employeeName }}</span>
                <span class="block text-xs text-ink-subtle">{{ row.employeeNumber }}</span>
            </template>
            <template #cell-period="{ row }"><span class="text-ink-muted">{{ row.periodName ?? t('payroll.common.dash') }}</span></template>
            <template #cell-net="{ row }">
                <span class="tabular font-mono">{{ pay.money(row.netAmount, row.currency, row.isReversal) }}</span>
            </template>
            <template #cell-status="{ row }"><PayrollBadge kind="payment" :value="row.paymentStatus" /></template>
            <template #cell-paidOn="{ row }">
                <span class="tabular text-ink-muted">{{ row.paidOn ? format.date(row.paidOn) : t('payroll.common.dash') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('payroll.payslips.show', { payslip: row.id })">{{ t('payroll.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="filtered ? t('payroll.common.noMatches') : t('payroll.payslips.empty')" :description="filtered ? t('payroll.common.noMatchesBody') : t('payroll.payslips.emptyBody')">
                    <template #icon><Receipt /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="payslips" />
    </Card>
</template>
