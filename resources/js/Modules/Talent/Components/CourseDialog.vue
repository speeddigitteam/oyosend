<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';
import type { Course } from './types';

/**
 * Creates or edits a course. A validity in months makes it a certification
 * whose completions expire; a mandatory course is flagged for everyone.
 */
const props = defineProps<{ open: boolean; course: Course | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({
    title: '',
    code: '',
    provider: '',
    description: '',
    duration_minutes: '',
    validity_months: '',
    is_mandatory: false,
    is_active: true,
});

const duration = computed(() => (form.duration_minutes.trim() === '' ? null : parseInteger(form.duration_minutes)));
const validity = computed(() => (form.validity_months.trim() === '' ? null : parseInteger(form.validity_months)));
const durationInvalid = computed(() => form.duration_minutes.trim() !== '' && (duration.value === null || duration.value < 1));
const validityInvalid = computed(() => form.validity_months.trim() !== '' && (validity.value === null || validity.value < 1 || validity.value > 600));

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const course = props.course;

    form.defaults({
        title: course?.title ?? '',
        code: course?.code ?? '',
        provider: course?.provider ?? '',
        description: course?.description ?? '',
        duration_minutes: course?.durationMinutes == null ? '' : String(course.durationMinutes),
        validity_months: course?.validityMonths == null ? '' : String(course.validityMonths),
        is_mandatory: course?.isMandatory ?? false,
        is_active: course?.isActive ?? true,
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form.transform((data) => ({
        title: data.title.trim(),
        code: blank(data.code),
        provider: blank(data.provider),
        description: blank(data.description),
        duration_minutes: duration.value,
        validity_months: validity.value,
        is_mandatory: data.is_mandatory,
        is_active: data.is_active,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.course) {
        form.put(route('talent.courses.update', { course: props.course.id }), options);
    } else {
        form.post(route('talent.courses.store'), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="course ? t('talent.courses.edit') : t('talent.courses.add')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="form.title.trim() === '' || durationInvalid || validityInvalid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.title" :label="t('talent.fields.title')" maxlength="160" :error="form.errors.title" required autofocus />
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.code" :label="t('talent.courses.code')" maxlength="30" optional mono :error="form.errors.code" />
            <Input v-model="form.provider" :label="t('talent.courses.provider')" maxlength="160" optional :error="form.errors.provider" />
            <Input v-model="form.duration_minutes" inputmode="numeric" :label="t('talent.courses.duration')" :hint="t('talent.courses.durationHint')" optional :error="form.errors.duration_minutes ?? (durationInvalid ? t('talent.common.invalidNumber') : undefined)" />
            <Input v-model="form.validity_months" inputmode="numeric" :label="t('talent.courses.validity')" :hint="t('talent.courses.validityHint')" optional :error="form.errors.validity_months ?? (validityInvalid ? t('talent.common.invalidNumber') : undefined)" />
        </div>
        <Textarea v-model="form.description" :label="t('talent.fields.description')" maxlength="5000" optional :error="form.errors.description" />
        <Checkbox v-model="form.is_mandatory" :label="t('talent.courses.mandatory')" :description="t('talent.courses.mandatoryHint')" />
        <Checkbox v-model="form.is_active" :label="t('talent.fields.active')" />
    </TalentFormDialog>
</template>
