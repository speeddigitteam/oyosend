<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PayrollFormDialog from './PayrollFormDialog.vue';
import { newIdempotencyKey, todayIn } from './payrollMoney';
import type { PaymentMethod } from './types';

/**
 * Records the outcome of paying out, for one payslip or for every unpaid
 * payslip of an approved run. Payroll never moves money: the person pays
 * outside the app and records what happened here.
 *
 * A fresh idempotency key is made each time the dialog opens: a retry inside
 * one opening is safe, a later decision is a new key.
 */
const props = defineProps<{
    open: boolean;
    url: string;
    currency: string;
    methods: PaymentMethod[];
    /** Net pay of the payslip, for one payslip. */
    amount?: number;
    /** Record the same outcome for every unpaid payslip of an approved run. */
    bulk?: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

const form = useForm({
    status: 'paid' as 'paid' | 'failed',
    method: 'bank' as string,
    reference: '',
    paid_on: '',
    note: '',
    idempotency_key: '',
});

const today = computed(() => todayIn(page.props.app.timezone));
const methodOptions = computed(() => props.methods.map((value) => ({ value, label: t(`payroll.methods.${value}`) })));
const statusOptions = computed(() => [
    { value: 'paid' as const, label: t('payroll.status.payment.paid') },
    { value: 'failed' as const, label: t('payroll.status.payment.failed') },
]);
const description = computed(() => {
    if (props.bulk) {
        return t('payroll.payments.bulkBody');
    }

    return props.amount === undefined ? undefined : t('payroll.payments.body', { amount: format.money(props.amount, props.currency) });
});
const runError = computed(() => (form.errors as Record<string, string | undefined>).run);
const unusable = computed(() => form.status === 'paid' && (form.method === '' || form.paid_on === ''));

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    form.defaults({
        status: 'paid',
        method: props.methods[0] ?? 'bank',
        reference: '',
        paid_on: today.value,
        note: '',
        idempotency_key: newIdempotencyKey(),
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    const paid = form.status === 'paid';

    form.transform((data) => ({
        ...data,
        method: paid ? data.method : null,
        paid_on: paid ? data.paid_on : null,
        reference: data.reference.trim() === '' ? null : data.reference.trim(),
        note: data.note.trim() === '' ? null : data.note.trim(),
    })).post(props.url, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="bulk === true ? t('payroll.payments.bulkTitle') : t('payroll.payments.title')"
        :description="description"
        :submit-label="t('payroll.payments.save')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <SegmentedControl v-model="form.status" :options="statusOptions" :label="t('payroll.payments.outcome')" />
        <template v-if="form.status === 'paid'">
            <Select v-model="form.method" :options="methodOptions" :label="t('payroll.fields.method')" :error="form.errors.method" required />
            <Input v-model="form.paid_on" type="date" :max="today" :label="t('payroll.fields.paidOn')" :error="form.errors.paid_on" required />
        </template>
        <Input v-model="form.reference" :label="t('payroll.fields.reference')" :error="form.errors.reference" maxlength="120" optional />
        <Textarea v-model="form.note" :label="t('payroll.fields.note')" :error="form.errors.note" maxlength="1000" optional />
        <p v-if="form.errors.idempotency_key" class="text-sm text-danger" role="alert">{{ form.errors.idempotency_key }}</p>
        <p v-if="runError" class="text-sm text-danger" role="alert">{{ runError }}</p>
    </PayrollFormDialog>
</template>
