<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';
import type { AssigneeRole, ChecklistKind, PersonOption, Template } from './types';

/**
 * A checklist template: a name, a kind, and ordered task rows. A due offset is
 * in days from the anchor date (the joining date or last working day);
 * negative means before it.
 */
const props = defineProps<{
    open: boolean;
    template: Template | null;
    kinds: ChecklistKind[];
    roles: AssigneeRole[];
    employees: PersonOption[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

interface TaskDraft { title: string; description: string; assignee_role: AssigneeRole; assignee_employee_id: string; due_offset_days: string }

const MAX_TASKS = 60;

const form = useForm({
    name: '',
    kind: 'onboarding' as ChecklistKind,
    is_default: false,
    is_active: true,
    tasks: [] as TaskDraft[],
});

const errorFor = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

const kindOptions = computed(() => props.kinds.map((value) => ({ value, label: t(`talent.status.kind.${value}`) })));
const roleOptions = computed(() => props.roles.map((value) => ({ value, label: t(`talent.status.assigneeRole.${value}`) })));
const personOptions = computed(() => [
    { value: '', label: t('talent.common.choose'), disabled: true },
    ...props.employees.map((person) => ({ value: person.id, label: `${person.name} (${person.number})` })),
]);
const offsets = computed(() => form.tasks.map((task) => (task.due_offset_days.trim() === '' ? 0 : parseInteger(task.due_offset_days, true))));
const unusable = computed(
    () => form.name.trim() === ''
        || form.tasks.some((task, index) => task.title.trim() === ''
            || (task.assignee_role === 'person' && task.assignee_employee_id === '')
            || offsets.value[index] === null
            || Math.abs(offsets.value[index] ?? 0) > 365),
);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const template = props.template;

    form.defaults({
        name: template?.name ?? '',
        kind: template?.kind ?? 'onboarding',
        is_default: template?.isDefault ?? false,
        is_active: template?.isActive ?? true,
        tasks: template?.tasks.map((task) => ({
            title: task.title,
            description: task.description ?? '',
            assignee_role: task.assigneeRole,
            assignee_employee_id: task.assigneeEmployeeId ?? '',
            due_offset_days: task.dueOffsetDays === null ? '' : String(task.dueOffsetDays),
        })) ?? [],
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function addTask(): void {
    if (form.tasks.length < MAX_TASKS) {
        form.tasks = [...form.tasks, { title: '', description: '', assignee_role: 'hr', assignee_employee_id: '', due_offset_days: '0' }];
    }
}

function removeTask(index: number): void {
    form.tasks = form.tasks.filter((_, position) => position !== index);
}

function move(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= form.tasks.length) {
        return;
    }

    const next = [...form.tasks];
    [next[index], next[target]] = [next[target]!, next[index]!];
    form.tasks = next;
}

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        kind: data.kind,
        is_default: data.is_default,
        is_active: data.is_active,
        tasks: data.tasks.map((task) => ({
            title: task.title.trim(),
            description: task.description.trim() === '' ? null : task.description.trim(),
            assignee_role: task.assignee_role,
            assignee_employee_id: task.assignee_role === 'person' ? task.assignee_employee_id : null,
            due_offset_days: task.due_offset_days.trim() === '' ? null : parseInteger(task.due_offset_days, true),
        })),
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.template) {
        form.put(route('talent.settings.templates.update', { template: props.template.id }), options);
    } else {
        form.post(route('talent.settings.templates.store'), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="lg"
        :title="template ? t('talent.settings.editTemplate') : t('talent.settings.addTemplate')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.name" :label="t('talent.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
            <Select v-model="form.kind" :options="kindOptions" :label="t('talent.fields.kind')" :error="form.errors.kind" />
        </div>
        <div class="flex flex-wrap gap-x-6 gap-y-2">
            <Checkbox v-model="form.is_default" :label="t('talent.settings.makeDefault')" />
            <Checkbox v-model="form.is_active" :label="t('talent.fields.active')" />
        </div>

        <fieldset class="grid gap-3">
            <legend class="eyebrow mb-1">{{ t('talent.settings.templateTasks') }}</legend>
            <p v-if="form.tasks.length === 0" class="text-sm text-ink-muted">{{ t('talent.settings.noTemplateTasks') }}</p>
            <div v-for="(task, index) in form.tasks" :key="index" class="glass-well grid gap-2 rounded-control p-3" :data-testid="`template-task-${index}`">
                <div class="grid gap-2 sm:grid-cols-2">
                    <Input v-model="task.title" class="sm:col-span-2" :label="t('talent.fields.title')" maxlength="200" :error="errorFor(`tasks.${index}.title`)" />
                    <Select v-model="task.assignee_role" :options="roleOptions" :label="t('talent.fields.assignee')" :error="errorFor(`tasks.${index}.assignee_role`)" />
                    <Select
                        v-if="task.assignee_role === 'person'"
                        v-model="task.assignee_employee_id"
                        :options="personOptions"
                        :label="t('talent.checklists.person')"
                        :error="errorFor(`tasks.${index}.assignee_employee_id`)"
                    />
                    <Input
                        v-model="task.due_offset_days"
                        inputmode="numeric"
                        :label="t('talent.settings.dueOffset')"
                        :hint="t('talent.settings.dueOffsetHint')"
                        :error="errorFor(`tasks.${index}.due_offset_days`)"
                    />
                </div>
                <div class="flex flex-wrap justify-end gap-1">
                    <Button type="button" size="sm" variant="ghost" :disabled="index === 0" @click="move(index, -1)">{{ t('talent.settings.moveUp') }}</Button>
                    <Button type="button" size="sm" variant="ghost" :disabled="index === form.tasks.length - 1" @click="move(index, 1)">{{ t('talent.settings.moveDown') }}</Button>
                    <Button type="button" size="sm" variant="ghost" @click="removeTask(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.common.remove') }}
                    </Button>
                </div>
            </div>
            <div>
                <Button type="button" size="sm" variant="secondary" :disabled="form.tasks.length >= MAX_TASKS" @click="addTask">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('talent.settings.addTemplateTask') }}
                </Button>
            </div>
            <p v-if="form.errors.tasks" class="text-xs text-danger" role="alert">{{ form.errors.tasks }}</p>
        </fieldset>
    </TalentFormDialog>
</template>
