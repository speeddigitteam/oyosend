<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import { UNITS_PER_DAY } from './hrmFormat';
import type { LeaveType } from './types';

/**
 * Adds to or takes from a leave balance. The amount is typed in days (a half day
 * is 0.5, a deduction is negative) and sent as whole units.
 */
const props = defineProps<{
    open: boolean;
    employee: { id: string; name: string } | null;
    types: LeaveType[];
    year: number;
    /** Leave type to preselect, when the person clicked a balance cell. */
    typeId?: string | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

/** Server errors for fields the form sends under another name. */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const form = useForm({ leave_type_id: '', days: '', note: '' });
const options = computed(() => props.types.map((type) => ({ value: type.id, label: type.name })));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.leave_type_id = props.typeId ?? props.types[0]?.id ?? '';
    }
}, { immediate: true });

/** Whole units for the typed days, or null when it is zero, not a number or not a multiple of a half day. */
const units = computed<number | null>(() => {
    const text = form.days.trim().replace(',', '.');
    const value = Number(text);

    if (text === '' || !Number.isFinite(value) || value === 0) {
        return null;
    }

    const result = value * UNITS_PER_DAY;

    return Number.isInteger(result) && Math.abs(result) <= 732 ? result : null;
});

const unusable = computed(() => props.employee === null || form.leave_type_id === '' || units.value === null || form.note.trim() === '');

function submit(): void {
    if (props.employee === null || units.value === null) {
        return;
    }

    const employeeId = props.employee.id;
    const amount = units.value;

    form.transform((data) => ({
        employee_id: employeeId,
        leave_type_id: data.leave_type_id,
        units: amount,
        leave_year: props.year,
        note: data.note.trim(),
    })).post(route('hrm.leave.adjustments.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="sm"
        :title="t('hrm.balances.adjust.title')"
        :description="employee ? t('hrm.balances.adjust.description', { name: employee.name }) : undefined"
        :submit-label="t('hrm.balances.adjust.submit')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.leave_type_id" :label="t('hrm.fields.leaveType')" :options="options" :error="form.errors.leave_type_id" />
        <Input
            v-model="form.days"
            :label="t('hrm.balances.adjust.days')"
            :hint="t('hrm.balances.adjust.daysHint')"
            inputmode="decimal"
            :error="serverErrors.units"
            required
        />
        <Textarea v-model="form.note" :label="t('hrm.fields.note')" maxlength="300" :error="form.errors.note" required />
    </HrmFormDialog>
</template>
