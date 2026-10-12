<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';

/**
 * Asks for an attendance correction for one day. The times are in the workspace
 * time zone; a shift that ends after midnight is entered with "ends the next day".
 */
const props = defineProps<{
    open: boolean;
    today: string;
    windowDays: number;
    /** Day to start on, e.g. the day a person clicked. */
    date?: string | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

/** Server errors for fields the form sends under another name. */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const form = useForm({ work_date: props.today, in_time: '09:00', out_time: '17:00', next_day: false, reason: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.work_date = props.date ?? props.today;
    }
}, { immediate: true });

const unusable = computed(() => form.work_date === '' || form.in_time === '' || form.out_time === '' || form.reason.trim() === '');

function nextDay(date: string): string {
    const next = new Date(`${date}T00:00:00Z`);
    next.setUTCDate(next.getUTCDate() + 1);

    return next.toISOString().slice(0, 10);
}

function submit(): void {
    form.transform((data) => ({
        work_date: data.work_date,
        clock_in_at: `${data.work_date} ${data.in_time}`,
        clock_out_at: `${data.next_day ? nextDay(data.work_date) : data.work_date} ${data.out_time}`,
        reason: data.reason.trim(),
    })).post(route('hrm.attendance.corrections.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="t('hrm.correction.request.title')"
        :description="t('hrm.correction.request.description', { count: windowDays })"
        :submit-label="t('hrm.correction.request.submit')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.work_date" type="date" :label="t('hrm.fields.date')" :max="today" :error="form.errors.work_date" required />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.in_time" type="time" :label="t('hrm.fields.clockIn')" :error="serverErrors.clock_in_at" required />
            <Input v-model="form.out_time" type="time" :label="t('hrm.fields.clockOut')" :error="serverErrors.clock_out_at" required />
        </div>
        <Checkbox v-model="form.next_day" :label="t('hrm.fields.endsNextDay')" />
        <Textarea v-model="form.reason" :label="t('hrm.fields.reason')" maxlength="1000" :error="form.errors.reason" required />
    </HrmFormDialog>
</template>
