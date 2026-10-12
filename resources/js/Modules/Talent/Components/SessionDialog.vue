<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';
import type { SessionStatus, TrainingSession } from './types';

/** Schedules or edits a training session. Date and time are local to the workspace time zone. */
const props = defineProps<{
    open: boolean;
    courseId: string;
    session: TrainingSession | null;
    timezone: string;
    statuses: SessionStatus[];
    defaultDuration: number | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ date: '', time: '', duration_minutes: '', location: '', trainer: '', capacity: '', status: 'scheduled' as SessionStatus });

const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: t(`talent.status.session.${value}`) })));
const duration = computed(() => (form.duration_minutes.trim() === '' ? null : parseInteger(form.duration_minutes)));
const capacity = computed(() => (form.capacity.trim() === '' ? null : parseInteger(form.capacity)));
const invalid = computed(
    () => (form.duration_minutes.trim() !== '' && (duration.value === null || duration.value < 15 || duration.value > 10080))
        || (form.capacity.trim() !== '' && (capacity.value === null || capacity.value < 1)),
);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const session = props.session;

    form.defaults({
        date: session?.date ?? '',
        time: session?.time ?? '',
        duration_minutes: session ? '' : props.defaultDuration === null ? '' : String(Math.max(15, props.defaultDuration)),
        location: session?.location ?? '',
        trainer: session?.trainer ?? '',
        capacity: session?.capacity == null ? '' : String(session.capacity),
        status: session?.status ?? 'scheduled',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form.transform((data) => ({
        date: data.date,
        time: data.time,
        duration_minutes: duration.value,
        location: blank(data.location),
        trainer: blank(data.trainer),
        capacity: capacity.value,
        status: data.status,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.session) {
        form.put(route('talent.sessions.update', { session: props.session.id }), options);
    } else {
        form.post(route('talent.courses.sessions.store', { course: props.courseId }), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="session ? t('talent.courses.editSession') : t('talent.courses.addSession')"
        :description="t('talent.interviews.timezoneHint', { timezone })"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="form.date === '' || form.time === '' || invalid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.date" type="date" :label="t('talent.fields.date')" :error="form.errors.date" required autofocus />
            <Input v-model="form.time" type="time" :label="t('talent.fields.time')" :error="form.errors.time" required />
            <Input v-model="form.duration_minutes" inputmode="numeric" :label="t('talent.interviews.duration')" optional :error="form.errors.duration_minutes" />
            <Input v-model="form.capacity" inputmode="numeric" :label="t('talent.courses.capacity')" optional :error="form.errors.capacity" />
            <Input v-model="form.location" :label="t('talent.fields.location')" maxlength="255" optional :error="form.errors.location" />
            <Input v-model="form.trainer" :label="t('talent.courses.trainer')" maxlength="160" optional :error="form.errors.trainer" />
        </div>
        <Select v-if="session" v-model="form.status" :options="statusOptions" :label="t('talent.fields.status')" :error="form.errors.status" />
    </TalentFormDialog>
</template>
