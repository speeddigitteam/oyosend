<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { Shift } from './types';

/** Gives an employee a shift from a date; a later assignment ends the earlier one. */
const props = defineProps<{
    open: boolean;
    employeeId: string;
    shifts: Shift[];
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ shift_id: '', starts_on: props.today, ends_on: '' });
const options = computed(() => props.shifts.map((shift) => ({ value: shift.id, label: shift.name })));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.shift_id = props.shifts[0]?.id ?? '';
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        employee_id: props.employeeId,
        shift_id: data.shift_id,
        starts_on: data.starts_on,
        ends_on: data.ends_on === '' ? null : data.ends_on,
    })).post(route('hrm.attendance.shift-assignments.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="t('hrm.shiftAssignment.title')"
        :description="t('hrm.shiftAssignment.description')"
        :submit-label="t('hrm.shiftAssignment.submit')"
        :processing="form.processing"
        :disabled="form.shift_id === '' || form.starts_on === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.shift_id" :label="t('hrm.fields.shift')" :options="options" :error="form.errors.shift_id" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.starts_on" type="date" :label="t('hrm.fields.from')" :error="form.errors.starts_on" required />
            <Input v-model="form.ends_on" type="date" :label="t('hrm.fields.to')" optional :error="form.errors.ends_on" />
        </div>
    </HrmFormDialog>
</template>
