<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import type { InvoiceStatus, RecurringStatus } from './types';

type Value = InvoiceStatus | RecurringStatus;

const props = defineProps<{ value: Value }>();
const { t } = useI18n();

const TONES: Record<Value, BadgeTone> = {
    draft: 'neutral',
    issued: 'info',
    partially_paid: 'warn',
    paid: 'ok',
    overdue: 'danger',
    void: 'neutral',
    active: 'ok',
    paused: 'warn',
    ended: 'neutral',
};

const tone = computed<BadgeTone>(() => TONES[props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`invoicing.status.${value}`) }}</Badge>
</template>
