<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import { useFormat } from '@/Core/composables/useFormat';
import ProjectStatusBadge from './ProjectStatusBadge.vue';
import type { Task } from './types';
const props = withDefaults(defineProps<{ tasks: Task[]; projectId?: string | null }>(), { projectId: null });
const emit = defineEmits<{ select: [task: Task] }>();
const { t } = useI18n(); const format = useFormat();
const columns = computed<Column[]>(() => [
    { key: 'title', label: t('projects.fields.task') }, { key: 'status', label: t('projects.fields.status') },
    { key: 'priority', label: t('projects.fields.priority') }, { key: 'assignee', label: t('projects.fields.assignee'), hideable: true },
    { key: 'due', label: t('projects.fields.dueDate'), hideable: true }, { key: 'estimate', label: t('projects.fields.estimate'), align: 'right', hideable: true },
]);
</script>
<template>
    <DataTable :columns="columns" :rows="props.tasks" row-key="id" :caption="t('projects.tasks.caption')" storage-key="project-tasks">
        <template #cell-title="{ row }"><Button size="sm" variant="ghost" class="max-w-72 justify-start truncate" @click="emit('select', row)">{{ row.title }}</Button></template>
        <template #cell-status="{ row }"><ProjectStatusBadge :value="row.status" /></template>
        <template #cell-priority="{ row }"><ProjectStatusBadge :value="row.priority" /></template>
        <template #cell-assignee="{ row }"><span class="text-ink-muted">{{ row.assignees.map((member: { name: string }) => member.name).join(', ') || t('projects.common.unassigned') }}</span></template>
        <template #cell-due="{ row }"><span class="tabular text-ink-muted">{{ row.dueDate ? format.date(row.dueDate) : t('projects.common.notSet') }}</span></template>
        <template #cell-estimate="{ row }"><span class="font-mono text-ink-muted">{{ row.estimateMinutes ? `${format.number(row.estimateMinutes / 60, { maximumFractionDigits: 1 })} ${t('projects.common.hoursShort')}` : t('projects.common.notSet') }}</span></template>
    </DataTable>
</template>

