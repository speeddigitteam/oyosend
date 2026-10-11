<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { Task, TaskFormOptions, TaskPriority, TaskRecurrence, TaskStatus } from './types';
const props = withDefaults(defineProps<{ open: boolean; projectId: string; options: TaskFormOptions; task?: Task | null }>(), { task: null });
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const page = usePage();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm<{ title: string; description: string; status: TaskStatus; priority: TaskPriority; assignee_id: string; milestone_id: string; sprint_id: string; parent_id: string; starts_at: string; due_at: string; remind_at: string; estimated_minutes: string; recurrence_rule: TaskRecurrence | null }>({ title: '', description: '', status: 'todo', priority: 'normal', assignee_id: '', milestone_id: '', sprint_id: '', parent_id: '', starts_at: '', due_at: '', remind_at: '', estimated_minutes: '', recurrence_rule: null });
const recurring = ref(false);
const initialAssignee = ref('');
const statuses: TaskStatus[] = ['backlog', 'todo', 'in_progress', 'review', 'completed', 'cancelled'];
const priorities: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];
const canAssign = computed(() => props.task ? props.task.assignable === true && props.task.assignees.length <= 1 : props.options.canAssign === true);
const projectError = computed(() => (form.errors as Record<string, string>).project_id);
const parentTasks = computed(() => {
    if (!props.task) return props.options.parentTasks;
    const blocked = new Set([props.task.id]);
    let changed = true;

    while (changed) {
        changed = false;
        props.options.parentTasks.forEach((candidate) => {
            if (candidate.parentId && blocked.has(candidate.parentId) && !blocked.has(candidate.id)) {
                blocked.add(candidate.id);
                changed = true;
            }
        });
    }

    return props.options.parentTasks.filter((candidate) => !blocked.has(candidate.id));
});
const optional = (items: { id: string; name: string }[]) => [{ value: '', label: t('projects.common.notSet') }, ...items.map((item) => ({ value: item.id, label: item.name }))];
function datetimeLocal(value?: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: page.props.app.timezone,
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date);
    const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((item) => item.type === type)?.value ?? '';
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}
function editDatetime(localValue?: string | null, instant?: string | null): string {
    if (localValue && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/.test(localValue)) return localValue.slice(0, 16);
    return datetimeLocal(instant);
}
watch(() => props.open, (open) => {
    if (!open) return; const task = props.task;
    form.defaults({ title: task?.title ?? '', description: task?.description ?? '', status: task?.status ?? 'todo', priority: task?.priority ?? 'normal', assignee_id: task?.assignees[0]?.id ?? '', milestone_id: task?.milestone?.id ?? '', sprint_id: task?.sprint?.id ?? '', parent_id: task?.parentId ?? '', starts_at: editDatetime(task?.startsLocal, task?.startsAt), due_at: editDatetime(task?.dueLocal, task?.dueAt), remind_at: editDatetime(task?.remindLocal, task?.remindAt), estimated_minutes: task?.estimateMinutes?.toString() ?? '', recurrence_rule: null });
    recurring.value = task?.recurrenceRule != null;
    initialAssignee.value = task?.assignees[0]?.id ?? '';
    form.reset(); form.clearErrors();
}, { immediate: true });
function submit(): void {
    form.recurrence_rule = recurring.value ? (props.task?.recurrenceRule ?? { frequency: 'weekly', interval: 1 }) : null;
    form.transform((data) => {
        if (canAssign.value && data.assignee_id !== initialAssignee.value) return data;
        const { assignee_id: _assignee, ...safe } = data;
        return safe;
    });
    const settings = { preserveScroll: true, onSuccess: () => { model.value = false; } };
    if (props.task) form.put(route('projects.tasks.update', { project: props.projectId, task: props.task.id }), settings);
    else form.post(route('projects.tasks.store', { project: props.projectId }), settings);
}
</script>
<template>
    <Modal v-model:open="model" size="lg" :title="t(task ? 'projects.tasks.edit' : 'projects.tasks.create')" :persistent="form.processing">
        <form id="project-task-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="form.title" class="sm:col-span-2" :label="t('projects.fields.title')" :error="form.errors.title" required maxlength="240" autofocus />
            <Select v-model="form.status" :options="statuses.map((value) => ({ value, label: t(`projects.status.${value}`) }))" :label="t('projects.fields.status')" :error="form.errors.status" required />
            <Select v-model="form.priority" :options="priorities.map((value) => ({ value, label: t(`projects.status.${value}`) }))" :label="t('projects.fields.priority')" :error="form.errors.priority" required />
            <Select v-if="canAssign" v-model="form.assignee_id" :options="optional(options.members)" :label="t('projects.fields.assignee')" :error="form.errors.assignee_id" />
            <Select v-model="form.milestone_id" :options="optional(options.milestones)" :label="t('projects.fields.milestone')" :error="form.errors.milestone_id" />
            <Select v-model="form.sprint_id" :options="optional(options.sprints)" :label="t('projects.fields.sprint')" :error="form.errors.sprint_id" />
            <Select v-model="form.parent_id" :options="optional(parentTasks)" :label="t('projects.fields.parentTask')" :error="form.errors.parent_id" />
            <Input v-model="form.starts_at" type="datetime-local" :label="t('projects.fields.startDate')" :error="form.errors.starts_at" />
            <Input v-model="form.due_at" type="datetime-local" :label="t('projects.fields.dueDate')" :error="form.errors.due_at" :required="recurring" />
            <Input v-model="form.remind_at" type="datetime-local" :label="t('projects.fields.reminder')" :error="form.errors.remind_at" />
            <Input v-model="form.estimated_minutes" type="number" min="0" max="10000000" step="1" :label="t('projects.fields.estimateMinutes')" :error="form.errors.estimated_minutes" />
            <Textarea v-model="form.description" class="sm:col-span-2" :label="t('projects.fields.description')" :error="form.errors.description" optional maxlength="20000" />
            <Checkbox v-model="recurring" :label="t('projects.tasks.recurring')" />
            <p v-if="projectError" class="text-sm text-danger sm:col-span-2" role="alert">{{ projectError }}</p>
        </form>
        <template #footer><Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('projects.common.cancel') }}</Button><Button form="project-task-form" type="submit" variant="primary" :loading="form.processing">{{ t('projects.common.save') }}</Button></template>
    </Modal>
</template>

