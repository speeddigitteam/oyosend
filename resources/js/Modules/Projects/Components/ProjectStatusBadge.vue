<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import type { ApprovalStatus, EntryStatus, MilestoneStatus, ProjectStatus, TaskPriority, TaskStatus } from './types';

type StatusValue = ProjectStatus | TaskStatus | TaskPriority | ApprovalStatus | EntryStatus | MilestoneStatus;
const props = defineProps<{ value: StatusValue }>();
const { t } = useI18n();
const tones: Partial<Record<StatusValue, BadgeTone>> = {
    active: 'ok', completed: 'ok', approved: 'ok',
    on_hold: 'warn', pending: 'warn', submitted: 'info', review: 'info', in_progress: 'info',
    urgent: 'danger', rejected: 'danger', cancelled: 'neutral',
    high: 'accent', normal: 'brand',
};
const tone = computed<BadgeTone>(() => tones[props.value] ?? 'neutral');
</script>

<template><Badge :tone="tone" dot>{{ t(`projects.status.${value}`) }}</Badge></template>

