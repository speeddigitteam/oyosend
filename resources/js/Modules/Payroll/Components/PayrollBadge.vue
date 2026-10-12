<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

/** A coloured status chip for any Payroll state; the label comes from `payroll.status.{kind}.{value}`. */
const props = defineProps<{
    kind: 'run' | 'period' | 'payment' | 'loan' | 'item' | 'installment' | 'runKind';
    value: string;
}>();

const { t } = useI18n();

const TONES: Record<string, Record<string, BadgeTone>> = {
    run: { draft: 'neutral', submitted: 'info', approved: 'ok' },
    period: { open: 'neutral', processing: 'info', approved: 'brand', paid: 'ok', closed: 'neutral' },
    payment: { unpaid: 'warn', paid: 'ok', failed: 'danger' },
    loan: { pending: 'info', active: 'ok', completed: 'neutral', cancelled: 'neutral', rejected: 'danger' },
    item: { pending: 'info', included: 'ok', cancelled: 'neutral' },
    installment: { pending: 'info', deducted: 'ok', cancelled: 'neutral' },
    runKind: { regular: 'neutral', adjustment: 'info', reversal: 'warn' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind]?.[props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" :dot="kind !== 'runKind'" :data-status="value">{{ t(`payroll.status.${kind}.${value}`) }}</Badge>
</template>
