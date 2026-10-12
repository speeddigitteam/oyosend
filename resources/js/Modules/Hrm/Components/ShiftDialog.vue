<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import { WEEKDAYS, useHrmFormat } from './hrmFormat';
import type { Shift } from './types';

/**
 * A shift is a local start and end time with grace periods. An end at or before
 * the start means the next day.
 */
const props = defineProps<{ open: boolean; shift: Shift | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const hrm = useHrmFormat();

const form = useForm({
    name: '',
    starts_at: '09:00',
    ends_at: '17:00',
    break_minutes: '60',
    late_grace_minutes: '10',
    early_grace_minutes: '0',
    overtime_threshold_minutes: '30',
    half_day_minutes: '',
    own_days: false,
    weekly_off_days: [] as number[],
    is_default: false,
    is_active: true,
});

watch(() => props.open, (open) => {
    if (open) {
        const shift = props.shift;
        form.clearErrors();
        form.name = shift?.name ?? '';
        form.starts_at = shift?.startsAt ?? '09:00';
        form.ends_at = shift?.endsAt ?? '17:00';
        form.break_minutes = String(shift?.breakMinutes ?? 60);
        form.late_grace_minutes = String(shift?.lateGraceMinutes ?? 10);
        form.early_grace_minutes = String(shift?.earlyGraceMinutes ?? 0);
        form.overtime_threshold_minutes = String(shift?.overtimeThresholdMinutes ?? 30);
        form.half_day_minutes = shift?.halfDayMinutes == null ? '' : String(shift.halfDayMinutes);
        form.own_days = shift?.weeklyOffDays != null;
        form.weekly_off_days = [...(shift?.weeklyOffDays ?? [])];
        form.is_default = shift?.isDefault ?? false;
        form.is_active = shift?.isActive ?? true;
    }
}, { immediate: true });

const days = computed(() => WEEKDAYS.map((day) => ({ day, label: hrm.weekday(day, 'long') })));

function toggle(day: number, on: boolean): void {
    form.weekly_off_days = on
        ? [...form.weekly_off_days, day].sort((a, b) => a - b)
        : form.weekly_off_days.filter((value) => value !== day);
}

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const transformed = form.transform((data) => ({
        name: data.name.trim(),
        starts_at: data.starts_at,
        ends_at: data.ends_at,
        break_minutes: Number(data.break_minutes),
        late_grace_minutes: Number(data.late_grace_minutes),
        early_grace_minutes: Number(data.early_grace_minutes),
        overtime_threshold_minutes: Number(data.overtime_threshold_minutes),
        half_day_minutes: data.half_day_minutes.trim() === '' ? null : Number(data.half_day_minutes),
        weekly_off_days: data.own_days ? data.weekly_off_days : null,
        is_default: data.is_default,
        is_active: data.is_active,
    }) as never);

    if (props.shift) {
        transformed.put(route('hrm.settings.shifts.update', { shift: props.shift.id }), options);
    } else {
        transformed.post(route('hrm.settings.shifts.store'), options);
    }
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="lg"
        :title="shift ? t('hrm.settings.shifts.edit') : t('hrm.settings.shifts.add')"
        :submit-label="t('hrm.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || form.starts_at === '' || form.ends_at === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="100" :error="form.errors.name" required autofocus />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.starts_at" type="time" :label="t('hrm.fields.shiftStarts')" :error="form.errors.starts_at" required />
            <Input v-model="form.ends_at" type="time" :label="t('hrm.fields.shiftEnds')" :hint="t('hrm.settings.shifts.endsHint')" :error="form.errors.ends_at" required />
            <Input v-model="form.break_minutes" type="number" min="0" max="480" :label="t('hrm.fields.breakMinutes')" :error="form.errors.break_minutes" />
            <Input v-model="form.late_grace_minutes" type="number" min="0" max="240" :label="t('hrm.fields.lateGrace')" :error="form.errors.late_grace_minutes" />
            <Input v-model="form.early_grace_minutes" type="number" min="0" max="240" :label="t('hrm.fields.earlyGrace')" :error="form.errors.early_grace_minutes" />
            <Input v-model="form.overtime_threshold_minutes" type="number" min="0" max="480" :label="t('hrm.fields.overtimeThreshold')" :error="form.errors.overtime_threshold_minutes" />
            <Input v-model="form.half_day_minutes" type="number" min="1" max="1440" :label="t('hrm.fields.halfDayMinutes')" optional :hint="t('hrm.settings.shifts.halfDayHint')" :error="form.errors.half_day_minutes" />
        </div>
        <fieldset class="grid gap-2">
            <legend class="mb-1 text-sm font-medium text-ink">{{ t('hrm.fields.weeklyOff') }}</legend>
            <Checkbox v-model="form.own_days" :label="t('hrm.settings.shifts.ownDays')" />
            <div v-if="form.own_days" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Checkbox
                    v-for="item in days"
                    :key="item.day"
                    :model-value="form.weekly_off_days.includes(item.day)"
                    :label="item.label"
                    @update:model-value="toggle(item.day, $event)"
                />
            </div>
            <p v-if="form.errors.weekly_off_days" class="text-sm text-danger" role="alert">{{ form.errors.weekly_off_days }}</p>
        </fieldset>
        <div class="flex flex-wrap gap-x-6 gap-y-2">
            <Checkbox v-model="form.is_default" :label="t('hrm.settings.shifts.makeDefault')" />
            <Checkbox v-model="form.is_active" :label="t('hrm.fields.active')" />
        </div>
    </HrmFormDialog>
</template>
