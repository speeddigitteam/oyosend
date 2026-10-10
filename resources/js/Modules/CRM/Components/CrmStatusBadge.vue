<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import type { ActivityPriority, ActivityStatus, ContractStatus, CustomerLifecycleStage, DealStatus, LeadStatus, QuoteStatus, SalesOrderStatus } from './types';

type Status = LeadStatus | DealStatus | ActivityStatus | ActivityPriority | QuoteStatus | SalesOrderStatus | ContractStatus | CustomerLifecycleStage;

const props = defineProps<{ status: Status }>();
const { t } = useI18n();

const tones: Partial<Record<Status, BadgeTone>> = {
    new: 'info',
    contacted: 'accent',
    qualified: 'ok',
    disqualified: 'danger',
    converted: 'brand',
    open: 'info',
    won: 'ok',
    lost: 'danger',
    planned: 'info',
    completed: 'ok',
    canceled: 'neutral',
    low: 'neutral',
    normal: 'info',
    high: 'warn',
    urgent: 'danger',
    draft: 'neutral',
    sent: 'info',
    accepted: 'ok',
    rejected: 'danger',
    expired: 'warn',
    confirmed: 'brand',
    fulfilled: 'ok',
    active: 'ok',
    terminated: 'danger',
    lead: 'info',
    prospect: 'accent',
    customer: 'ok',
    inactive: 'neutral',
    churned: 'danger',
};

const tone = computed(() => tones[props.status] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`crm.status.${status}`) }}</Badge>
</template>
