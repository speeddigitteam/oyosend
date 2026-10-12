<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { CycleRow, NamedRef, ReviewFormDetail } from './types';

/** Creates or edits a review cycle while it is still a draft. Launching creates one review per employee in scope. */
const props = defineProps<{
    open: boolean;
    cycle: CycleRow | null;
    forms: ReviewFormDetail[];
    departments: NamedRef[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({
    name: '',
    form_id: '',
    starts_on: '',
    ends_on: '',
    self_due_on: '',
    manager_due_on: '',
    requires_self_review: true,
    department_id: '',
});

const formOptions = computed(() => [
    { value: '', label: t('talent.common.choose'), disabled: true },
    ...props.forms.filter((item) => item.isActive || item.id === props.cycle?.formId).map((item) => ({ value: item.id, label: item.name })),
]);
const departmentOptions = computed(() => [{ value: '', label: t('talent.cycles.allDepartments') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);
const unusable = computed(() => form.name.trim() === '' || form.form_id === '' || form.starts_on === '' || form.ends_on === '' || form.ends_on < form.starts_on);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const cycle = props.cycle;

    form.defaults({
        name: cycle?.name ?? '',
        form_id: cycle?.formId ?? '',
        starts_on: cycle?.startsOn ?? '',
        ends_on: cycle?.endsOn ?? '',
        self_due_on: cycle?.selfDueOn ?? '',
        manager_due_on: cycle?.managerDueOn ?? '',
        requires_self_review: cycle?.requiresSelfReview ?? true,
        department_id: cycle?.departmentId ?? '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function blank(value: string): string | null {
    return value === '' ? null : value;
}

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        form_id: data.form_id,
        starts_on: data.starts_on,
        ends_on: data.ends_on,
        self_due_on: blank(data.self_due_on),
        manager_due_on: blank(data.manager_due_on),
        requires_self_review: data.requires_self_review,
        department_id: blank(data.department_id),
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.cycle) {
        form.put(route('talent.cycles.update', { cycle: props.cycle.id }), options);
    } else {
        form.post(route('talent.cycles.store'), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="cycle ? t('talent.cycles.edit') : t('talent.cycles.add')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('talent.fields.name')" maxlength="160" :error="form.errors.name" required autofocus />
        <Select v-model="form.form_id" :options="formOptions" :label="t('talent.cycles.form')" :error="form.errors.form_id" />
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.starts_on" type="date" :label="t('talent.fields.startsOn')" :error="form.errors.starts_on" required />
            <Input v-model="form.ends_on" type="date" :label="t('talent.fields.endsOn')" :min="form.starts_on" :error="form.errors.ends_on" required />
            <Input v-model="form.self_due_on" type="date" :label="t('talent.cycles.selfDue')" optional :error="form.errors.self_due_on" />
            <Input v-model="form.manager_due_on" type="date" :label="t('talent.cycles.managerDue')" optional :error="form.errors.manager_due_on" />
        </div>
        <Select v-model="form.department_id" :options="departmentOptions" :label="t('talent.fields.department')" :error="form.errors.department_id" />
        <Checkbox v-model="form.requires_self_review" :label="t('talent.cycles.requiresSelf')" :description="t('talent.cycles.requiresSelfHint')" />
    </TalentFormDialog>
</template>
