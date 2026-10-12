<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import { priorityTone, statusTone } from './supportFormat';
import type { TicketPriority, TicketStatus } from './types';

/** A ticket's status or priority as a labelled badge (text always carries the meaning). */
const props = defineProps<{ kind: 'status' | 'priority'; value: TicketStatus | TicketPriority }>();
const { t } = useI18n();

const tone = computed(() => (props.kind === 'status' ? statusTone(props.value as TicketStatus) : priorityTone(props.value as TicketPriority)));
</script>

<template>
    <Badge :tone="tone" :dot="kind === 'status'">{{ t(`support.${kind}.${value}`) }}</Badge>
</template>
