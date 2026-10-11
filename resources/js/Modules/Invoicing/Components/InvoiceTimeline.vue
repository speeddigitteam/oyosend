<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { InvoiceDetail } from './types';

const props = defineProps<{ invoice: InvoiceDetail }>();
const { t } = useI18n();
const format = useFormat();

interface Entry {
    key: string;
    at: string;
    label: string;
    detail?: string;
}

const entries = computed<Entry[]>(() => {
    const invoice = props.invoice;
    const items: Entry[] = [{ key: 'created', at: invoice.createdAt, label: t('invoicing.timeline.created') }];

    if (invoice.issuedAt) {
        items.push({ key: 'issued', at: invoice.issuedAt, label: t('invoicing.timeline.issued', { number: invoice.number ?? '' }) });
    }

    if (invoice.sentAt) {
        items.push({ key: 'sent', at: invoice.sentAt, label: t('invoicing.timeline.sent') });
    }

    if (invoice.overdueAt) {
        items.push({ key: 'overdue', at: invoice.overdueAt, label: t('invoicing.timeline.overdue') });
    }

    for (const payment of invoice.payments) {
        items.push({
            key: `payment-${payment.id}`,
            at: payment.createdAt,
            label: t('invoicing.timeline.payment', { amount: format.money(payment.amount, payment.currency), method: t(`invoicing.methods.${payment.method}`) }),
        });

        if (payment.reversedAt) {
            items.push({
                key: `reversed-${payment.id}`,
                at: payment.reversedAt,
                label: t('invoicing.timeline.reversed', { amount: format.money(payment.amount, payment.currency) }),
                detail: payment.reversalReason ?? undefined,
            });
        }
    }

    for (const note of invoice.creditNotes) {
        items.push({
            key: `credit-${note.id}`,
            at: `${note.issuedOn}T00:00:00Z`,
            label: t('invoicing.timeline.creditNote', { number: note.number, amount: format.money(note.totalAmount, note.currency) }),
            detail: note.reason ?? undefined,
        });
    }

    if (invoice.paidAt) {
        items.push({ key: 'paid', at: invoice.paidAt, label: t('invoicing.timeline.paid') });
    }

    if (invoice.voidedAt) {
        items.push({ key: 'void', at: invoice.voidedAt, label: t('invoicing.timeline.voided'), detail: invoice.voidReason ?? undefined });
    }

    return items.sort((a, b) => a.at.localeCompare(b.at));
});
</script>

<template>
    <Card :title="t('invoicing.timeline.title')">
        <ol class="grid gap-3" data-testid="invoice-timeline">
            <li v-for="entry in entries" :key="entry.key" class="flex gap-3">
                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="text-sm text-ink">{{ entry.label }}</p>
                    <p v-if="entry.detail" class="text-[0.8125rem] text-ink-muted">{{ entry.detail }}</p>
                    <p class="tabular text-xs text-ink-subtle">{{ format.date(entry.at, entry.key.startsWith('credit-') ? 'date' : 'datetime') }}</p>
                </div>
            </li>
        </ol>
    </Card>
</template>
