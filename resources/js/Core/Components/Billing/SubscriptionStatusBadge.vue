<script setup lang="ts">
import Badge from '@/Core/Components/Badge.vue';
import { SUBSCRIPTION_TONES, useBillingTerms } from './terms';

/**
 * Where a subscription stands, as a badge. Null is a workspace without one.
 */
defineProps<{ status: string | null }>();

const terms = useBillingTerms();
</script>

<template>
    <Badge :tone="status === null ? 'neutral' : (SUBSCRIPTION_TONES[status] ?? 'neutral')" :dot="status !== null">
        {{ terms.subscriptionStatus(status) }}
    </Badge>
</template>
