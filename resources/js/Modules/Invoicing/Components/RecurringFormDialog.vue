<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import CustomerFields from './CustomerFields.vue';
import InvoiceLinesEditor from './InvoiceLinesEditor.vue';
import { blankLine, currencyDigits, fromStoredLine, resolveAll } from './invoiceMath';
import type { EditableLine, InvoiceFormOptions, RecurringFrequency, RecurringTemplate } from './types';

const props = withDefaults(defineProps<{
    open: boolean;
    options: InvoiceFormOptions;
    frequencies: RecurringFrequency[];
    template?: RecurringTemplate | null;
}>(), { template: null });

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({
    company_id: '',
    contact_id: '',
    name: '',
    frequency: 'monthly' as string,
    every: '1',
    currency: '',
    starts_on: '',
    ends_on: '',
    due_days: '14',
    auto_issue: false,
    auto_send: false,
    title: '',
    reference: '',
    terms: '',
    notes: '',
});
const lines = ref<EditableLine[]>([blankLine()]);

const frequencyOptions = computed(() => props.frequencies.map((value) => ({ value, label: t(`invoicing.frequencies.${value}`) })));
const currencyOptions = computed(() => [...new Set([...props.options.currencies, form.currency].filter((code) => code !== ''))]
    .map((code) => ({ value: code, label: code })));
const resolved = computed(() => resolveAll(lines.value, currencyDigits(form.currency), props.options.taxRates));
const hasCustomer = computed(() => form.company_id !== '' || form.contact_id !== '');
const canSave = computed(() => hasCustomer.value && resolved.value.valid && form.name.trim() !== '' && form.currency !== '' && form.starts_on !== '');
const errors = computed(() => form.errors as Record<string, string>);

watch(() => props.open, (open) => {
    if (!open) return;

    const template = props.template;
    const currency = template?.currency ?? props.options.defaults.currency ?? props.options.currencies[0] ?? '';

    form.defaults({
        company_id: template?.companyId ?? '',
        contact_id: template?.contactId ?? '',
        name: template?.name ?? '',
        frequency: template?.frequency ?? 'monthly',
        every: String(template?.every ?? 1),
        currency,
        starts_on: template?.startsOn ?? '',
        ends_on: template?.endsOn ?? '',
        due_days: String(template?.dueDays ?? props.options.defaults.dueDays ?? 14),
        auto_issue: template?.autoIssue ?? false,
        auto_send: template?.autoSend ?? false,
        title: template?.title ?? '',
        reference: template?.reference ?? '',
        terms: template ? (template.terms ?? '') : (props.options.defaults.terms ?? ''),
        notes: template ? (template.notes ?? '') : (props.options.defaults.notes ?? ''),
    });
    form.reset();
    form.clearErrors();

    const digits = currencyDigits(currency);
    lines.value = template && template.lines.length > 0
        ? template.lines.map((line) => fromStoredLine(line, digits, props.options.taxRates))
        : [blankLine({ taxRateId: props.options.taxRates.find((rate) => rate.isDefault)?.id ?? '' })];
}, { immediate: true });

function submit(): void {
    if (!canSave.value) return;

    form.transform((data) => ({
        ...data,
        company_id: data.company_id === '' ? null : data.company_id,
        contact_id: data.contact_id === '' ? null : data.contact_id,
        every: Number(data.every),
        due_days: Number(data.due_days),
        ends_on: data.ends_on === '' ? null : data.ends_on,
        title: data.title === '' ? null : data.title,
        reference: data.reference === '' ? null : data.reference,
        terms: data.terms === '' ? null : data.terms,
        notes: data.notes === '' ? null : data.notes,
        lines: resolved.value.payloads,
    }));

    const settings = { preserveScroll: true, onSuccess: () => { model.value = false; } };

    if (props.template) form.put(route('invoicing.recurring.update', { template: props.template.id }), settings);
    else form.post(route('invoicing.recurring.store'), settings);
}
</script>

<template>
    <Modal v-model:open="model" size="lg" :title="t(template ? 'invoicing.recurring.edit' : 'invoicing.recurring.create')" :description="t('invoicing.recurring.formHint')" :persistent="form.processing">
        <form id="recurring-form" class="grid gap-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.name" class="sm:col-span-2" :label="t('invoicing.fields.templateName')" :error="form.errors.name" maxlength="160" required />
                <CustomerFields v-model:company-id="form.company_id" v-model:contact-id="form.contact_id" :companies="options.companies" :contacts="options.contacts" :company-error="form.errors.company_id" :contact-error="form.errors.contact_id" />
                <Select v-model="form.frequency" :options="frequencyOptions" :label="t('invoicing.fields.frequency')" :error="form.errors.frequency" required />
                <Input v-model="form.every" type="number" min="1" max="12" step="1" :label="t('invoicing.fields.every')" :error="form.errors.every" required />
                <Input v-model="form.starts_on" type="date" :label="t('invoicing.fields.startsOn')" :error="form.errors.starts_on" required />
                <Input v-model="form.ends_on" type="date" :label="t('invoicing.fields.endsOn')" :error="form.errors.ends_on" optional />
                <Select v-model="form.currency" :options="currencyOptions" :label="t('invoicing.fields.currency')" :error="form.errors.currency" required />
                <Input v-model="form.due_days" type="number" min="0" max="365" step="1" :label="t('invoicing.fields.dueDays')" :error="form.errors.due_days" required />
                <Input v-model="form.title" :label="t('invoicing.fields.title')" :error="form.errors.title" maxlength="200" optional />
                <Input v-model="form.reference" :label="t('invoicing.fields.reference')" :error="form.errors.reference" maxlength="120" optional />
                <Checkbox v-model="form.auto_issue" :label="t('invoicing.recurring.autoIssue')" :description="t('invoicing.recurring.autoIssueHint')" />
                <Checkbox v-model="form.auto_send" :label="t('invoicing.recurring.autoSend')" :description="t('invoicing.recurring.autoSendHint')" />
            </div>
            <h3 class="text-sm font-semibold text-ink">{{ t('invoicing.form.lines') }}</h3>
            <InvoiceLinesEditor v-model="lines" :currency="form.currency" :tax-rates="options.taxRates" :errors="errors" />
            <div class="grid gap-4">
                <Textarea v-model="form.terms" :label="t('invoicing.fields.terms')" :error="form.errors.terms" maxlength="20000" optional />
                <Textarea v-model="form.notes" :label="t('invoicing.fields.notes')" :error="form.errors.notes" maxlength="10000" optional />
            </div>
            <p v-if="!hasCustomer" class="text-[0.8125rem] text-ink-subtle" role="status">{{ t('invoicing.form.customerRequired') }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="recurring-form" variant="primary" :loading="form.processing" :disabled="!canSave">{{ t('invoicing.common.save') }}</Button>
        </template>
    </Modal>
</template>
