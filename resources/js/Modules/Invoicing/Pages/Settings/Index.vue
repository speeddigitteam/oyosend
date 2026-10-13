<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Eye, Percent, Pencil, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import LetterheadBandCard from '../../Components/LetterheadBandCard.vue';
import TaxRateDialog from '../../Components/TaxRateDialog.vue';
import type { InvoicingSettings, TaxRate } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: InvoicingSettings;
    sequences: { invoicesIssued: number; creditNotesIssued: number };
    taxRates: TaxRate[];
}>();

const { t, locale } = useI18n();
const format = useFormat();

const form = useForm({
    invoice_prefix: props.settings.invoicePrefix,
    credit_note_prefix: props.settings.creditNotePrefix,
    number_padding: String(props.settings.numberPadding),
    fiscal_year_numbering: props.settings.fiscalYearNumbering,
    fiscal_year_start_month: String(props.settings.fiscalYearStartMonth),
    default_due_days: String(props.settings.defaultDueDays),
    default_terms: props.settings.defaultTerms ?? '',
    default_notes: props.settings.defaultNotes ?? '',
    reminders_enabled: props.settings.remindersEnabled,
    reminder_before_days: String(props.settings.reminderBeforeDays),
    reminder_overdue_every_days: String(props.settings.reminderOverdueEveryDays),
    invoice_accepted_quotes: props.settings.invoiceAcceptedQuotes,
    document_template: props.settings.documentTemplate,
});

const templateOptions = computed(() => [
    { value: 'classic', label: t('invoicing.settings.templateClassic') },
    { value: 'modern', label: t('invoicing.settings.templateModern') },
    { value: 'compact', label: t('invoicing.settings.templateCompact') },
    { value: 'letterhead', label: t('invoicing.settings.templateLetterhead') },
]);

const rateOpen = ref(false);
const editingRate = ref<TaxRate | null>(null);

const monthOptions = computed(() => {
    const names = new Intl.DateTimeFormat(locale.value === 'bn' ? 'bn-BD' : 'en', { month: 'long', timeZone: 'UTC' });

    return Array.from({ length: 12 }, (_, index) => ({ value: String(index + 1), label: names.format(new Date(Date.UTC(2026, index, 1))) }));
});
const nextInvoice = computed(() => {
    const padding = Math.min(Math.max(Number(form.number_padding) || 1, 1), 10);
    const number = String(props.sequences.invoicesIssued + 1).padStart(padding, '0');

    return `${form.invoice_prefix}${form.fiscal_year_numbering ? 'YYYY-' : ''}${number}`;
});
const rateColumns = computed<Column[]>(() => [
    { key: 'name', label: t('invoicing.fields.name') },
    { key: 'rate', label: t('invoicing.fields.taxPercent'), align: 'right' },
    { key: 'state', label: t('invoicing.fields.status') },
    { key: 'actions', label: t('invoicing.common.actions'), srOnly: true, align: 'right' },
]);

function submit(): void {
    form.transform((data) => ({
        ...data,
        number_padding: Number(data.number_padding),
        fiscal_year_start_month: Number(data.fiscal_year_start_month),
        default_due_days: Number(data.default_due_days),
        reminder_before_days: Number(data.reminder_before_days),
        reminder_overdue_every_days: Number(data.reminder_overdue_every_days),
        default_terms: data.default_terms === '' ? null : data.default_terms,
        default_notes: data.default_notes === '' ? null : data.default_notes,
    }));
    form.put(route('invoicing.settings.update'), { preserveScroll: true });
}

function openRate(rate: TaxRate | null): void {
    editingRate.value = rate;
    rateOpen.value = true;
}

function previewTemplate(): void {
    window.open(route('invoicing.settings.document-preview', { template: form.document_template }), '_blank', 'noopener');
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="t('invoicing.settings.title')" :description="t('invoicing.settings.description')" />
    <InvoicingNav current="settings" />

    <form class="grid gap-3" @submit.prevent="submit">
        <Card :title="t('invoicing.settings.numbering')" :description="t('invoicing.settings.numberingHint')">
            <div class="grid gap-4 sm:grid-cols-3">
                <Input v-model="form.invoice_prefix" :label="t('invoicing.settings.invoicePrefix')" :error="form.errors.invoice_prefix" maxlength="20" mono />
                <Input v-model="form.credit_note_prefix" :label="t('invoicing.settings.creditNotePrefix')" :error="form.errors.credit_note_prefix" maxlength="20" mono />
                <Input v-model="form.number_padding" type="number" min="1" max="10" step="1" :label="t('invoicing.settings.numberPadding')" :error="form.errors.number_padding" required />
                <Checkbox v-model="form.fiscal_year_numbering" class="sm:col-span-2" :label="t('invoicing.settings.fiscalNumbering')" :description="t('invoicing.settings.fiscalNumberingHint')" />
                <Select v-if="form.fiscal_year_numbering" v-model="form.fiscal_year_start_month" :options="monthOptions" :label="t('invoicing.settings.fiscalStart')" :error="form.errors.fiscal_year_start_month" />
            </div>
            <p class="mt-4 text-sm text-ink-muted" data-testid="next-number">
                {{ t('invoicing.settings.nextNumber') }}
                <span class="font-mono text-ink">{{ nextInvoice }}</span>
                <span class="text-ink-subtle"> · {{ t('invoicing.settings.issuedCount', { invoices: format.number(sequences.invoicesIssued), notes: format.number(sequences.creditNotesIssued) }) }}</span>
            </p>
        </Card>

        <Card :title="t('invoicing.settings.defaults')">
            <div class="grid gap-4">
                <Input v-model="form.default_due_days" type="number" min="0" max="365" step="1" class="sm:max-w-48" :label="t('invoicing.settings.dueDays')" :error="form.errors.default_due_days" required />
                <Textarea v-model="form.default_terms" :label="t('invoicing.settings.defaultTerms')" :error="form.errors.default_terms" maxlength="20000" optional />
                <Textarea v-model="form.default_notes" :label="t('invoicing.settings.defaultNotes')" :error="form.errors.default_notes" maxlength="10000" optional />
            </div>
        </Card>

        <Card :title="t('invoicing.settings.reminders')" :description="t('invoicing.settings.remindersHint')">
            <div class="grid gap-4 sm:grid-cols-2">
                <Checkbox v-model="form.reminders_enabled" class="sm:col-span-2" :label="t('invoicing.settings.remindersEnabled')" />
                <Input v-model="form.reminder_before_days" type="number" min="0" max="30" step="1" :label="t('invoicing.settings.reminderBefore')" :error="form.errors.reminder_before_days" :disabled="!form.reminders_enabled" required />
                <Input v-model="form.reminder_overdue_every_days" type="number" min="1" max="90" step="1" :label="t('invoicing.settings.reminderEvery')" :error="form.errors.reminder_overdue_every_days" :disabled="!form.reminders_enabled" required />
            </div>
        </Card>

        <Card :title="t('invoicing.settings.handoff')">
            <Checkbox v-model="form.invoice_accepted_quotes" :label="t('invoicing.settings.invoiceQuotes')" :description="t('invoicing.settings.invoiceQuotesHint')" />
        </Card>

        <Card :title="t('invoicing.settings.documentTitle')" :description="t('invoicing.settings.documentHint')">
            <div class="flex flex-wrap items-end gap-3">
                <Select v-model="form.document_template" :options="templateOptions" class="sm:max-w-64" :label="t('invoicing.settings.templateLabel')" :error="form.errors.document_template" />
                <Button type="button" size="sm" variant="secondary" @click="previewTemplate">
                    <template #icon><Eye class="size-4" aria-hidden="true" /></template>
                    {{ t('invoicing.settings.templatePreview') }}
                </Button>
            </div>
            <p class="mt-3 text-sm text-ink-muted">{{ t('invoicing.settings.templatePreviewHint') }}</p>
            <p v-if="form.document_template === 'letterhead'" class="mt-1 text-sm text-ink-muted">{{ t('invoicing.settings.templateLetterheadHint') }}</p>
        </Card>

        <div class="flex justify-end">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('invoicing.common.save') }}</Button>
        </div>
    </form>

    <Card class="mt-3" :title="t('invoicing.settings.letterhead')" :description="t('invoicing.settings.letterheadHint')">
        <div class="grid gap-4 sm:grid-cols-2">
            <LetterheadBandCard band="header" :label="t('invoicing.settings.letterheadHeader')" :image-url="settings.letterheadHeaderUrl" />
            <LetterheadBandCard band="footer" :label="t('invoicing.settings.letterheadFooter')" :image-url="settings.letterheadFooterUrl" />
        </div>
    </Card>

    <Card class="mt-3" :title="t('invoicing.settings.taxRates')" :description="t('invoicing.settings.taxRatesHint')" flush>
        <template #actions>
            <Button size="sm" variant="secondary" @click="openRate(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.settings.addTaxRate') }}
            </Button>
        </template>
        <DataTable :columns="rateColumns" :rows="taxRates" row-key="id" :caption="t('invoicing.settings.taxRates')">
            <template #cell-name="{ row }">{{ row.name }}</template>
            <template #cell-rate="{ row }"><span class="tabular font-mono">{{ format.number(row.rateBps / 100, { maximumFractionDigits: 2 }) }}%</span></template>
            <template #cell-state="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ t(row.isActive ? 'invoicing.settings.active' : 'invoicing.settings.inactive') }}</Badge>
                    <Badge v-if="row.isDefault" tone="brand">{{ t('invoicing.settings.default') }}</Badge>
                </div>
            </template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" @click="openRate(row)">
                    <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                    {{ t('invoicing.common.edit') }}
                </Button>
            </template>
            <template #empty>
                <EmptyState compact :title="t('invoicing.settings.noTaxRates')" :description="t('invoicing.settings.noTaxRatesBody')">
                    <template #icon><Percent /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <TaxRateDialog v-model:open="rateOpen" :rate="editingRate" />
</template>
