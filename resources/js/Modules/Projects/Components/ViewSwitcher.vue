<script setup lang="ts">
import { CalendarDays, ChartNoAxesGantt, Columns3, List } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import type { ProjectView } from './types';
const model = defineModel<ProjectView>({ required: true });
const { t } = useI18n();
const options = computed(() => [
    { value: 'board' as const, label: t('projects.views.board'), icon: Columns3 },
    { value: 'list' as const, label: t('projects.views.list'), icon: List },
    { value: 'calendar' as const, label: t('projects.views.calendar'), icon: CalendarDays },
    { value: 'gantt' as const, label: t('projects.views.gantt'), icon: ChartNoAxesGantt },
]);
</script>
<template><SegmentedControl v-model="model" :options="options" :label="t('projects.views.label')" icon-only /></template>
