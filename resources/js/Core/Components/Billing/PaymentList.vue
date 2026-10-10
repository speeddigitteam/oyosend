<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import PaymentStatusBadge from './PaymentStatusBadge.vue';
import { useBillingTerms } from './terms';
import type { GatewayOption, PaymentSummary } from './types';

/**
 * The attempts to pay one invoice, newest first as the server sends them.
 * `#details="{ payment }"` adds lines under an entry (the panel's note and
 * bank reference).
 */
withDefaults(
    defineProps<{
        payments: PaymentSummary[];
        /** Gateways by key, to show an online payment under the provider's own name. */
        gateways?: GatewayOption[];
    }>(),
    { gateways: () => [] },
);

const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();
</script>

<template>
    <p v-if="payments.length === 0" class="px-5 pb-5 text-ink-muted">{{ t('billing.payments.empty') }}</p>

    <ul v-else class="border-t border-line">
        <li
            v-for="payment in payments"
            :key="payment.id"
            class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 border-b border-line px-5 py-3.5 last:border-b-0"
        >
            <div class="min-w-0 flex-1 basis-56">
                <p class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span class="font-medium text-ink">{{ terms.gateway(payment.gateway, gateways) }}</span>
                    <span v-if="payment.method" class="text-ink-muted">{{ terms.method(payment.method) }}</span>
                    <PaymentStatusBadge :status="payment.status" />
                </p>

                <p class="tabular mt-1 text-[0.8125rem] text-ink-subtle">
                    <time v-if="payment.paidAt" :datetime="payment.paidAt">
                        {{ t('billing.payments.paidOn', { date: format.date(payment.paidAt, 'datetime') }) }}
                    </time>
                    <time v-else :datetime="payment.createdAt">
                        {{ t('billing.payments.started', { date: format.date(payment.createdAt, 'datetime') }) }}
                    </time>
                </p>

                <p
                    v-if="payment.failureReason"
                    :class="['mt-1 text-[0.8125rem] wrap-break-word', payment.status === 'failed' ? 'text-danger' : 'text-ink-muted']"
                >
                    {{ t('billing.payments.reason', { reason: payment.failureReason }) }}
                </p>

                <slot name="details" :payment="payment" />

                <p class="mt-1 text-[0.8125rem] text-ink-subtle">
                    {{ t('billing.payments.reference') }}
                    <span class="font-mono text-xs break-all">{{ payment.reference }}</span>
                </p>
            </div>

            <p class="tabular font-medium whitespace-nowrap text-ink">{{ format.money(payment.amount, payment.currency) }}</p>
        </li>
    </ul>
</template>
