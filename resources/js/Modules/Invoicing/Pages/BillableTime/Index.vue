<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Clock3 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CustomerFields from '../../Components/CustomerFields.vue';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import type { BillableProject, InvoiceFormOptions } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    projects: BillableProject[];
    form: InvoiceFormOptions;
    canBill: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const selected = ref<BillableProject | null>(null);
const open = computed({
    get: () => selected.value !== null,
    set: (value: boolean) => { if (!value) selected.value = null; },
});
const billing = useForm({ company_id: '', contact_id: '', label: '' });
const hasCustomer = computed(() => billing.company_id !== '' || billing.contact_id !== '');

const columns = computed<Column[]>(() => [
    { key: 'project', label: t('invoicing.billable.project') },
    { key: 'entries', label: t('invoicing.billable.entries'), align: 'right' },
    { key: 'hours', label: t('invoicing.billable.hours'), align: 'right' },
    { key: 'estimate', label: t('invoicing.billable.estimate'), align: 'right' },
    { key: 'actions', label: t('invoicing.common.actions'), srOnly: true, align: 'right' },
]);

watch(selected, (value) => {
    if (value) {
        billing.reset();
        billing.clearErrors();
    }
});

function hours(minutes: number): string {
    return format.number(minutes / 60, { maximumFractionDigits: 2 });
}

function shortId(id: string): string {
    return id.slice(-8);
}

function submit(): void {
    const project = selected.value;

    if (!project || !hasCustomer.value) return;

    billing.transform((data) => ({
        project_id: project.projectId,
        currency: project.currency,
        label: data.label.trim() === '' ? null : data.label.trim(),
        company_id: data.company_id === '' ? null : data.company_id,
        contact_id: data.contact_id === '' ? null : data.contact_id,
    }));
    billing.post(route('invoicing.billable-time.store'), { preserveScroll: true, onSuccess: () => { selected.value = null; } });
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="t('invoicing.billable.title')" :description="t('invoicing.billable.description')" />
    <InvoicingNav current="billable" />

    <Card flush>
        <DataTable :columns="columns" :rows="projects" row-key="projectId" :caption="t('invoicing.billable.caption')">
            <template #cell-project="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ t('invoicing.billable.projectRef', { id: shortId(row.projectId) }) }}</span>
                <span class="block text-xs text-ink-subtle">{{ row.currency }}</span>
            </template>
            <template #cell-entries="{ row }"><span class="tabular font-mono">{{ format.number(row.entries) }}</span></template>
            <template #cell-hours="{ row }"><span class="tabular font-mono">{{ hours(row.minutes) }}</span></template>
            <template #cell-estimate="{ row }"><span class="tabular font-mono">{{ format.money(row.estimatedAmount, row.currency) }}</span></template>
            <template #cell-actions="{ row }">
                <Button v-if="canBill" size="sm" variant="secondary" @click="selected = row">{{ t('invoicing.billable.create') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="t('invoicing.billable.empty')" :description="t('invoicing.billable.emptyBody')">
                    <template #icon><Clock3 /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>
    <p class="mt-3 text-[0.8125rem] text-ink-subtle">{{ t('invoicing.billable.note') }}</p>

    <Modal v-model:open="open" :title="t('invoicing.billable.create')" :description="t('invoicing.billable.createHint')" :persistent="billing.processing">
        <form id="billable-form" class="grid gap-4" @submit.prevent="submit">
            <CustomerFields v-model:company-id="billing.company_id" v-model:contact-id="billing.contact_id" :companies="form.companies" :contacts="form.contacts" :company-error="billing.errors.company_id" :contact-error="billing.errors.contact_id" />
            <Input v-model="billing.label" :label="t('invoicing.billable.label')" :hint="t('invoicing.billable.labelHint')" :error="billing.errors.label" maxlength="180" optional />
            <p v-if="(billing.errors as Record<string, string>).project_id" class="text-sm text-danger" role="alert">{{ (billing.errors as Record<string, string>).project_id }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="billing.processing" @click="open = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="billable-form" variant="primary" :loading="billing.processing" :disabled="!hasCustomer">{{ t('invoicing.billable.submit') }}</Button>
        </template>
    </Modal>
</template>
