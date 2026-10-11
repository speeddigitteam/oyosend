<script setup lang="ts">
import { computed } from 'vue';
import { CalendarClock, UserRound } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import ProjectStatusBadge from './ProjectStatusBadge.vue';
import type { Task, TaskStatus } from './types';

const props = defineProps<{ tasks: Task[] }>();
const emit = defineEmits<{ select: [task: Task] }>();
const { t } = useI18n();
const format = useFormat();
const columns: TaskStatus[] = ['backlog', 'todo', 'in_progress', 'review', 'completed', 'cancelled'];
const grouped = computed(() => Object.fromEntries(columns.map((status) => [status, props.tasks.filter((task) => task.status === status)])) as Record<TaskStatus, Task[]>);
</script>

<template>
    <div v-if="tasks.length" class="overflow-x-auto pb-2">
        <div role="region" class="grid min-w-[74rem] grid-cols-6 gap-3" :aria-label="t('projects.views.board')">
            <section v-for="status in columns" :key="status" class="glass-well min-w-0 rounded-card p-2" :aria-labelledby="`task-column-${status}`">
                <header class="mb-2 flex items-center justify-between gap-2 px-1 py-1">
                    <h3 :id="`task-column-${status}`" class="text-sm font-semibold text-ink">{{ t(`projects.status.${status}`) }}</h3>
                    <span class="rounded-chip bg-hover px-2 font-mono text-xs text-ink-muted">{{ format.number(grouped[status].length) }}</span>
                </header>
                <div class="grid gap-2">
                    <button v-for="task in grouped[status]" :key="task.id" type="button" class="glass rounded-control p-3 text-left transition hover:border-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" @click="emit('select', task)">
                        <span class="block text-sm font-semibold text-ink">{{ task.title }}</span>
                        <span class="mt-2 flex flex-wrap items-center gap-2"><ProjectStatusBadge :value="task.priority" /></span>
                        <span v-if="task.dueDate" class="mt-3 flex items-center gap-1.5 text-xs text-ink-subtle"><CalendarClock class="size-3.5" aria-hidden="true" />{{ format.date(task.dueDate) }}</span>
                        <span v-if="task.assignees.length" class="mt-1 flex items-center gap-1.5 text-xs text-ink-subtle"><UserRound class="size-3.5" aria-hidden="true" />{{ task.assignees.map((member) => member.name).join(', ') }}</span>
                    </button>
                    <p v-if="grouped[status].length === 0" class="rounded-control border border-dashed border-line px-3 py-5 text-center text-xs text-ink-subtle">{{ t('projects.tasks.noStatusTasks') }}</p>
                </div>
            </section>
        </div>
    </div>
    <EmptyState v-else :title="t('projects.tasks.empty.title')" :description="t('projects.tasks.empty.body')" compact><template #icon><CalendarClock /></template></EmptyState>
</template>

