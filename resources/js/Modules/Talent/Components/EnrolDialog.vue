<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { PersonOption, TrainingSession } from './types';

/**
 * Enrols an employee in a course, optionally into a scheduled session. A
 * person with only the `own` scope enrols themselves: the employee list then
 * holds nobody else and the field is hidden.
 */
const props = defineProps<{
    open: boolean;
    /** Fixed when opened from a course; otherwise chosen from `courses`. */
    courseId: string | null;
    courses: { id: string; title: string }[];
    sessions: TrainingSession[];
    employees: PersonOption[];
    ownEmployeeId: string | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();

const form = useForm({ course_id: '', employee_id: '', session_id: '' });

const courseOptions = computed(() => [
    { value: '', label: t('talent.common.choose'), disabled: true },
    ...props.courses.map((course) => ({ value: course.id, label: course.title })),
]);
const employeeOptions = computed(() => [
    ...(props.ownEmployeeId === null ? [{ value: '', label: t('talent.enrolments.myself') }] : []),
    ...props.employees.map((person) => ({ value: person.id, label: `${person.name} (${person.number})` })),
]);
const sessionOptions = computed(() => [
    { value: '', label: t('talent.enrolments.noSession') },
    ...props.sessions
        .filter((session) => session.status === 'scheduled' && session.courseId === (props.courseId ?? form.course_id))
        .map((session) => ({ value: session.id, label: format.date(session.date) })),
]);

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ course_id: props.courseId ?? '', employee_id: props.ownEmployeeId ?? '', session_id: '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        course_id: data.course_id,
        employee_id: data.employee_id === '' ? null : data.employee_id,
        session_id: data.session_id === '' ? null : data.session_id,
    })).post(route('talent.enrolments.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.enrolments.enrolTitle')"
        :submit-label="t('talent.enrolments.enrol')"
        :processing="form.processing"
        :disabled="form.course_id === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-if="courseId === null" v-model="form.course_id" :options="courseOptions" :label="t('talent.fields.course')" :error="form.errors.course_id" />
        <Select
            v-if="employees.length > 0"
            v-model="form.employee_id"
            :options="employeeOptions"
            :label="t('talent.fields.employee')"
            :error="form.errors.employee_id"
        />
        <Select
            v-if="sessionOptions.length > 1"
            v-model="form.session_id"
            :options="sessionOptions"
            :label="t('talent.enrolments.session')"
            :error="form.errors.session_id"
        />
    </TalentFormDialog>
</template>
