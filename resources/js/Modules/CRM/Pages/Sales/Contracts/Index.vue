<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { FileSignature, Pencil, Plus, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import CrmStatusBadge from '../../../Components/CrmStatusBadge.vue';
import SalesNav from '../../../Components/SalesNav.vue';
import type { Contract, ContractFormOptions, ContractStatus } from '../../../Components/types';

defineOptions({ layout: TenantLayout });
const props = defineProps<{
    contracts: Paginated<Contract>;
    filters: { search: string; status: ContractStatus | null };
    statuses: ContractStatus[];
    form: ContractFormOptions;
    canCreate: boolean;
}>();
const { t } = useI18n();
const format = useFormat();
const page = usePage();
const search = ref(props.filters.search);
const status = ref(props.filters.status ?? '');
const open = ref(false);
const target = ref<Contract | null>(null);
const statusTarget = ref<Contract | null>(null);
const change = useForm({ status: 'active' as ContractStatus });
const contract = useForm({
    sales_order_id: '', contact_id: '', company_id: '', title: '', currency: page.props.app.currency,
    value_amount: 0, starts_on: '', ends_on: '', renews_on: '', terms: '',
});
const statusOptions = computed(() => [
    { value: '', label: t('crm.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`crm.status.${value}`) })),
]);
const options = (items: Array<{ id: string; name?: string; number?: string }>) => [
    { value: '', label: t('crm.common.notSet') },
    ...items.map((item) => ({ value: item.id, label: item.name ?? item.number ?? item.id })),
];
const columns = computed<Column[]>(() => [
    { key: 'number', label: t('crm.fields.number') },
    { key: 'title', label: t('crm.fields.title') },
    { key: 'status', label: t('crm.fields.status') },
    { key: 'value', label: t('crm.fields.value'), align: 'right' },
    { key: 'period', label: t('crm.fields.period') },
    { key: 'actions', label: t('crm.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('crm.sales.contracts.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
}
function edit(item: Contract | null): void {
    target.value = item;
    contract.sales_order_id = item?.salesOrder?.id ?? '';
    contract.contact_id = item?.contact?.id ?? '';
    contract.company_id = item?.company?.id ?? '';
    contract.title = item?.title ?? '';
    contract.currency = item?.currency ?? page.props.app.currency;
    contract.value_amount = item?.valueAmount ?? 0;
    contract.starts_on = item?.startsOn ?? '';
    contract.ends_on = item?.endsOn ?? '';
    contract.renews_on = item?.renewsOn ?? '';
    contract.terms = item?.terms ?? '';
    contract.clearErrors();
    open.value = true;
}
function selectOrder(): void {
    const order = props.form.orders.find((item) => item.id === contract.sales_order_id);
    if (!order) return;
    contract.contact_id = order.contactId ?? '';
    contract.company_id = order.companyId ?? '';
    contract.currency = order.currency;
    contract.value_amount = order.totalAmount;
}
function submit(): void {
    contract.transform((data) => {
        if (!data.sales_order_id) return data;
        const { contact_id: _contact, company_id: _company, currency: _currency, value_amount: _value, ...canonical } = data;
        return canonical;
    });
    const settings = { preserveScroll: true, onSuccess: () => { open.value = false; contract.reset(); } };
    if (target.value) contract.put(route('crm.sales.contracts.update', { contract: target.value.id }), settings);
    else contract.post(route('crm.sales.contracts.store'), settings);
}
function statusesFor(item: Contract): ContractStatus[] {
    return item.status === 'draft' ? ['active', 'terminated'] : item.status === 'active' ? ['expired', 'terminated'] : [];
}
function openStatus(item: Contract): void {
    statusTarget.value = item;
    change.status = statusesFor(item)[0] ?? item.status;
    change.clearErrors();
}
function submitStatus(): void {
    if (!statusTarget.value) return;
    change.patch(route('crm.sales.contracts.status', { contract: statusTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => { statusTarget.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('crm.eyebrow')" :title="t('crm.sales.contracts.title')" :description="t('crm.sales.contracts.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" @click="edit(null)"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('crm.sales.contracts.add') }}</Button>
        </template>
    </PageHeader>
    <SalesNav current="contracts" />
    <Card flush>
        <DataTable :columns="columns" :rows="contracts.data" row-key="id" :caption="t('crm.sales.contracts.title')" storage-key="crm-contracts">
            <template #toolbar><form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply"><Input v-model="search" :label="t('crm.sales.contracts.search')" class="min-w-48 flex-1" type="search"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input><Select v-model="status" :options="statusOptions" :label="t('crm.fields.status')" class="w-full sm:w-40" /><Button type="submit" size="sm">{{ t('crm.common.search') }}</Button></form></template>
            <template #cell-number="{ row }"><span class="font-mono text-xs text-ink-muted">{{ row.number }}</span></template>
            <template #cell-title="{ row }"><span class="font-medium text-ink">{{ row.title }}</span></template>
            <template #cell-status="{ row }"><CrmStatusBadge :status="row.status" /></template>
            <template #cell-value="{ row }"><span class="tabular font-mono">{{ format.money(row.valueAmount, row.currency) }}</span></template>
            <template #cell-period="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.startsOn) }} — {{ row.endsOn ? format.date(row.endsOn) : t('crm.common.notSet') }}</span></template>
            <template #cell-actions="{ row }"><div class="flex justify-end gap-1"><Button v-if="row.editable" icon size="sm" variant="ghost" :aria-label="t('crm.sales.contracts.edit')" @click="edit(row)"><template #icon><Pencil class="size-4" aria-hidden="true" /></template></Button><Button v-if="row.statusable && statusesFor(row).length" size="sm" variant="ghost" @click="openStatus(row)">{{ t('crm.sales.contracts.changeStatus') }}</Button></div></template>
            <template #empty><EmptyState :title="t('crm.sales.contracts.empty')"><template #icon><FileSignature /></template></EmptyState></template>
        </DataTable>
        <Pagination :meta="contracts" />
    </Card>

    <Modal v-model:open="open" size="lg" :title="t(target ? 'crm.sales.contracts.edit' : 'crm.sales.contracts.add')" :persistent="contract.processing">
        <form id="crm-contract-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="contract.title" class="sm:col-span-2" :label="t('crm.fields.title')" :error="contract.errors.title" required />
            <Select v-model="contract.sales_order_id" :options="options(form.orders)" :label="t('crm.nav.orders')" :error="contract.errors.sales_order_id" @update:model-value="selectOrder" />
            <Select v-model="contract.contact_id" :options="options(form.contacts)" :label="t('crm.fields.contact')" :error="contract.errors.contact_id" :disabled="Boolean(contract.sales_order_id)" />
            <Select v-model="contract.company_id" :options="options(form.companies)" :label="t('crm.fields.company')" :error="contract.errors.company_id" :disabled="Boolean(contract.sales_order_id)" />
            <Input v-model="contract.currency" :label="t('crm.fields.currency')" :hint="contract.sales_order_id ? t('crm.sales.contracts.inheritedFromOrder') : undefined" :error="contract.errors.currency" maxlength="3" :disabled="Boolean(contract.sales_order_id)" />
            <Input v-model="contract.value_amount" type="number" min="0" :label="t('crm.fields.value')" :hint="contract.sales_order_id ? t('crm.sales.contracts.inheritedFromOrder') : undefined" :error="contract.errors.value_amount" :disabled="Boolean(contract.sales_order_id)" />
            <Input v-model="contract.starts_on" type="date" :label="t('crm.sales.contracts.start')" :error="contract.errors.starts_on" required />
            <Input v-model="contract.ends_on" type="date" :label="t('crm.sales.contracts.end')" :error="contract.errors.ends_on" optional />
            <Input v-model="contract.renews_on" type="date" :label="t('crm.sales.contracts.renewal')" :error="contract.errors.renews_on" optional />
            <Textarea v-model="contract.terms" class="sm:col-span-2" :label="t('crm.sales.quotes.terms')" :error="contract.errors.terms" optional />
        </form>
        <template #footer><Button variant="ghost" @click="open = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-contract-form" type="submit" variant="primary" :loading="contract.processing">{{ t('crm.common.save') }}</Button></template>
    </Modal>

    <Modal :open="statusTarget !== null" :title="t('crm.sales.contracts.changeStatus')" :persistent="change.processing" @update:open="!$event && (statusTarget = null)">
        <form id="crm-contract-status" @submit.prevent="submitStatus"><Select v-model="change.status" :options="statusTarget ? statusesFor(statusTarget).map((value) => ({ value, label: t(`crm.status.${value}`) })) : []" :label="t('crm.fields.status')" :error="change.errors.status" /></form>
        <template #footer><Button variant="ghost" @click="statusTarget = null">{{ t('crm.common.cancel') }}</Button><Button form="crm-contract-status" type="submit" variant="primary" :loading="change.processing">{{ t('crm.common.save') }}</Button></template>
    </Modal>
</template>
