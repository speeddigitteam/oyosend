<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/Core/Components/Badge.vue';
import { INVOICE_TONES, useBillingTerms } from './terms';

/**
 * An invoice's status as a badge. An unpaid invoice past its due date reads
 * "Overdue": that is what the person has to act on.
 */
const props = defineProps<{
    status: string;
    overdue?: boolean;
}>();

const terms = useBillingTerms();

const shown = computed(() => (props.status === 'open' && props.overdue ? 'overdue' : props.status));
</script>

<template>
    <Badge :tone="INVOICE_TONES[shown] ?? 'neutral'" dot>{{ terms.invoiceStatus(status, shown === 'overdue') }}</Badge>
</template>
