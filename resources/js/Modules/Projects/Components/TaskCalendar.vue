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
const calendarDate = (task: Task): string | null => task.dueDate ?? task.startDate ?? null;
const dated = computed(() => [...props.tasks].filter((task) => calendarDate(task)).sort((a, b) => (calendarDate(a) ?? '').localeCompare(calendarDate(b) ?? '')));
const grouped = computed(() => {
    const result = new Map<string, Task[]>();
    dated.value.forEach((task) => { const date = calendarDate(task)!; result.set(date, [...(result.get(date) ?? []), task]); });
    return [...result.entries()];
});
</script>
<template>
    <div v-if="grouped.length" role="region" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" :aria-label="t('projects.views.calendar')">
        <section v-for="[date, entries] in grouped" :key="date" class="glass-well rounded-card p-3">
            <h3 class="eyebrow mb-2">{{ format.date(date) }}</h3>
            <ul class="grid gap-1"><li v-for="task in entries" :key="task.id"><Button size="sm" variant="ghost" class="w-full justify-start" @click="emit('select', task)">{{ task.title }}</Button></li></ul>
        </section>
    </div>
    <EmptyState v-else :title="t('projects.tasks.noDatedTasks')" :description="t('projects.tasks.noDatedTasksBody')" compact />
</template>

