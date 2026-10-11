<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { Task } from './types';
const props = defineProps<{ tasks: Task[] }>();
const emit = defineEmits<{ select: [task: Task] }>();
const { t } = useI18n(); const format = useFormat();
const DAY = 86_400_000;
const ranged = computed(() => props.tasks.filter((task) => task.startDate && task.dueDate));
const start = computed(() => Math.min(...ranged.value.map((task) => new Date(task.startDate!).getTime())));
const end = computed(() => Math.max(...ranged.value.map((task) => new Date(task.dueDate!).getTime())));
const days = computed(() => ranged.value.length ? Math.max(1, Math.round((end.value - start.value) / DAY) + 1) : 0);
function bar(task: Task): { left: string; width: string } {
    const offset = Math.max(0, Math.round((new Date(task.startDate!).getTime() - start.value) / DAY));
    const span = Math.max(1, Math.round((new Date(task.dueDate!).getTime() - new Date(task.startDate!).getTime()) / DAY) + 1);
    return { left: `${(offset / days.value) * 100}%`, width: `${(span / days.value) * 100}%` };
}
function dateOnly(timestamp: number): string {
    return new Date(timestamp).toISOString().slice(0, 10);
}
</script>
<template>
    <div v-if="ranged.length" role="region" class="overflow-x-auto" :aria-label="t('projects.views.gantt')">
        <div class="min-w-[48rem]">
            <div class="mb-2 grid grid-cols-[15rem_1fr] gap-3 text-xs text-ink-subtle"><span>{{ t('projects.fields.task') }}</span><span class="flex justify-between"><span>{{ format.date(dateOnly(start)) }}</span><span>{{ format.date(dateOnly(end)) }}</span></span></div>
            <ol class="divide-y divide-line">
                <li v-for="task in ranged" :key="task.id" class="grid min-h-12 grid-cols-[15rem_1fr] items-center gap-3 py-2">
                    <Button size="sm" variant="ghost" class="min-w-0 justify-start truncate" @click="emit('select', task)">{{ task.title }}</Button>
                    <div class="relative h-6 overflow-hidden rounded-chip bg-hover"><button type="button" class="absolute top-1 h-4 min-w-3 rounded-chip bg-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" :style="bar(task)" :aria-label="t('projects.gantt.range', { task: task.title, start: format.date(task.startDate!), end: format.date(task.dueDate!) })" @click="emit('select', task)" /></div>
                </li>
            </ol>
        </div>
    </div>
    <EmptyState v-else :title="t('projects.tasks.noRangedTasks')" :description="t('projects.tasks.noRangedTasksBody')" compact />
</template>
