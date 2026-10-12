<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BarMeter from '../../Components/BarMeter.vue';
import NoteDialog from '../../Components/NoteDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import TaskDialog from '../../Components/TaskDialog.vue';
import type { AssigneeRole, ChecklistRow, ChecklistTask, PersonOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    checklist: ChecklistRow;
    tasks: ChecklistTask[];
    abilities: { manage: boolean; cancel: boolean; addTask: boolean };
    employees: PersonOption[];
    assigneeRoles: AssigneeRole[];
}>();

const { t } = useI18n();
const format = useFormat();

const addingTask = ref(false);
const cancelling = ref(false);
const skipping = ref<ChecklistTask | null>(null);
const skipOpen = ref(false);
const busy = ref<string | null>(null);

const progress = computed(() => (props.checklist.taskCount === 0 ? 0 : Math.floor((props.checklist.doneCount * 10000) / props.checklist.taskCount)));

function post(name: 'talent.tasks.complete' | 'talent.tasks.reopen', task: ChecklistTask): void {
    busy.value = task.id;
    router.post(route(name, { task: task.id }), {}, { preserveScroll: true, onFinish: () => { busy.value = null; } });
}

function askSkip(task: ChecklistTask): void {
    skipping.value = task;
    skipOpen.value = true;
}

function cancelChecklist(): void {
    router.post(route('talent.checklists.cancel', { checklist: props.checklist.id }), {}, {
        preserveScroll: true,
        onFinish: () => { cancelling.value = false; },
    });
}

function assigneeText(task: ChecklistTask): string {
    return task.assignee?.name ?? t(`talent.status.assigneeRole.${task.assigneeRole}`);
}
</script>

<template>
    <PageHeader
        :eyebrow="t(`talent.status.kind.${checklist.kind}`)"
        :title="checklist.name"
        :description="checklist.employee ? checklist.employee.name : undefined"
    >
        <template v-if="abilities.addTask || abilities.cancel" #actions>
            <Button v-if="abilities.addTask" variant="secondary" @click="addingTask = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.checklists.addTask') }}
            </Button>
            <Button v-if="abilities.cancel" variant="danger" @click="cancelling = true">{{ t('talent.checklists.cancel') }}</Button>
        </template>
    </PageHeader>

    <Card class="mb-3">
        <div class="flex flex-wrap items-center gap-4">
            <TalentBadge kind="checklist" :value="checklist.status" />
            <p class="tabular text-sm text-ink-muted">{{ t('talent.checklists.doneOf', { done: format.number(checklist.doneCount), total: format.number(checklist.taskCount) }) }}</p>
            <p class="text-sm text-ink-muted">{{ t('talent.checklists.anchorOn', { date: format.date(checklist.anchorDate) }) }}</p>
            <div class="min-w-40 flex-1">
                <BarMeter :bp="progress" :label="t('talent.checklists.progressOf', { name: checklist.name })" />
            </div>
        </div>
    </Card>

    <Card flush :title="t('talent.checklists.tasks')">
        <EmptyState v-if="tasks.length === 0" :title="t('talent.checklists.noTasks')" :description="t('talent.checklists.noTasksBody')" compact class="p-5" />
        <ul v-else class="divide-y divide-line" data-testid="checklist-tasks">
            <li v-for="task in tasks" :key="task.id" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                <div class="min-w-0">
                    <p class="text-sm" :class="task.status === 'pending' ? 'text-ink' : 'text-ink-muted line-through'">{{ task.title }}</p>
                    <p v-if="task.description" class="mt-0.5 text-xs text-ink-subtle">{{ task.description }}</p>
                    <p class="mt-1 text-xs text-ink-subtle">
                        {{ assigneeText(task) }}
                        <template v-if="task.dueOn"> - {{ t('talent.checklists.due', { date: format.date(task.dueOn) }) }}</template>
                        <template v-if="task.completedAt"> - {{ t('talent.checklists.completedOn', { date: format.date(task.completedAt) }) }}</template>
                    </p>
                    <p v-if="task.note" class="mt-1 text-xs text-ink-muted">{{ task.note }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-1">
                    <TalentBadge kind="task" :value="task.status" />
                    <Button
                        v-if="task.status === 'pending' && task.canComplete"
                        size="sm"
                        variant="secondary"
                        :loading="busy === task.id"
                        @click="post('talent.tasks.complete', task)"
                    >
                        {{ t('talent.checklists.completeTask') }}
                    </Button>
                    <Button
                        v-if="task.status !== 'pending' && task.canComplete"
                        size="sm"
                        variant="ghost"
                        :loading="busy === task.id"
                        @click="post('talent.tasks.reopen', task)"
                    >
                        {{ t('talent.checklists.reopenTask') }}
                    </Button>
                    <Button v-if="task.status === 'pending' && task.canSkip" size="sm" variant="ghost" @click="askSkip(task)">
                        {{ t('talent.checklists.skipTask') }}
                    </Button>
                </div>
            </li>
        </ul>
    </Card>

    <TaskDialog v-if="abilities.addTask" v-model:open="addingTask" :checklist-id="checklist.id" :employees="employees" :roles="assigneeRoles" />
    <NoteDialog
        v-if="skipping"
        v-model:open="skipOpen"
        :url="route('talent.tasks.skip', { task: skipping.id })"
        :title="t('talent.checklists.skipTitle')"
        :description="skipping.title"
        :submit-label="t('talent.checklists.skipTask')"
    />
    <ConfirmDialog
        v-model:open="cancelling"
        :title="t('talent.checklists.cancelTitle')"
        :description="t('talent.checklists.cancelBody')"
        :confirm-label="t('talent.checklists.cancel')"
        @confirm="cancelChecklist"
    />
</template>
