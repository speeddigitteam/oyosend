<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import MoneyInput from './MoneyInput.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';
import PercentInput from './PercentInput.vue';
import type { EmployeeOption, Item, Period } from './types';

/**
 * A one-off pay item (allowance, bonus, deduction, reimbursement) for one
 * employee and pay period: a fixed amount or a share of the monthly basic.
 */
const props = defineProps<{
    open: boolean;
    item: Item | null;
    employees: EmployeeOption[];
    periods: Period[];
    types: string[];
    currency: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({
    employee_id: '',
    period_id: '',
    type: 'allowance' as string,
    name: '',
    mode: 'amount' as 'amount' | 'percent',
    amount: null as number | null,
    basic_bps: null as number | null,
    is_taxable: false,
    note: '',
});

const editing = computed(() => props.item !== null);
const employeeOptions = computed(() => props.employees.map((employee) => ({ value: employee.id, label: `${employee.name} (${employee.number})` })));
const periodOptions = computed(() => props.periods.map((period) => ({ value: period.id, label: period.name })));
const typeOptions = computed(() => props.types.map((value) => ({ value, label: t(`payroll.itemTypes.${value}`) })));
const modeOptions = computed(() => [
    { value: 'amount' as const, label: t('payroll.items.modeAmount') },
    { value: 'percent' as const, label: t('payroll.items.modePercent') },
]);
const unusable = computed(() => {
    if (form.name.trim() === '' || (!editing.value && (form.employee_id === '' || form.period_id === ''))) {
        return true;
    }

    return form.mode === 'amount' ? form.amount === null || form.amount < 1 : form.basic_bps === null || form.basic_bps < 1;
});

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const item = props.item;

    form.defaults({
        employee_id: '',
        period_id: props.periods[0]?.id ?? '',
        type: item?.type ?? props.types[0] ?? 'allowance',
        name: item?.name ?? '',
        mode: item !== null && item.basicBps !== null ? 'percent' : 'amount',
        amount: item?.amount ?? null,
        basic_bps: item?.basicBps ?? null,
        is_taxable: item?.isTaxable ?? false,
        note: item?.note ?? '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    const byAmount = form.mode === 'amount';

    form.transform((data) => ({
        ...(editing.value ? {} : { employee_id: data.employee_id, period_id: data.period_id }),
        type: data.type,
        name: data.name.trim(),
        amount: byAmount ? data.amount : null,
        basic_bps: byAmount ? null : data.basic_bps,
        is_taxable: data.is_taxable,
        note: data.note.trim() === '' ? null : data.note.trim(),
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.item === null) {
        form.post(route('payroll.items.store'), options);
    } else {
        form.put(route('payroll.items.update', { item: props.item.id }), options);
    }
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="item === null ? t('payroll.items.create') : t('payroll.items.edit')"
        :description="t('payroll.items.formBody')"
        :submit-label="item === null ? t('payroll.items.create') : t('payroll.common.saveChanges')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <template v-if="!editing">
            <Select v-model="form.employee_id" :options="employeeOptions" :placeholder="t('payroll.common.choose')" :label="t('payroll.fields.employee')" :error="form.errors.employee_id" required />
            <Select v-model="form.period_id" :options="periodOptions" :placeholder="t('payroll.common.choose')" :label="t('payroll.fields.period')" :error="form.errors.period_id" required />
        </template>
        <div class="grid gap-4 sm:grid-cols-2">
            <Select v-model="form.type" :options="typeOptions" :label="t('payroll.fields.type')" :error="form.errors.type" required />
            <Input v-model="form.name" :label="t('payroll.fields.name')" :error="form.errors.name" maxlength="160" required />
        </div>
        <SegmentedControl v-model="form.mode" :options="modeOptions" :label="t('payroll.items.mode')" />
        <MoneyInput v-if="form.mode === 'amount'" v-model="form.amount" :currency="currency" :label="t('payroll.fields.amountWithCurrency', { currency })" :error="form.errors.amount" />
        <PercentInput v-else v-model="form.basic_bps" :max="100000" :label="t('payroll.fields.percentOfBasic')" :hint="t('payroll.items.percentHint')" :error="form.errors.basic_bps" />
        <Checkbox v-model="form.is_taxable" :label="t('payroll.fields.taxable')" :description="t('payroll.items.taxableHint')" />
        <Textarea v-model="form.note" :label="t('payroll.fields.note')" :error="form.errors.note" maxlength="1000" optional />
    </PayrollFormDialog>
</template>
