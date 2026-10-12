<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';

/** HR adds a past attendance interval for an employee, in workspace time. */
const props = defineProps<{
    open: boolean;
    employee: { id: string; name: string } | null;
    /** The day the interval belongs to. */
    date: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

/** Server errors for fields the form sends under another name. */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const form = useForm({ date: props.date, in_time: '09:00', out_time: '17:00', next_day: false, note: '' });

watch(() => [props.open, props.date], () => {
    if (props.open) {
        form.reset();
        form.clearErrors();
        form.date = props.date;
    }
}, { immediate: true });

const unusable = computed(() => props.employee === null || form.date === '' || form.in_time === '' || form.out_time === '');

function nextDay(date: string): string {
    const next = new Date(`${date}T00:00:00Z`);
    next.setUTCDate(next.getUTCDate() + 1);

    return next.toISOString().slice(0, 10);
}

function submit(): void {
    if (props.employee === null) {
        return;
    }

    const employeeId = props.employee.id;

    form.transform((data) => ({
        employee_id: employeeId,
        clock_in_at: `${data.date} ${data.in_time}`,
        clock_out_at: `${data.next_day ? nextDay(data.date) : data.date} ${data.out_time}`,
        note: data.note.trim() === '' ? null : data.note.trim(),
    })).post(route('hrm.attendance.entries.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="t('hrm.entry.title')"
        :description="employee ? t('hrm.entry.description', { name: employee.name }) : undefined"
        :submit-label="t('hrm.entry.submit')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.date" type="date" :label="t('hrm.fields.date')" :error="serverErrors.clock_in_at" required />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.in_time" type="time" :label="t('hrm.fields.clockIn')" required />
            <Input v-model="form.out_time" type="time" :label="t('hrm.fields.clockOut')" :error="serverErrors.clock_out_at" required />
        </div>
        <Checkbox v-model="form.next_day" :label="t('hrm.fields.endsNextDay')" />
        <Input v-model="form.note" :label="t('hrm.fields.note')" optional maxlength="300" :error="form.errors.note" />
        <p v-if="serverErrors.employee_id" class="text-sm text-danger" role="alert">{{ serverErrors.employee_id }}</p>
    </HrmFormDialog>
</template>
