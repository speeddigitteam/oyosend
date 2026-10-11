<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, Receipt, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
import InvoiceStatusBadge from '../../Components/InvoiceStatusBadge.vue';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import type { InvoiceStatus, InvoiceSummary } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    invoices: Paginated<InvoiceSummary>;
    filters: { search: string; status: InvoiceStatus | null; contactId: string | null; companyId: string | null; from: string | null; to: string | null };
    statuses: InvoiceStatus[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const search = ref(props.filters.search);
const status = ref<string>(props.filters.status ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const loading = ref(false);

const statusOptions = computed(() => [
    { value: '', label: t('invoicing.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`invoicing.status.${value}`) })),
]);
const filtered = computed(() => search.value !== '' || status.value !== '' || from.value !== '' || to.value !== '');
const columns = computed<Column[]>(() => [
    { key: 'number', label: t('invoicing.fields.number') },
    { key: 'customer', label: t('invoicing.fields.customer') },
    { key: 'status', label: t('invoicing.fields.status') },
    { key: 'issuedOn', label: t('invoicing.fields.issuedOn'), hideable: true },
    { key: 'dueOn', label: t('invoicing.fields.dueOn'), hideable: true },
    { key: 'total', label: t('invoicing.fields.total'), align: 'right' },
    { key: 'balance', label: t('invoicing.fields.balance'), align: 'right' },
    { key: 'actions', label: t('invoicing.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('invoicing.invoices.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    search.value = '';
    status.value = '';
    from.value = '';
    to.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="t('invoicing.invoices.title')" :description="t('invoicing.invoices.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" :href="route('invoicing.invoices.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.invoices.create') }}
            </Button>
        </template>
    </PageHeader>
    <InvoicingNav current="invoices" />

    <Card flush>
        <DataTable :columns="columns" :rows="invoices.data" row-key="id" :caption="t('invoicing.invoices.caption')" :loading="loading" storage-key="invoicing-invoices">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="search" type="search" :label="t('invoicing.invoices.search')" class="min-w-48 flex-1">
                        <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    </Input>
                    <Select v-model="status" :options="statusOptions" :label="t('invoicing.fields.status')" class="w-full sm:w-40" />
                    <Input v-model="from" type="date" :label="t('invoicing.filters.from')" class="w-full sm:w-40" />
                    <Input v-model="to" type="date" :label="t('invoicing.filters.to')" class="w-full sm:w-40" />
                    <Button type="submit" size="sm">{{ t('invoicing.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('invoicing.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-number="{ row }">
                <Button variant="ghost" size="sm" :href="route('invoicing.invoices.show', { invoice: row.id })" class="font-mono">
                    {{ row.number ?? t('invoicing.invoices.draftNumber') }}
                </Button>
            </template>
            <template #cell-customer="{ row }">
                <span class="block max-w-64 truncate text-ink">{{ row.customer.name ?? t('invoicing.common.notSet') }}</span>
                <span v-if="row.title" class="block max-w-64 truncate text-xs text-ink-subtle">{{ row.title }}</span>
            </template>
            <template #cell-status="{ row }"><InvoiceStatusBadge :value="row.status" /></template>
            <template #cell-issuedOn="{ row }"><span class="tabular text-ink-muted">{{ row.issuedOn ? format.date(row.issuedOn) : t('invoicing.common.dash') }}</span></template>
            <template #cell-dueOn="{ row }"><span class="tabular text-ink-muted">{{ row.dueOn ? format.date(row.dueOn) : t('invoicing.common.dash') }}</span></template>
            <template #cell-total="{ row }"><span class="tabular font-mono">{{ format.money(row.totalAmount, row.currency) }}</span></template>
            <template #cell-balance="{ row }"><span class="tabular font-mono">{{ format.money(row.balanceAmount, row.currency) }}</span></template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('invoicing.invoices.show', { invoice: row.id })">{{ t('invoicing.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="filtered ? t('invoicing.common.noMatches') : t('invoicing.invoices.empty')" :description="filtered ? t('invoicing.common.noMatchesBody') : t(canCreate ? 'invoicing.invoices.emptyBody' : 'invoicing.invoices.emptyViewOnly')">
                    <template #icon><Receipt /></template>
                    <Button v-if="canCreate && !filtered" variant="primary" :href="route('invoicing.invoices.create')">{{ t('invoicing.invoices.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="invoices" />
    </Card>
</template>
