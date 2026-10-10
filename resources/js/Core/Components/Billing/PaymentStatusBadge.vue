<script setup lang="ts">
import Badge from '@/Core/Components/Badge.vue';
import { PAYMENT_TONES, useBillingTerms } from './terms';

defineProps<{ status: string }>();

const terms = useBillingTerms();
</script>

<template>
    <Badge :tone="PAYMENT_TONES[status] ?? 'neutral'" dot>{{ terms.paymentStatus(status) }}</Badge>
</template>
