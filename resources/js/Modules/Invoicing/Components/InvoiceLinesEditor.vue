<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import InvoiceTotals from './InvoiceTotals.vue';
import { blankLine, currencyDigits, MAX_LINES, resolveAll } from './invoiceMath';
import type { EditableLine, TaxRate } from './types';

const props = withDefaults(defineProps<{
    currency: string;
    taxRates: TaxRate[];
    errors?: Record<string, string>;
    /** Show the live totals preview under the lines. */
    preview?: boolean;
}>(), { errors: () => ({}), preview: true });

const lines = defineModel<EditableLine[]>({ required: true });
const { t } = useI18n();
const format = useFormat();

const digits = computed(() => currencyDigits(props.currency));
const resolved = computed(() => resolveAll(lines.value, digits.value, props.taxRates));
const taxOptions = computed(() => [
    { value: '', label: t('invoicing.form.noTax') },
    ...props.taxRates.map((rate) => ({ value: rate.id, label: `${rate.name} (${format.number(rate.rateBps / 100, { maximumFractionDigits: 2 })}%)` })),
    { value: 'custom', label: t('invoicing.form.customTax') },
]);
const discountOptions = computed(() => [
    { value: 'none', label: t('invoicing.form.discountNone') },
    { value: 'percent', label: t('invoicing.form.discountPercent') },
    { value: 'amount', label: t('invoicing.form.discountAmount', { currency: props.currency }) },
]);
const incomplete = computed(() => lines.value.length - resolved.value.payloads.length);
const error = (index: number, field: string): string | undefined => props.errors[`lines.${index}.${field}`];

function add(): void {
    if (lines.value.length < MAX_LINES) lines.value = [...lines.value, blankLine({ taxRateId: props.taxRates.find((rate) => rate.isDefault)?.id ?? '' })];
}

function remove(index: number): void {
    if (lines.value.length > 1) lines.value = lines.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="grid gap-3">
        <ol class="grid gap-3" :aria-label="t('invoicing.form.lines')">
            <li v-for="(line, index) in lines" :key="line.key" class="glass-well grid gap-3 rounded-card p-3" data-testid="invoice-line">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-6">
                    <Input v-model="line.name" class="col-span-2 sm:col-span-3" :label="t('invoicing.form.lineName', { number: format.number(index + 1) })" :error="error(index, 'name')" maxlength="180" required />
                    <Input v-model="line.quantity" inputmode="decimal" :label="t('invoicing.fields.quantity')" :error="error(index, 'quantity_milli')" mono required />
                    <Input v-model="line.unit" :label="t('invoicing.fields.unit')" :error="error(index, 'unit')" maxlength="40" optional />
                    <Input v-model="line.unitPrice" class="col-span-2 sm:col-span-1" inputmode="decimal" :label="t('invoicing.fields.unitPrice')" :error="error(index, 'unit_price_amount')" mono required />
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-6">
                    <Select v-model="line.discountMode" :options="discountOptions" :label="t('invoicing.fields.discount')" class="sm:col-span-2" />
                    <Input v-if="line.discountMode !== 'none'" v-model="line.discount" inputmode="decimal" :label="t('invoicing.fields.discountValue')" :error="error(index, 'discount_amount') ?? error(index, 'discount_bps')" mono class="sm:col-span-1" />
                    <Select v-model="line.taxRateId" :options="taxOptions" :label="t('invoicing.fields.tax')" :error="error(index, 'tax_rate_id')" class="col-span-2" />
                    <template v-if="line.taxRateId === 'custom'">
                        <Input v-model="line.taxPercent" inputmode="decimal" :label="t('invoicing.fields.taxPercent')" :error="error(index, 'tax_bps')" mono />
                        <Input v-model="line.taxName" :label="t('invoicing.fields.taxName')" :error="error(index, 'tax_name')" maxlength="80" optional />
                    </template>
                </div>
                <Input v-model="line.description" :label="t('invoicing.fields.description')" :error="error(index, 'description')" maxlength="5000" optional />
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-ink-muted">
                        {{ t('invoicing.form.lineTotal') }}
                        <span class="tabular font-mono text-ink" data-testid="line-total">
                            {{ resolved.lineTotals[index] ? format.money(resolved.lineTotals[index]!.total, currency) : t('invoicing.common.dash') }}
                        </span>
                    </p>
                    <Button variant="ghost" size="sm" :disabled="lines.length === 1" @click="remove(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('invoicing.form.removeLine', { number: format.number(index + 1) }) }}
                    </Button>
                </div>
            </li>
        </ol>

        <p v-if="errors.lines" class="text-sm text-danger" role="alert">{{ errors.lines }}</p>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button variant="secondary" size="sm" :disabled="lines.length >= MAX_LINES" @click="add">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.form.addLine') }}
            </Button>
            <p v-if="incomplete > 0" class="text-[0.8125rem] text-ink-subtle" role="status">{{ t('invoicing.form.incomplete', { count: format.number(incomplete) }) }}</p>
        </div>

        <div v-if="preview" class="border-t border-line pt-3">
            <p class="eyebrow mb-2">{{ t('invoicing.form.preview') }}</p>
            <InvoiceTotals :currency="currency" :subtotal="resolved.totals.subtotal" :discount="resolved.totals.discount" :tax="resolved.totals.tax" :total="resolved.totals.total" />
            <p class="mt-2 text-right text-[0.8125rem] text-ink-subtle">{{ t('invoicing.form.previewNote') }}</p>
        </div>
    </div>
</template>
