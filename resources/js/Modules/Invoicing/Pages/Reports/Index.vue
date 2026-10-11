<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChartNoAxesCombined } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import type { ReceivablesReport } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    report: ReceivablesReport;
    filters: { from: string | null; to: string | null; currency: string | null };
    buckets: string[];
}>();

const { t } = useI18n();
const format = useFormat();

const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const currency = ref(props.filters.currency ?? '');
const loading = ref(false);

const isEmpty = computed(() => props.report.ageing.length === 0
    && props.report.byCustomer.length === 0
    && props.report.collected.length === 0
    && props.report.invoiced.length === 0);

const ageingColumns = computed<Column[]>(() => [
    { key: 'currency', label: t('invoicing.fields.currency') },
    { key: 'invoices', label: t('invoicing.reports.invoices'), align: 'right' },
    ...props.buckets.map((bucket): Column => ({ key: bucket, label: t(`invoicing.reports.buckets.${bucket}`), align: 'right' })),
    { key: 'outstanding', label: t('invoicing.reports.outstanding'), align: 'right' },
]);
const customerColumns = computed<Column[]>(() => [
    { key: 'name', label: t('invoicing.fields.customer') },
    { key: 'invoices', label: t('invoicing.reports.invoices'), align: 'right' },
    { key: 'oldestDueOn', label: t('invoicing.reports.oldestDue'), hideable: true },
    { key: 'outstanding', label: t('invoicing.reports.outstanding'), align: 'right' },
]);
const customerRows = computed(() => props.report.byCustomer.map((row, index) => ({ ...row, key: `${row.companyId ?? ''}:${row.contactId ?? ''}:${row.currency}:${index}` })));
const collectedColumns = computed<Column[]>(() => [
    { key: 'currency', label: t('invoicing.fields.currency') },
    { key: 'payments', label: t('invoicing.reports.payments'), align: 'right' },
    { key: 'amount', label: t('invoicing.fields.amount'), align: 'right' },
]);
const methodColumns = computed<Column[]>(() => [
    { key: 'method', label: t('invoicing.fields.method') },
    { key: 'currency', label: t('invoicing.fields.currency') },
    { key: 'payments', label: t('invoicing.reports.payments'), align: 'right' },
    { key: 'amount', label: t('invoicing.fields.amount'), align: 'right' },
]);
const methodRows = computed(() => props.report.collectedByMethod.map((row) => ({ ...row, key: `${row.currency}:${row.method}` })));
const invoicedColumns = computed<Column[]>(() => [
    { key: 'currency', label: t('invoicing.fields.currency') },
    { key: 'invoices', label: t('invoicing.reports.invoices'), align: 'right' },
    { key: 'total', label: t('invoicing.fields.total'), align: 'right' },
    { key: 'tax', label: t('invoicing.fields.tax'), align: 'right' },
    { key: 'credited', label: t('invoicing.totals.credited'), align: 'right' },
]);

function apply(): void {
    router.get(route('invoicing.reports.index'), {
        from: from.value || undefined,
        to: to.value || undefined,
        currency: currency.value.trim() === '' ? undefined : currency.value.trim().toUpperCase(),
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="t('invoicing.reports.title')" :description="t('invoicing.reports.description', { date: format.date(report.asOf) })" />
    <InvoicingNav current="reports" />

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <Input v-model="from" type="date" :label="t('invoicing.filters.from')" class="w-full sm:w-44" />
            <Input v-model="to" type="date" :label="t('invoicing.filters.to')" class="w-full sm:w-44" />
            <Input v-model="currency" :label="t('invoicing.fields.currency')" :hint="t('invoicing.reports.currencyHint')" maxlength="3" class="w-full sm:w-36" mono />
            <Button type="submit" :loading="loading">{{ t('invoicing.common.apply') }}</Button>
        </form>
    </Card>

    <Card v-if="isEmpty">
        <EmptyState :title="t('invoicing.reports.empty')" :description="t('invoicing.reports.emptyBody')">
            <template #icon><ChartNoAxesCombined /></template>
        </EmptyState>
    </Card>

    <div v-else class="grid gap-3">
        <Card :title="t('invoicing.reports.ageing')" :description="t('invoicing.reports.ageingHint')" flush>
            <DataTable :columns="ageingColumns" :rows="report.ageing" row-key="currency" :caption="t('invoicing.reports.ageing')">
                <template v-for="bucket in buckets" :key="bucket" #[`cell-${bucket}`]="{ row }">
                    <span class="tabular font-mono">{{ format.money(row.buckets[bucket] ?? 0, row.currency) }}</span>
                </template>
                <template #cell-outstanding="{ row }"><span class="tabular font-mono text-ink">{{ format.money(row.outstanding, row.currency) }}</span></template>
                <template #cell-invoices="{ row }"><span class="tabular font-mono">{{ format.number(row.invoices) }}</span></template>
                <template #empty><EmptyState compact :title="t('invoicing.reports.noReceivables')" /></template>
            </DataTable>
        </Card>

        <Card :title="t('invoicing.reports.byCustomer')" flush>
            <DataTable :columns="customerColumns" :rows="customerRows" row-key="key" :caption="t('invoicing.reports.byCustomer')">
                <template #cell-name="{ row }">
                    <span class="text-ink">{{ row.name || t('invoicing.common.notSet') }}</span>
                    <span class="ml-2 text-xs text-ink-subtle">{{ row.currency }}</span>
                </template>
                <template #cell-invoices="{ row }"><span class="tabular font-mono">{{ format.number(row.invoices) }}</span></template>
                <template #cell-oldestDueOn="{ row }"><span class="tabular text-ink-muted">{{ row.oldestDueOn ? format.date(row.oldestDueOn) : t('invoicing.common.dash') }}</span></template>
                <template #cell-outstanding="{ row }"><span class="tabular font-mono">{{ format.money(row.outstanding, row.currency) }}</span></template>
                <template #empty><EmptyState compact :title="t('invoicing.reports.noReceivables')" /></template>
            </DataTable>
        </Card>

        <div class="grid gap-3 lg:grid-cols-2">
            <Card :title="t('invoicing.reports.collected')" :description="t('invoicing.reports.collectedHint')" flush>
                <DataTable :columns="collectedColumns" :rows="report.collected" row-key="currency" :caption="t('invoicing.reports.collected')">
                    <template #cell-payments="{ row }"><span class="tabular font-mono">{{ format.number(row.payments) }}</span></template>
                    <template #cell-amount="{ row }"><span class="tabular font-mono">{{ format.money(row.amount, row.currency) }}</span></template>
                    <template #empty><EmptyState compact :title="t('invoicing.reports.noPayments')" /></template>
                </DataTable>
            </Card>
            <Card :title="t('invoicing.reports.collectedByMethod')" flush>
                <DataTable :columns="methodColumns" :rows="methodRows" row-key="key" :caption="t('invoicing.reports.collectedByMethod')">
                    <template #cell-method="{ row }">{{ t(`invoicing.methods.${row.method}`) }}</template>
                    <template #cell-payments="{ row }"><span class="tabular font-mono">{{ format.number(row.payments) }}</span></template>
                    <template #cell-amount="{ row }"><span class="tabular font-mono">{{ format.money(row.amount, row.currency) }}</span></template>
                    <template #empty><EmptyState compact :title="t('invoicing.reports.noPayments')" /></template>
                </DataTable>
            </Card>
        </div>

        <Card :title="t('invoicing.reports.invoiced')" :description="t('invoicing.reports.invoicedHint')" flush>
            <DataTable :columns="invoicedColumns" :rows="report.invoiced" row-key="currency" :caption="t('invoicing.reports.invoiced')">
                <template #cell-invoices="{ row }"><span class="tabular font-mono">{{ format.number(row.invoices) }}</span></template>
                <template #cell-total="{ row }"><span class="tabular font-mono">{{ format.money(row.total, row.currency) }}</span></template>
                <template #cell-tax="{ row }"><span class="tabular font-mono">{{ format.money(row.tax, row.currency) }}</span></template>
                <template #cell-credited="{ row }"><span class="tabular font-mono">{{ format.money(row.credited, row.currency) }}</span></template>
                <template #empty><EmptyState compact :title="t('invoicing.reports.noInvoiced')" /></template>
            </DataTable>
        </Card>
    </div>
</template>
