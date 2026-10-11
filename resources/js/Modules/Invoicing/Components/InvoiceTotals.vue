<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';

const props = defineProps<{
    currency: string;
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
    paid?: number;
    credited?: number;
    balance?: number;
}>();

const { t } = useI18n();
const format = useFormat();

const rows = computed(() => {
    const items: { key: string; label: string; value: number; strong?: boolean }[] = [
        { key: 'subtotal', label: t('invoicing.totals.subtotal'), value: props.subtotal },
    ];

    if (props.discount > 0) items.push({ key: 'discount', label: t('invoicing.totals.discount'), value: -props.discount });

    items.push({ key: 'tax', label: t('invoicing.totals.tax'), value: props.tax });
    items.push({ key: 'total', label: t('invoicing.totals.total'), value: props.total, strong: true });

    if (props.paid !== undefined && props.paid > 0) items.push({ key: 'paid', label: t('invoicing.totals.paid'), value: -props.paid });
    if (props.credited !== undefined && props.credited > 0) items.push({ key: 'credited', label: t('invoicing.totals.credited'), value: -props.credited });
    if (props.balance !== undefined) items.push({ key: 'balance', label: t('invoicing.totals.balance'), value: props.balance, strong: true });

    return items;
});
</script>

<template>
    <dl class="ml-auto grid w-full max-w-sm gap-1.5 text-sm" data-testid="invoice-totals">
        <div v-for="row in rows" :key="row.key" :class="['flex items-baseline justify-between gap-4', row.strong ? 'border-t border-line pt-1.5 font-semibold text-ink' : 'text-ink-muted']">
            <dt>{{ row.label }}</dt>
            <dd class="tabular font-mono" :data-total="row.key">{{ format.money(row.value, currency) }}</dd>
        </div>
    </dl>
</template>
