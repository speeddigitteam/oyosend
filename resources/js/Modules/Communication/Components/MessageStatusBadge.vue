<script setup lang="ts">
import Badge from '@/Core/Components/Badge.vue';
import { statusTone } from './communicationFormat';
import type { MessageStatus } from './types';
import { useCommunicationLabels } from './useCommunicationLabels';

defineProps<{ status: MessageStatus }>();

const { label } = useCommunicationLabels();
</script>

<template>
    <Badge :tone="statusTone(status)" dot>{{ label('status', status) }}</Badge>
</template>
