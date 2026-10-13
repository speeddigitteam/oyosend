<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Activity, ArrowLeft, CalendarClock, Coins, Gauge, Paperclip, Pencil, Plus, Rocket, Share2, ShieldCheck, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { eventLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import ApprovalRequestDialog from '../../Components/ApprovalRequestDialog.vue';
import AttachmentsPanel from '../../Components/AttachmentsPanel.vue';
import ClientAccessDialog from '../../Components/ClientAccessDialog.vue';
import ClientAccessPanel, { type ClientAccessRow } from '../../Components/ClientAccessPanel.vue';
import PlanningActions from '../../Components/PlanningActions.vue';
import ProgressBar from '../../Components/ProgressBar.vue';
import ProjectFormDialog from '../../Components/ProjectFormDialog.vue';
import ProjectStatusBadge from '../../Components/ProjectStatusBadge.vue';
import ProjectStatusActions from '../../Components/ProjectStatusActions.vue';
import ProjectTeamPanel, { type ProjectMember } from '../../Components/ProjectTeamPanel.vue';
import TaskBoard from '../../Components/TaskBoard.vue';
import TaskCalendar from '../../Components/TaskCalendar.vue';
import TaskDetailDialog from '../../Components/TaskDetailDialog.vue';
import TaskFormDialog from '../../Components/TaskFormDialog.vue';
import TaskGantt from '../../Components/TaskGantt.vue';
import TaskList from '../../Components/TaskList.vue';
import type { Milestone, Project, ProjectAttachment, ProjectFinancials, ProjectFormOptions, ProjectView, Task, TaskFormOptions } from '../../Components/types';
import ViewSwitcher from '../../Components/ViewSwitcher.vue';

defineOptions({ layout: TenantLayout });

interface SprintRow { id: string; name: string; goal?: string | null; status: 'planned' | 'active' | 'completed' | 'cancelled'; starts_on: string; ends_on: string }
interface ActivityRow { id: string; event: string; summary: string; actor?: string | null; occurredAt: string }
interface WorkloadRow { membershipId: string; name: string; allocatedMinutes: number; taskCount: number }
interface PlanningCancellation { kind: 'milestone' | 'sprint'; id: string; name: string }

const props = defineProps<{
    project: Project;
    members: ProjectMember[];
    memberOptions: TaskFormOptions['members'];
    approvalMembers: TaskFormOptions['members'];
    tasks: Task[];
    milestones: Milestone[];
    sprints: SprintRow[];
    activity: ActivityRow[];
    financials: ProjectFinancials | null;
    workload: WorkloadRow[];
    clientAccesses: ClientAccessRow[];
    projectAttachments: ProjectAttachment[];
    projectForm: ProjectFormOptions;
    taskForm: TaskFormOptions;
    defaultView?: ProjectView;
    canManageMembers: boolean;
    canManageFinancials: boolean;
    canRequestApprovals: boolean;
    currentMembershipId: string;
}>();

const { t } = useI18n();
const format = useFormat();
const view = ref<ProjectView>(props.defaultView ?? 'board');
const projectOpen = ref(false);
const taskOpen = ref(false);
const clientOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<string | null>(null);
const editingTask = ref<Task | null>(null);
const selectedMilestone = ref<Milestone | null>(null);
const milestoneError = ref('');
const sprintError = ref('');
const planningSaving = ref(false);
const planningCancellation = ref<PlanningCancellation | null>(null);
const completedMilestones = computed(() => props.milestones.filter((item) => item.completed).length);
const canCreateTask = computed(() => props.project.taskCreatable === true && !['completed', 'cancelled'].includes(props.project.status));
const canCreatePlanning = computed(() => props.project.editable === true && !['completed', 'cancelled'].includes(props.project.status));
const selected = computed<Task | null>(() => {
    const task = props.tasks.find((item) => item.id === selectedId.value);
    return task ? { ...task, subtasks: props.tasks.filter((item) => item.parentId === task.id) } : null;
});

function select(task: Task): void { selectedId.value = task.id; detailOpen.value = true; }
function edit(task: Task): void { detailOpen.value = false; editingTask.value = task; taskOpen.value = true; }
function createTask(): void { if (!canCreateTask.value) return; editingTask.value = null; taskOpen.value = true; }
function activityLabel(entry: ActivityRow): string { const label = eventLabel(entry.event); return label === entry.event ? entry.summary : label; }
function firstError(errors: Record<string, string>): string { return errors.status ?? errors.default ?? Object.values(errors)[0] ?? ''; }
function updateMilestone(milestone: Milestone, status: 'completed' | 'cancelled'): void {
    milestoneError.value = '';
    planningSaving.value = true;
    router.patch(route('projects.milestones.status', { project: props.project.id, milestone: milestone.id }), { status }, { preserveScroll: true, onSuccess: () => { if (status === 'cancelled') planningCancellation.value = null; }, onError: (errors) => { milestoneError.value = firstError(errors); }, onFinish: () => { planningSaving.value = false; } });
}
function updateSprint(sprint: SprintRow, status: 'active' | 'completed' | 'cancelled'): void {
    sprintError.value = '';
    planningSaving.value = true;
    router.patch(route('projects.sprints.status', { project: props.project.id, sprint: sprint.id }), { status }, { preserveScroll: true, onSuccess: () => { if (status === 'cancelled') planningCancellation.value = null; }, onError: (errors) => { sprintError.value = firstError(errors); }, onFinish: () => { planningSaving.value = false; } });
}
function requestPlanningCancellation(target: PlanningCancellation): void {
    if (planningSaving.value) return;
    milestoneError.value = '';
    sprintError.value = '';
    planningCancellation.value = target;
}
function confirmPlanningCancellation(): void {
    const target = planningCancellation.value;
    if (!target || planningSaving.value) return;
    if (target.kind === 'milestone') {
        const milestone = props.milestones.find((item) => item.id === target.id);
        if (milestone) updateMilestone(milestone, 'cancelled');
    } else {
        const sprint = props.sprints.find((item) => item.id === target.id);
        if (sprint) updateSprint(sprint, 'cancelled');
    }
}
</script>

<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="project.name" :description="project.description ?? t('projects.projects.noDescription')">
        <template #actions>
            <Button variant="ghost" :href="route('projects.index')"><template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>{{ t('projects.projects.title') }}</Button>
            <Button v-if="canManageMembers" @click="clientOpen = true"><template #icon><Share2 class="size-4" aria-hidden="true" /></template>{{ t('projects.portal.share') }}</Button>
            <Button v-if="project.editable" @click="projectOpen = true"><template #icon><Pencil class="size-4" aria-hidden="true" /></template>{{ t('projects.common.edit') }}</Button>
            <Button v-if="canCreateTask" variant="primary" @click="createTask"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.tasks.create') }}</Button>
        </template>
    </PageHeader>
    <PlanningActions v-if="canCreatePlanning" :project-id="project.id" />

    <div class="grid gap-3 xl:grid-cols-[minmax(0,3fr)_19rem]">
        <div class="grid min-w-0 content-start gap-3">
            <Card>
                <div class="flex flex-wrap items-center justify-between gap-3"><div class="flex flex-wrap items-center gap-2"><ProjectStatusBadge :value="project.status" /><span class="text-sm text-ink-muted">{{ project.category?.name ?? t('projects.common.notSet') }}</span><ProjectStatusActions v-if="project.editable" :project-id="project.id" :status="project.status" /></div><ViewSwitcher v-model="view" /></div>
                <div class="mt-5"><TaskBoard v-if="view === 'board'" :tasks="tasks" :project-id="project.id" @select="select" /><TaskList v-else-if="view === 'list'" :tasks="tasks" :project-id="project.id" @select="select" /><TaskCalendar v-else-if="view === 'calendar'" :tasks="tasks" @select="select" /><TaskGantt v-else :tasks="tasks" @select="select" /></div>
            </Card>
        </div>
        <aside class="grid content-start gap-3">
            <Card :title="t('projects.projects.overview')">
                <dl class="grid gap-4"><div><dt class="eyebrow">{{ t('projects.fields.progress') }}</dt><dd class="mt-2"><ProgressBar :value="project.progress" :label="t('projects.projects.progressLabel', { name: project.name })" /></dd></div><div><dt class="eyebrow">{{ t('projects.fields.owner') }}</dt><dd class="mt-1 text-sm text-ink">{{ project.owner?.name ?? t('projects.common.unassigned') }}</dd></div><div><dt class="eyebrow">{{ t('projects.fields.client') }}</dt><dd class="mt-1 text-sm text-ink">{{ project.company?.name ?? project.contact?.name ?? t('projects.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('projects.fields.schedule') }}</dt><dd class="mt-1 flex items-center gap-1 text-sm text-ink"><CalendarClock class="size-4 text-ink-subtle" aria-hidden="true" />{{ project.startsOn ? format.date(project.startsOn) : t('projects.common.notSet') }} – {{ project.dueOn ? format.date(project.dueOn) : t('projects.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('projects.fields.members') }}</dt><dd class="mt-1 flex items-center gap-1 text-sm text-ink"><UsersRound class="size-4 text-ink-subtle" aria-hidden="true" />{{ format.number(project.members?.length ?? 0) }}</dd></div></dl>
            </Card>
            <Card :title="t('projects.milestones.title')" flush>
                <EmptyState v-if="milestones.length === 0" :title="t('projects.milestones.empty')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="milestone in milestones" :key="milestone.id" class="px-5 py-3">
                        <div class="flex items-center justify-between gap-2"><p class="text-sm font-medium text-ink">{{ milestone.name }}</p><ProjectStatusBadge :value="milestone.status ?? (milestone.completed ? 'completed' : 'open')" /></div>
                        <div class="mt-1 flex items-center justify-between gap-2"><p class="text-xs text-ink-subtle">{{ milestone.dueDate ? format.date(milestone.dueDate) : t('projects.common.notSet') }}</p><span class="font-mono text-xs text-ink-subtle">{{ format.number(milestone.completedTaskCount) }}/{{ format.number(milestone.taskCount) }}</span></div>
                        <div v-if="milestone.approvalPending || milestone.approvalRequestable || (project.editable && milestone.status === 'open')" class="mt-2 flex flex-wrap gap-1">
                            <span v-if="milestone.approvalPending" class="rounded-chip bg-warn-soft px-2 py-1 text-xs text-warn">{{ t('projects.approvals.pending') }}</span>
                            <Button v-if="milestone.approvalRequestable" size="sm" variant="ghost" @click="selectedMilestone = milestone"><ShieldCheck class="size-4" aria-hidden="true" />{{ t('projects.approvals.request') }}</Button>
                            <template v-if="project.editable && milestone.status === 'open'"><Button size="sm" variant="ghost" :disabled="planningSaving" @click="requestPlanningCancellation({ kind: 'milestone', id: milestone.id, name: milestone.name })">{{ t('projects.common.cancel') }}</Button><Button size="sm" :disabled="planningSaving" @click="updateMilestone(milestone, 'completed')">{{ t('projects.milestones.complete') }}</Button></template>
                        </div>
                    </li>
                </ul>
                <p v-if="milestoneError && planningCancellation?.kind !== 'milestone'" class="px-5 py-3 text-sm text-danger" role="alert">{{ milestoneError }}</p>
                <template #footer><p class="text-xs text-ink-subtle">{{ t('projects.milestones.completed', { done: format.number(completedMilestones), total: format.number(milestones.length) }) }}</p></template>
            </Card>
        </aside>
    </div>

    <div class="mt-3 grid gap-3 lg:grid-cols-2 xl:grid-cols-4">
        <Card :title="t('projects.sprints.title')" flush>
            <template #actions><Rocket class="size-4 text-ink-subtle" aria-hidden="true" /></template>
            <EmptyState v-if="sprints.length === 0" :title="t('projects.sprints.empty')" compact />
            <ul v-else class="divide-y divide-line"><li v-for="sprint in sprints" :key="sprint.id" class="px-5 py-3"><div class="flex items-center justify-between gap-2"><p class="text-sm font-medium text-ink">{{ sprint.name }}</p><ProjectStatusBadge :value="sprint.status" /></div><p class="mt-1 text-xs text-ink-subtle">{{ format.date(sprint.starts_on) }} – {{ format.date(sprint.ends_on) }}</p><p v-if="sprint.goal" class="mt-1 line-clamp-2 text-xs text-ink-muted">{{ sprint.goal }}</p><div v-if="project.editable && (sprint.status === 'planned' || sprint.status === 'active')" class="mt-2 flex flex-wrap gap-1"><Button size="sm" variant="ghost" :disabled="planningSaving" @click="requestPlanningCancellation({ kind: 'sprint', id: sprint.id, name: sprint.name })">{{ t('projects.common.cancel') }}</Button><Button v-if="sprint.status === 'planned'" size="sm" :disabled="planningSaving" @click="updateSprint(sprint, 'active')">{{ t('projects.sprints.start') }}</Button><Button v-else size="sm" :disabled="planningSaving" @click="updateSprint(sprint, 'completed')">{{ t('projects.sprints.complete') }}</Button></div></li></ul>
            <p v-if="sprintError && planningCancellation?.kind !== 'sprint'" class="px-5 py-3 text-sm text-danger" role="alert">{{ sprintError }}</p>
        </Card>
        <Card :title="t('projects.workload.title')" flush><template #actions><Gauge class="size-4 text-ink-subtle" aria-hidden="true" /></template><EmptyState v-if="workload.length === 0" :title="t('projects.workload.empty')" compact /><ul v-else class="divide-y divide-line"><li v-for="row in workload" :key="row.membershipId" class="px-5 py-3"><div class="flex justify-between gap-2"><p class="text-sm font-medium text-ink">{{ row.name }}</p><span class="font-mono text-sm text-ink">{{ format.number(row.allocatedMinutes / 60, { maximumFractionDigits: 1 }) }} {{ t('projects.common.hoursShort') }}</span></div><p class="mt-1 text-xs text-ink-subtle">{{ t('projects.workload.taskCount', { count: format.number(row.taskCount) }) }}</p></li></ul></Card>
        <Card :title="t('projects.financials.title')"><template #actions><Coins class="size-4 text-ink-subtle" aria-hidden="true" /></template><dl v-if="financials" class="grid gap-3 text-sm"><div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('projects.financials.budget') }}</dt><dd class="font-mono">{{ format.money(financials.budgetAmount, financials.currency) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('projects.financials.spent') }}</dt><dd class="font-mono">{{ format.money(financials.spentAmount, financials.currency) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('projects.financials.billable') }}</dt><dd class="font-mono">{{ format.money(financials.billableAmount, financials.currency) }}</dd></div><div class="flex justify-between gap-3 border-t border-line pt-3"><dt class="font-medium">{{ t('projects.financials.remaining') }}</dt><dd class="font-mono font-semibold">{{ format.money(financials.budgetRemainingAmount, financials.currency) }}</dd></div></dl><EmptyState v-else :title="t('projects.financials.unavailable')" compact /></Card>
        <Card :title="t('projects.activity.title')" flush><template #actions><Activity class="size-4 text-ink-subtle" aria-hidden="true" /></template><EmptyState v-if="activity.length === 0" :title="t('projects.activity.empty')" compact /><ol v-else class="max-h-80 divide-y divide-line overflow-y-auto"><li v-for="entry in activity" :key="entry.id" class="px-5 py-3"><p class="text-sm text-ink">{{ activityLabel(entry) }}</p><p class="mt-1 text-xs text-ink-subtle">{{ format.date(entry.occurredAt, 'datetime') }}<template v-if="entry.actor"> · {{ t('projects.activity.by', { name: entry.actor }) }}</template></p></li></ol></Card>
    </div>

    <div class="mt-3 grid items-start gap-3 lg:grid-cols-2">
        <ProjectTeamPanel :project-id="project.id" :members="members" :options="memberOptions" :can-manage="canManageMembers" />
        <Card :title="t('projects.attachments.title')"><template #actions><Paperclip class="size-4 text-ink-subtle" aria-hidden="true" /></template><AttachmentsPanel :project-id="project.id" :attachments="projectAttachments" :can-upload="Boolean(project.editable)" /></Card>
    </div>
    <div v-if="canManageMembers" class="mt-3"><ClientAccessPanel :project-id="project.id" :accesses="clientAccesses" @share="clientOpen = true" /></div>

    <ConfirmDialog :open="planningCancellation !== null" :title="t('projects.transitions.change')" :description="planningCancellation ? t('projects.transitions.confirm', { action: `${t('projects.common.cancel')} ${planningCancellation.name}` }) : undefined" :confirm-label="t('projects.common.cancel')" :loading="planningSaving" @update:open="!$event && !planningSaving && (planningCancellation = null)" @confirm="confirmPlanningCancellation"><p v-if="planningCancellation && (planningCancellation.kind === 'milestone' ? milestoneError : sprintError)" class="text-sm text-danger" role="alert">{{ planningCancellation.kind === 'milestone' ? milestoneError : sprintError }}</p></ConfirmDialog>

    <ProjectFormDialog v-if="project.editable" v-model:open="projectOpen" :project="project" :options="projectForm" :can-manage-financials="canManageFinancials" />
    <ClientAccessDialog v-if="canManageMembers" v-model:open="clientOpen" :project-id="project.id" :contacts="projectForm.contacts" :companies="projectForm.companies" :can-manage-financials="canManageFinancials" />
    <TaskFormDialog v-if="canCreateTask || editingTask?.editable" v-model:open="taskOpen" :task="editingTask" :project-id="project.id" :options="taskForm" />
    <TaskDetailDialog v-model:open="detailOpen" :task="selected" :project-id="project.id" :members="memberOptions" :approval-members="approvalMembers" :assignee-options="taskForm.members" :task-options="tasks.map((task) => ({ id: task.id, name: task.title }))" :current-membership-id="currentMembershipId" @edit="edit" />
    <ApprovalRequestDialog v-if="selectedMilestone && canRequestApprovals" :open="true" :project-id="project.id" entity-type="milestone" :entity-id="selectedMilestone.id" :subject="selectedMilestone.name" :members="approvalMembers" :current-membership-id="currentMembershipId" @update:open="!$event && (selectedMilestone = null)" />
</template>
