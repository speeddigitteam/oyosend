<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import MoneyInput from './MoneyInput.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';
import type { EmployeeOption } from './types';

/** Request a loan or salary advance. It needs approval before any installment is deducted. */
const props = defineProps<{
    open: boolean;
    employees: EmployeeOption[];
    kinds: string[];
    currency: string;
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({
    employee_id: '',
    kind: 'loan' as string,
    title: '',
    principal_amount: null as number | null,
    installment_count: '1',
    first_due_on: '',
    reason: '',
});

const employeeOptions = computed(() => props.employees.map((employee) => ({ value: employee.id, label: `${employee.name} (${employee.number})` })));
const kindOptions = computed(() => props.kinds.map((value) => ({ value, label: t(`payroll.loanKinds.${value}`) })));
const count = computed(() => Number(form.installment_count));
const unusable = computed(() =>
    form.employee_id === '' || form.title.trim() === '' || form.principal_amount === null || form.principal_amount < 1
    || !Number.isInteger(count.value) || count.value < 1 || count.value > 60 || form.first_due_on === '');

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    form.defaults({
        employee_id: '',
        kind: props.kinds[0] ?? 'loan',
        title: '',
        principal_amount: null,
        installment_count: '1',
        first_due_on: props.today,
        reason: '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        employee_id: data.employee_id,
        kind: data.kind,
        title: data.title.trim(),
        principal_amount: data.principal_amount,
        installment_count: Number(data.installment_count),
        first_due_on: data.first_due_on,
        reason: data.reason.trim() === '' ? null : data.reason.trim(),
    })).post(route('payroll.loans.store'), { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="t('payroll.loans.create')"
        :description="t('payroll.loans.formBody')"
        :submit-label="t('payroll.loans.submit')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.employee_id" :options="employeeOptions" :placeholder="t('payroll.common.choose')" :label="t('payroll.fields.employee')" :error="form.errors.employee_id" required />
        <div class="grid gap-4 sm:grid-cols-2">
            <Select v-model="form.kind" :options="kindOptions" :label="t('payroll.fields.kind')" :error="form.errors.kind" required />
            <Input v-model="form.title" :label="t('payroll.fields.title')" :error="form.errors.title" maxlength="160" required />
        </div>
        <MoneyInput v-model="form.principal_amount" :currency="currency" :label="t('payroll.fields.principalWithCurrency', { currency })" :error="form.errors.principal_amount" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.installment_count" type="number" min="1" max="60" inputmode="numeric" :label="t('payroll.fields.installments')" :hint="t('payroll.loans.installmentsHint')" :error="form.errors.installment_count" required />
            <Input v-model="form.first_due_on" type="date" :label="t('payroll.fields.firstDue')" :error="form.errors.first_due_on" required />
        </div>
        <Textarea v-model="form.reason" :label="t('payroll.fields.reason')" :error="form.errors.reason" maxlength="1000" optional />
    </PayrollFormDialog>
</template>
