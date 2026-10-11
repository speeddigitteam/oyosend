<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import InvoiceForm from '../../Components/InvoiceForm.vue';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import type { InvoiceFormOptions } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    form: InvoiceFormOptions;
    prefill: { contactId: string | null; companyId: string | null };
}>();

const { t } = useI18n();

function cancel(): void {
    router.get(route('invoicing.invoices.index'));
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="t('invoicing.invoices.create')" :description="t('invoicing.invoices.createDescription')" />
    <InvoicingNav current="invoices" />
    <InvoiceForm :options="form" :prefill="prefill" @cancel="cancel" />
</template>
