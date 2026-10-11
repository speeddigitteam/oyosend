<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { currencyDigits, newIdempotencyKey, parseDecimal, toDecimal, todayIn } from './invoiceMath';
import type { PaymentMethod } from './types';

const props = defineProps<{
    open: boolean;
    invoiceId: string;
    currency: string;
    balance: number;
    methods: PaymentMethod[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();
const page = usePage();

const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const digits = computed(() => currencyDigits(props.currency));
const today = computed(() => todayIn(page.props.app.timezone));
const form = useForm({
    amount: '',
    method: 'cash' as string,
    reference: '',
    paid_on: '',
    notes: '',
    idempotency_key: '',
});
const amountError = ref('');

const methodOptions = computed(() => props.methods.map((value) => ({ value, label: t(`invoicing.methods.${value}`) })));

// A fresh key per dialog open: a retry within one open is safe, a second payment is a new key.
watch(() => props.open, (open) => {
    if (!open) return;

    form.defaults({
        amount: toDecimal(props.balance, digits.value),
        method: props.methods[0] ?? 'cash',
        reference: '',
        paid_on: today.value,
        notes: '',
        idempotency_key: newIdempotencyKey(),
    });
    form.reset();
    form.clearErrors();
    amountError.value = '';
}, { immediate: true });

function submit(): void {
    const minor = parseDecimal(form.amount, digits.value);

    if (minor === null || minor < 1) {
        amountError.value = t('invoicing.payments.amountInvalid');
        return;
    }

    if (minor > props.balance) {
        amountError.value = t('invoicing.payments.amountTooHigh', { balance: format.money(props.balance, props.currency) });
        return;
    }

    amountError.value = '';
    form.transform((data) => ({
        ...data,
        amount: minor,
        reference: data.reference === '' ? null : data.reference,
        notes: data.notes === '' ? null : data.notes,
        paid_on: data.paid_on === '' ? null : data.paid_on,
    }));
    form.post(route('invoicing.invoices.payments.store', { invoice: props.invoiceId }), {
        preserveScroll: true,
        onSuccess: () => { model.value = false; },
    });
}
</script>

<template>
    <Modal v-model:open="model" :title="t('invoicing.payments.record')" :description="t('invoicing.payments.recordHint', { balance: format.money(balance, currency) })" :persistent="form.processing">
        <form id="payment-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="form.amount" inputmode="decimal" :label="t('invoicing.payments.amountWithCurrency', { currency })" :error="amountError || form.errors.amount" mono required />
            <Select v-model="form.method" :options="methodOptions" :label="t('invoicing.fields.method')" :error="form.errors.method" required />
            <Input v-model="form.paid_on" type="date" :max="today" :label="t('invoicing.fields.paidOn')" :error="form.errors.paid_on" required />
            <Input v-model="form.reference" :label="t('invoicing.fields.paymentReference')" :error="form.errors.reference" maxlength="120" optional />
            <Textarea v-model="form.notes" class="sm:col-span-2" :label="t('invoicing.fields.notes')" :error="form.errors.notes" maxlength="2000" optional />
            <p v-if="form.errors.idempotency_key" class="text-sm text-danger sm:col-span-2" role="alert">{{ form.errors.idempotency_key }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="payment-form" variant="primary" :loading="form.processing">{{ t('invoicing.payments.save') }}</Button>
        </template>
    </Modal>
</template>
