<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';
import MoneyInput from './MoneyInput.vue';
import type { EmployeeOption, NamedOption } from './types';

/**
 * A salary revision. With `employees` it gives an employee their first salary;
 * without, it adds a revision to the person on the page. Revisions are
 * immutable and must take effect after the latest one.
 */
const props = defineProps<{
    open: boolean;
    url: string;
    structures: NamedOption[];
    currency: string;
    today: string;
    /** Employees without a salary yet: present only for a first salary. */
    employees?: EmployeeOption[];
    /** Preselected employee for a first salary. */
    employeeId?: string;
    /** Prefill from the current revision. */
    structureId?: string | null;
    basicAmount?: number | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({
    employee_id: '',
    structure_id: '',
    basic_amount: null as number | null,
    effective_from: '',
    reason: '',
});

const first = computed(() => props.employees !== undefined);
const employeeOptions = computed(() => (props.employees ?? []).map((employee) => ({
    value: employee.id,
    label: `${employee.name} (${employee.number})`,
})));
const structureOptions = computed(() => props.structures.map((structure) => ({ value: structure.id, label: structure.name })));
const unusable = computed(() =>
    form.structure_id === '' || form.basic_amount === null || form.effective_from === '' || (first.value && form.employee_id === ''));

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    form.defaults({
        employee_id: props.employeeId ?? '',
        structure_id: props.structureId ?? props.structures[0]?.id ?? '',
        basic_amount: props.basicAmount ?? null,
        effective_from: props.today,
        reason: '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        ...(first.value ? { employee_id: data.employee_id } : {}),
        structure_id: data.structure_id,
        basic_amount: data.basic_amount,
        effective_from: data.effective_from,
        reason: data.reason.trim() === '' ? null : data.reason.trim(),
    })).post(props.url, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="first ? t('payroll.salaries.assignTitle') : t('payroll.salaries.reviseTitle')"
        :description="t('payroll.salaries.reviseBody')"
        :submit-label="t('payroll.salaries.saveRevision')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select
            v-if="first"
            v-model="form.employee_id"
            :options="employeeOptions"
            :placeholder="t('payroll.common.choose')"
            :label="t('payroll.fields.employee')"
            :error="form.errors.employee_id"
            required
        />
        <Select
            v-model="form.structure_id"
            :options="structureOptions"
            :placeholder="t('payroll.common.choose')"
            :label="t('payroll.fields.structure')"
            :error="form.errors.structure_id"
            required
        />
        <MoneyInput v-model="form.basic_amount" :currency="currency" :label="t('payroll.fields.basicWithCurrency', { currency })" :error="form.errors.basic_amount" />
        <Input v-model="form.effective_from" type="date" :label="t('payroll.fields.effectiveFrom')" :hint="t('payroll.salaries.effectiveHint')" :error="form.errors.effective_from" required />
        <Textarea v-model="form.reason" :label="t('payroll.fields.reason')" :error="form.errors.reason" maxlength="500" optional />
    </PayrollFormDialog>
</template>
