<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import InvoiceLinesEditor from './InvoiceLinesEditor.vue';
import { blankLine, currencyDigits, fromInvoiceLine, resolveAll } from './invoiceMath';
import type { EditableLine, InvoiceDetail } from './types';

const props = defineProps<{ open: boolean; invoice: InvoiceDetail }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();

const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({ reason: '' });
const lines = ref<EditableLine[]>([blankLine()]);
const digits = computed(() => currencyDigits(props.invoice.currency));
const resolved = computed(() => resolveAll(lines.value, digits.value, []));
const overBalance = computed(() => resolved.value.valid && resolved.value.totals.total > props.invoice.balanceAmount);
const errors = computed(() => form.errors as Record<string, string>);

watch(() => props.open, (open) => {
    if (!open) return;

    form.reset();
    form.clearErrors();
    lines.value = [blankLine()];
}, { immediate: true });

function copyInvoiceLines(): void {
    lines.value = props.invoice.lines.map((line) => fromInvoiceLine(line, digits.value, []));
}

function submit(): void {
    if (!resolved.value.valid || overBalance.value) return;

    form.transform((data) => ({ ...data, lines: resolved.value.payloads }));
    form.post(route('invoicing.invoices.credit-notes.store', { invoice: props.invoice.id }), {
        preserveScroll: true,
        onSuccess: () => { model.value = false; },
    });
}
</script>

<template>
    <Modal v-model:open="model" size="lg" :title="t('invoicing.creditNotes.issue')" :description="t('invoicing.creditNotes.hint', { balance: format.money(invoice.balanceAmount, invoice.currency) })" :persistent="form.processing">
        <form id="credit-note-form" class="grid gap-4" @submit.prevent="submit">
            <Textarea v-model="form.reason" :label="t('invoicing.fields.reason')" :error="form.errors.reason" maxlength="500" required />
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-ink">{{ t('invoicing.form.lines') }}</h3>
                <Button size="sm" variant="ghost" @click="copyInvoiceLines">{{ t('invoicing.creditNotes.copyLines') }}</Button>
            </div>
            <InvoiceLinesEditor v-model="lines" :currency="invoice.currency" :tax-rates="[]" :errors="errors" />
            <p v-if="overBalance" class="text-sm text-danger" role="alert">{{ t('invoicing.creditNotes.overBalance', { balance: format.money(invoice.balanceAmount, invoice.currency) }) }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="credit-note-form" variant="primary" :loading="form.processing" :disabled="!resolved.valid || overBalance || form.reason.trim() === ''">{{ t('invoicing.creditNotes.submit') }}</Button>
        </template>
    </Modal>
</template>
