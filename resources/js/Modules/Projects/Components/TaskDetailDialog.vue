<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Bell, BellOff, Check, Circle, Link2, MessageSquare, Pencil, Plus, Send, ShieldCheck, UsersRound } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import ApprovalRequestDialog from './ApprovalRequestDialog.vue';
import AttachmentsPanel from './AttachmentsPanel.vue';
import ProjectStatusBadge from './ProjectStatusBadge.vue';
import TaskAssigneesDialog from './TaskAssigneesDialog.vue';
import type { Member, Option, Task } from './types';

const props = defineProps<{ open: boolean; task: Task | null; projectId: string; members: Member[]; approvalMembers: Member[]; assigneeOptions: Member[]; taskOptions: Option[]; currentMembershipId: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean]; edit: [task: Task] }>();
const { t } = useI18n();
const format = useFormat();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const approvalOpen = ref(false);
const assignmentOpen = ref(false);
const comment = useForm<{ body: string; mention_id: string; mentions?: string[]; is_internal: boolean }>({ body: '', mention_id: '', is_internal: false });
const checklist = useForm({ title: '' });
const dependency = useForm({ depends_on_task_id: '', type: 'finish_to_start' });
const mentionOptions = computed(() => [{ value: '', label: t('projects.common.notSet') }, ...props.members.map((member) => ({ value: member.id, label: member.name }))]);
const dependencyOptions = computed(() => props.taskOptions.filter((option) => option.id !== props.task?.id && !props.task?.dependencies?.some((item) => item.id === option.id)).map((option) => ({ value: option.id, label: option.name })));
const dependencyTypes = computed(() => ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'].map((type) => ({ value: type, label: t(`projects.dependencies.types.${type}`) })));

watch([() => props.open, () => props.task?.id], ([open]) => {
    if (!open) return;
    comment.reset(); comment.clearErrors();
    checklist.reset(); checklist.clearErrors();
    dependency.reset(); dependency.clearErrors();
    approvalOpen.value = false;
    assignmentOpen.value = false;
}, { immediate: true });

function toggleChecklist(id: string, completed: boolean): void {
    if (!props.task) return;
    router.patch(route('projects.tasks.checklists.update', { project: props.projectId, task: props.task.id, checklist: id }), { completed }, { preserveScroll: true });
}

function addChecklist(): void {
    if (!props.task) return;
    checklist.post(route('projects.tasks.checklists.store', { project: props.projectId, task: props.task.id }), { preserveScroll: true, onSuccess: () => checklist.reset() });
}

function addComment(): void {
    if (!props.task) return;
    comment.transform((data) => ({ body: data.body, mentions: data.mention_id ? [data.mention_id] : [], is_internal: data.is_internal }))
        .post(route('projects.tasks.comments.store', { project: props.projectId, task: props.task.id }), { preserveScroll: true, onSuccess: () => comment.reset() });
}

function addDependency(): void {
    if (!props.task) return;
    dependency.post(route('projects.tasks.dependencies.store', { project: props.projectId, task: props.task.id }), { preserveScroll: true, onSuccess: () => dependency.reset() });
}

function toggleWatch(): void {
    if (!props.task) return;
    const url = route(props.task.watching ? 'projects.tasks.unwatch' : 'projects.tasks.watch', { project: props.projectId, task: props.task.id });
    if (props.task.watching) router.delete(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}
</script>

<template>
    <Modal v-model:open="model" size="lg" :title="task?.title ?? t('projects.tasks.detail')">
        <div v-if="task" class="grid gap-5">
            <div class="flex flex-wrap gap-2"><ProjectStatusBadge :value="task.status" /><ProjectStatusBadge :value="task.priority" /><span v-if="task.approvalPending" class="rounded-chip bg-warn-soft px-2 py-1 text-xs text-warn">{{ t('projects.approvals.pending') }}</span></div>
            <p class="whitespace-pre-wrap text-sm text-ink">{{ task.description || t('projects.tasks.noDescription') }}</p>
            <dl class="grid gap-4 sm:grid-cols-3"><div><dt class="eyebrow">{{ t('projects.fields.assignee') }}</dt><dd class="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink"><span>{{ task.assignees.map((member) => member.name).join(', ') || t('projects.common.unassigned') }}</span><Button v-if="task.assignable" size="sm" variant="ghost" @click="assignmentOpen = true">{{ t('projects.assignments.manage') }}</Button></dd></div><div><dt class="eyebrow">{{ t('projects.fields.dueDate') }}</dt><dd class="mt-1 text-sm text-ink">{{ task.dueDate ? format.date(task.dueDate) : t('projects.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('projects.fields.tracked') }}</dt><dd class="mt-1 text-sm text-ink">{{ task.trackedMinutes == null ? t('projects.common.notSet') : `${format.number(task.trackedMinutes / 60, { maximumFractionDigits: 1 })} ${t('projects.common.hoursShort')}` }}</dd></div></dl>

            <section><h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-ink"><Circle class="size-4" aria-hidden="true" />{{ t('projects.tasks.checklist') }}</h3><EmptyState v-if="!task.checklist?.length" :title="t('projects.tasks.noChecklist')" compact /><ul v-else class="divide-y divide-line rounded-control border border-line"><li v-for="item in task.checklist" :key="item.id"><button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm disabled:cursor-default" :disabled="!task.editable" :aria-label="t(item.completed ? 'projects.tasks.markIncomplete' : 'projects.tasks.markComplete', { item: item.label })" @click="toggleChecklist(item.id, !item.completed)"><Check v-if="item.completed" class="size-4 text-ok" aria-hidden="true" /><Circle v-else class="size-4 text-ink-subtle" aria-hidden="true" /><span :class="item.completed ? 'text-ink-subtle line-through' : 'text-ink'">{{ item.label }}</span></button></li></ul><form v-if="task.editable" class="mt-2 flex gap-2" @submit.prevent="addChecklist"><Input v-model="checklist.title" class="flex-1" :label="t('projects.tasks.newChecklist')" :error="checklist.errors.title" required maxlength="240" /><Button class="self-end" size="sm" type="submit" :loading="checklist.processing"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.common.add') }}</Button></form></section>

            <section><h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-ink"><Link2 class="size-4" aria-hidden="true" />{{ t('projects.tasks.subtasks') }}</h3><EmptyState v-if="!task.subtasks?.length" :title="t('projects.tasks.noSubtasks')" compact /><ul v-else class="divide-y divide-line rounded-control border border-line"><li v-for="subtask in task.subtasks" :key="subtask.id" class="flex items-center justify-between gap-3 px-3 py-2"><span class="text-sm text-ink">{{ subtask.title }}</span><ProjectStatusBadge :value="subtask.status" /></li></ul></section>

            <section><h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-ink"><UsersRound class="size-4" aria-hidden="true" />{{ t('projects.tasks.dependencies') }}</h3><p v-if="task.dependencies?.length" class="text-sm text-ink-muted">{{ task.dependencies.map((item) => item.name).join(', ') }}</p><EmptyState v-else :title="t('projects.dependencies.empty')" compact /><form v-if="task.editable && dependencyOptions.length" class="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="addDependency"><Select v-model="dependency.depends_on_task_id" :options="dependencyOptions" :label="t('projects.dependencies.task')" :error="dependency.errors.depends_on_task_id" required /><Select v-model="dependency.type" :options="dependencyTypes" :label="t('projects.dependencies.type')" :error="dependency.errors.type" required /><Button class="self-end" size="sm" type="submit" :loading="dependency.processing">{{ t('projects.dependencies.add') }}</Button></form></section>

            <section><h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-ink"><MessageSquare class="size-4" aria-hidden="true" />{{ t('projects.comments.title') }}</h3><EmptyState v-if="!task.comments?.length" :title="t('projects.comments.empty')" compact /><ol v-else class="max-h-64 divide-y divide-line overflow-y-auto rounded-control border border-line"><li v-for="entry in task.comments" :key="entry.id" class="px-3 py-2"><div class="flex flex-wrap items-center justify-between gap-2"><p class="text-xs font-semibold text-ink">{{ entry.author }}<span v-if="entry.client" class="ml-1 text-brand-text">{{ t('projects.comments.client') }}</span><span v-if="entry.internal" class="ml-1 text-warn">{{ t('projects.comments.internal') }}</span></p><time class="text-xs text-ink-subtle">{{ format.date(entry.createdAt, 'datetime') }}</time></div><p class="mt-1 whitespace-pre-wrap text-sm text-ink-muted">{{ entry.body }}</p></li></ol><form v-if="task.commentable" class="mt-2 grid gap-2" @submit.prevent="addComment"><Textarea v-model="comment.body" :label="t('projects.comments.add')" :error="comment.errors.body" required maxlength="20000" /><div class="grid gap-2 sm:grid-cols-2"><Select v-model="comment.mention_id" :options="mentionOptions" :label="t('projects.comments.mention')" :error="comment.errors.mentions" /><Checkbox v-model="comment.is_internal" :label="t('projects.comments.internalOnly')" /></div><div class="flex justify-end"><Button size="sm" type="submit" :loading="comment.processing"><template #icon><Send class="size-4" aria-hidden="true" /></template>{{ t('projects.comments.send') }}</Button></div></form></section>

            <section><h3 class="mb-2 text-sm font-semibold text-ink">{{ t('projects.attachments.title') }}</h3><AttachmentsPanel :project-id="projectId" :task-id="task.id" :attachments="task.attachments ?? []" :can-upload="Boolean(task.editable)" /></section>
        </div>
        <template #footer><Button v-if="task" variant="ghost" @click="toggleWatch"><template #icon><component :is="task.watching ? BellOff : Bell" class="size-4" aria-hidden="true" /></template>{{ t(task.watching ? 'projects.tasks.unwatch' : 'projects.tasks.watch') }}</Button><Button v-if="task?.approvalRequestable" variant="ghost" @click="approvalOpen = true"><template #icon><ShieldCheck class="size-4" aria-hidden="true" /></template>{{ t('projects.approvals.request') }}</Button><Button variant="ghost" @click="model = false">{{ t('projects.common.close') }}</Button><Button v-if="task?.editable" variant="primary" @click="emit('edit', task)"><template #icon><Pencil class="size-4" aria-hidden="true" /></template>{{ t('projects.common.edit') }}</Button></template>
    </Modal>
    <ApprovalRequestDialog v-if="task" v-model:open="approvalOpen" :project-id="projectId" entity-type="task" :entity-id="task.id" :subject="task.title" :members="approvalMembers" :current-membership-id="currentMembershipId" />
    <TaskAssigneesDialog v-if="task" v-model:open="assignmentOpen" :project-id="projectId" :task="task" :members="assigneeOptions" />
</template>
