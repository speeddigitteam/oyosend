<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { AssigneeRole, PersonOption } from './types';

/** Adds a task to a checklist. A task assigned to a person needs that person; the others are resolved from the employee. */
const props = defineProps<{ open: boolean; checklistId: string; employees: PersonOption[]; roles: AssigneeRole[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ title: '', description: '', assignee_role: 'hr' as AssigneeRole, assignee_employee_id: '', due_on: '' });

const roleOptions = computed(() => props.roles.map((value) => ({ value, label: t(`talent.status.assigneeRole.${value}`) })));
const personOptions = computed(() => [
    { value: '', label: t('talent.common.choose'), disabled: true },
    ...props.employees.map((person) => ({ value: person.id, label: `${person.name} (${person.number})` })),
]);
const needsPerson = computed(() => form.assignee_role === 'person');

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        title: data.title.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
        assignee_role: data.assignee_role,
        assignee_employee_id: data.assignee_role === 'person' && data.assignee_employee_id !== '' ? data.assignee_employee_id : null,
        due_on: data.due_on === '' ? null : data.due_on,
    })).post(route('talent.checklists.tasks.store', { checklist: props.checklistId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.checklists.addTask')"
        :submit-label="t('talent.checklists.addTaskSubmit')"
        :processing="form.processing"
        :disabled="form.title.trim() === '' || (needsPerson && form.assignee_employee_id === '')"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.title" :label="t('talent.fields.title')" maxlength="200" :error="form.errors.title" required autofocus />
        <Textarea v-model="form.description" :label="t('talent.fields.description')" maxlength="2000" optional :error="form.errors.description" />
        <Select v-model="form.assignee_role" :options="roleOptions" :label="t('talent.fields.assignee')" :error="form.errors.assignee_role" />
        <Select v-if="needsPerson" v-model="form.assignee_employee_id" :options="personOptions" :label="t('talent.checklists.person')" :error="form.errors.assignee_employee_id" />
        <Input v-model="form.due_on" type="date" :label="t('talent.fields.dueOn')" optional :error="form.errors.due_on" />
    </TalentFormDialog>
</template>
