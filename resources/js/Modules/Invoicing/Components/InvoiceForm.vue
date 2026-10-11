<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import CustomerFields from './CustomerFields.vue';
import InvoiceLinesEditor from './InvoiceLinesEditor.vue';
import { blankLine, currencyDigits, fromInvoiceLine, resolveAll } from './invoiceMath';
import type { EditableLine, InvoiceDetail, InvoiceFormOptions } from './types';

const props = withDefaults(defineProps<{
    options: InvoiceFormOptions;
    /** The draft being edited; omit to create a new invoice. */
    invoice?: InvoiceDetail | null;
    prefill?: { contactId: string | null; companyId: string | null };
}>(), { invoice: null, prefill: () => ({ contactId: null, companyId: null }) });

const emit = defineEmits<{ saved: []; cancel: [] }>();
const { t } = useI18n();

const defaultCurrency = props.invoice?.currency ?? props.options.defaults.currency ?? props.options.currencies[0] ?? '';
const form = useForm({
    company_id: props.invoice?.companyId ?? props.prefill.companyId ?? '',
    contact_id: props.invoice?.contactId ?? props.prefill.contactId ?? '',
    currency: defaultCurrency,
    title: props.invoice?.title ?? '',
    reference: props.invoice?.reference ?? '',
    issued_on: props.invoice?.issuedOn ?? '',
    due_on: props.invoice?.dueOn ?? '',
    terms: props.invoice ? (props.invoice.terms ?? '') : (props.options.defaults.terms ?? ''),
    notes: props.invoice ? (props.invoice.notes ?? '') : (props.options.defaults.notes ?? ''),
});

const initialDigits = currencyDigits(defaultCurrency);
const lines = ref<EditableLine[]>(
    props.invoice && props.invoice.lines.length > 0
        ? props.invoice.lines.map((line) => fromInvoiceLine(line, initialDigits, props.options.taxRates))
        : [blankLine({ taxRateId: props.options.taxRates.find((rate) => rate.isDefault)?.id ?? '' })],
);

const currencyOptions = computed(() => {
    const codes = new Set([...props.options.currencies, form.currency].filter((code) => code !== ''));

    return [...codes].map((code) => ({ value: code, label: code }));
});
const hasCustomer = computed(() => form.company_id !== '' || form.contact_id !== '');
const resolved = computed(() => resolveAll(lines.value, currencyDigits(form.currency), props.options.taxRates));
const canSave = computed(() => hasCustomer.value && resolved.value.valid && form.currency !== '');
const errors = computed(() => form.errors as Record<string, string>);

function submit(): void {
    if (!canSave.value) return;

    form.transform((data) => ({
        ...data,
        company_id: data.company_id === '' ? null : data.company_id,
        contact_id: data.contact_id === '' ? null : data.contact_id,
        issued_on: data.issued_on === '' ? null : data.issued_on,
        due_on: data.due_on === '' ? null : data.due_on,
        title: data.title === '' ? null : data.title,
        reference: data.reference === '' ? null : data.reference,
        terms: data.terms === '' ? null : data.terms,
        notes: data.notes === '' ? null : data.notes,
        lines: resolved.value.payloads,
    }));

    const settings = { preserveScroll: true, onSuccess: () => emit('saved') };

    if (props.invoice) form.put(route('invoicing.invoices.update', { invoice: props.invoice.id }), settings);
    else form.post(route('invoicing.invoices.store'), settings);
}
</script>

<template>
    <form class="grid gap-3" data-testid="invoice-form" @submit.prevent="submit">
        <Card :title="t('invoicing.form.customer')">
            <div class="grid gap-4 sm:grid-cols-2">
                <CustomerFields v-model:company-id="form.company_id" v-model:contact-id="form.contact_id" :companies="options.companies" :contacts="options.contacts" :company-error="form.errors.company_id" :contact-error="form.errors.contact_id" />
                <Select v-model="form.currency" :options="currencyOptions" :label="t('invoicing.fields.currency')" :error="form.errors.currency" required />
                <Input v-model="form.reference" :label="t('invoicing.fields.reference')" :error="form.errors.reference" maxlength="120" optional />
                <Input v-model="form.title" class="sm:col-span-2" :label="t('invoicing.fields.title')" :error="form.errors.title" maxlength="200" optional />
            </div>
        </Card>

        <Card :title="t('invoicing.form.dates')" :description="t('invoicing.form.datesHint', { days: String(options.defaults.dueDays ?? 0) })">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.issued_on" type="date" :label="t('invoicing.fields.issuedOn')" :error="form.errors.issued_on" optional />
                <Input v-model="form.due_on" type="date" :label="t('invoicing.fields.dueOn')" :error="form.errors.due_on" optional />
            </div>
        </Card>

        <Card :title="t('invoicing.form.lines')">
            <InvoiceLinesEditor v-model="lines" :currency="form.currency" :tax-rates="options.taxRates" :errors="errors" />
        </Card>

        <Card :title="t('invoicing.form.termsAndNotes')">
            <div class="grid gap-4">
                <Textarea v-model="form.terms" :label="t('invoicing.fields.terms')" :error="form.errors.terms" maxlength="20000" optional />
                <Textarea v-model="form.notes" :label="t('invoicing.fields.notes')" :error="form.errors.notes" maxlength="10000" optional />
            </div>
        </Card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <p v-if="!hasCustomer" class="mr-auto text-[0.8125rem] text-ink-subtle" role="status">{{ t('invoicing.form.customerRequired') }}</p>
            <Button variant="ghost" :disabled="form.processing" @click="emit('cancel')">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="!canSave">
                {{ t(invoice ? 'invoicing.form.saveDraft' : 'invoicing.form.createDraft') }}
            </Button>
        </div>
    </form>
</template>
