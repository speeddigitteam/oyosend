<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { ChecklistKind, PersonOption } from './types';

/** Starts an onboarding or offboarding checklist for an employee from an active template. */
const props = defineProps<{
    open: boolean;
    employees: PersonOption[];
    templates: { id: string; name: string; kind: ChecklistKind; isDefault: boolean }[];
    kinds: ChecklistKind[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ employee_id: '', kind: 'onboarding' as ChecklistKind, template_id: '', anchor_date: '' });

const employeeOptions = computed(() => [
    { value: '', label: t('talent.common.choose'), disabled: true },
    ...props.employees.map((person) => ({ value: person.id, label: `${person.name} (${person.number})` })),
]);
const kindOptions = computed(() => props.kinds.map((value) => ({ value, label: t(`talent.status.kind.${value}`) })));
const templateOptions = computed(() => [
    { value: '', label: t('talent.checklists.defaultTemplate') },
    ...props.templates.filter((template) => template.kind === form.kind).map((template) => ({ value: template.id, label: template.name })),
]);

watch(() => form.kind, () => { form.template_id = ''; });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        employee_id: data.employee_id,
        kind: data.kind,
        template_id: data.template_id === '' ? null : data.template_id,
        anchor_date: data.anchor_date === '' ? null : data.anchor_date,
    })).post(route('talent.checklists.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.checklists.startTitle')"
        :description="t('talent.checklists.startHint')"
        :submit-label="t('talent.checklists.start')"
        :processing="form.processing"
        :disabled="form.employee_id === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.employee_id" :options="employeeOptions" :label="t('talent.fields.employee')" :error="form.errors.employee_id" />
        <Select v-model="form.kind" :options="kindOptions" :label="t('talent.fields.kind')" :error="form.errors.kind" />
        <Select v-model="form.template_id" :options="templateOptions" :label="t('talent.fields.template')" :error="form.errors.template_id ?? form.errors.kind" />
        <Input v-model="form.anchor_date" type="date" :label="t('talent.checklists.anchorDate')" :hint="t('talent.checklists.anchorHint')" optional :error="form.errors.anchor_date" />
    </TalentFormDialog>
</template>
