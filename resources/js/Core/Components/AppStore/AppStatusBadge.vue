<script setup lang="ts">
import { computed } from 'vue';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import { useAppStoreTerms } from './terms';
import type { InstallationStatus } from './types';

const props = defineProps<{ status: InstallationStatus }>();

const terms = useAppStoreTerms();

const tones: Record<InstallationStatus, BadgeTone> = {
    pending_payment: 'warn',
    active: 'ok',
    inactive: 'neutral',
    uninstalled: 'neutral',
};

const tone = computed(() => tones[props.status]);
</script>

<template>
    <Badge :tone="tone" dot>{{ terms.installationStatus(status) }}</Badge>
</template>
