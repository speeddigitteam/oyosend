<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { countryName } from '@/Core/lib/countries';
import InvoiceStatusBadge from './InvoiceStatusBadge.vue';
import { useBillingTerms } from './terms';
import type { InvoiceDocumentData } from './types';

/**
 * An invoice as a document: who issued it, who it is made out to, what it is
 * for and what it comes to. Everything on it is a snapshot taken when it was
 * issued, so it reads the same however the workspace or the plan changes.
 *
 * When the page is printed, this is the only thing on the paper.
 */
const props = defineProps<{ invoice: InvoiceDocumentData }>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

const issuer = computed(() => props.invoice.issuer ?? {});
const recipient = computed(() => props.invoice.billedTo ?? {});

/** City and postal code share a line, as on an envelope. */
const locality = computed(() => [recipient.value.city, recipient.value.postal_code].filter(Boolean).join(' '));
const country = computed(() => countryName(recipient.value.country, page.props.app.locale));

const lines = computed(() =>
    props.invoice.lines.map((line, index) => ({
        key: String(index),
        description: terms.line(line),
        amount: line.amount,
    })),
);

const columns = computed<Column[]>(() => [
    { key: 'description', label: t('billing.document.description') },
    { key: 'amount', label: t('billing.document.amount'), align: 'right', class: 'whitespace-nowrap' },
]);

const period = computed(() => terms.period(props.invoice.periodStart, props.invoice.periodEnd));

const totalLabel = computed(() => {
    if (props.invoice.status === 'open') {
        return t('billing.document.totalDue');
    }

    return props.invoice.status === 'paid' ? t('billing.document.totalPaid') : t('billing.document.total');
});

function money(amount: number): string {
    return format.money(amount, props.invoice.currency);
}
</script>

<template>
    <Card as="article" flush data-invoice-document>
        <header class="flex flex-wrap items-start justify-between gap-x-8 gap-y-5 px-5 pt-5 pb-5">
            <div class="min-w-0">
                <p class="eyebrow">{{ t('billing.document.from') }}</p>
                <p class="mt-1.5 font-display text-lg font-semibold tracking-tight wrap-break-word text-ink">{{ issuer.name }}</p>
                <p v-if="issuer.address" class="wrap-break-word whitespace-pre-line text-ink-muted">{{ issuer.address }}</p>
                <p v-if="issuer.email" class="break-all text-ink-muted">{{ issuer.email }}</p>
                <p v-if="issuer.tax_id" class="text-ink-muted">{{ t('billing.document.taxId', { id: issuer.tax_id }) }}</p>
            </div>

            <div class="flex flex-col items-start gap-1.5 sm:items-end">
                <p class="eyebrow">{{ t('billing.document.heading') }}</p>
                <p class="font-mono text-lg font-semibold tracking-tight text-ink">{{ invoice.number }}</p>
                <InvoiceStatusBadge :status="invoice.status" :overdue="invoice.overdue" />
            </div>
        </header>

        <div class="grid gap-x-8 gap-y-5 border-t border-line px-5 py-5 sm:grid-cols-2">
            <div class="min-w-0">
                <p class="eyebrow">{{ t('billing.document.billedTo') }}</p>
                <p class="mt-1.5 font-medium wrap-break-word text-ink">{{ recipient.name }}</p>
                <p v-if="recipient.address" class="wrap-break-word whitespace-pre-line text-ink-muted">{{ recipient.address }}</p>
                <p v-if="locality" class="text-ink-muted">{{ locality }}</p>
                <p v-if="country" class="text-ink-muted">{{ country }}</p>
                <p v-if="recipient.email" class="break-all text-ink-muted">{{ recipient.email }}</p>
                <p v-if="recipient.phone" class="tabular text-ink-muted">{{ recipient.phone }}</p>
                <p v-if="recipient.tax_id" class="text-ink-muted">{{ t('billing.document.taxId', { id: recipient.tax_id }) }}</p>
            </div>

            <dl class="grid grid-cols-[auto_minmax(0,1fr)] content-start gap-x-6 gap-y-1.5">
                <dt class="text-ink-subtle">{{ t('billing.document.kind') }}</dt>
                <dd class="text-ink">{{ terms.invoiceKind(invoice.kind) }}</dd>

                <dt class="text-ink-subtle">{{ t('billing.document.issued') }}</dt>
                <dd class="tabular text-ink">
                    <time :datetime="invoice.issuedAt">{{ format.date(invoice.issuedAt) }}</time>
                </dd>

                <template v-if="invoice.dueAt">
                    <dt class="text-ink-subtle">{{ t('billing.document.due') }}</dt>
                    <dd :class="['tabular', invoice.overdue ? 'font-medium text-danger' : 'text-ink']">
                        <time :datetime="invoice.dueAt">{{ format.date(invoice.dueAt) }}</time>
                    </dd>
                </template>

                <template v-if="invoice.paidAt">
                    <dt class="text-ink-subtle">{{ t('billing.document.paid') }}</dt>
                    <dd class="tabular text-ink">
                        <time :datetime="invoice.paidAt">{{ format.date(invoice.paidAt) }}</time>
                    </dd>
                </template>

                <template v-if="invoice.voidedAt">
                    <dt class="text-ink-subtle">{{ t('billing.document.withdrawn') }}</dt>
                    <dd class="tabular text-ink">
                        <time :datetime="invoice.voidedAt">{{ format.date(invoice.voidedAt) }}</time>
                    </dd>
                </template>

                <template v-if="period">
                    <dt class="text-ink-subtle">{{ t('billing.document.period') }}</dt>
                    <dd class="tabular text-ink">{{ period }}</dd>
                </template>
            </dl>
        </div>

        <DataTable :columns="columns" :rows="lines" row-key="key" :caption="t('billing.document.lines')">
            <template #cell-description="{ row }">
                <span class="text-ink">{{ row.description }}</span>
            </template>
            <template #cell-amount="{ row }">
                <span class="tabular text-ink">{{ money(row.amount) }}</span>
            </template>
        </DataTable>

        <dl class="ml-auto grid w-full max-w-sm grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1.5 border-t border-line px-5 py-5">
            <template v-if="invoice.creditAmount > 0">
                <dt class="text-ink-muted">{{ t('billing.document.subtotal') }}</dt>
                <dd class="tabular text-right text-ink">{{ money(invoice.subtotalAmount) }}</dd>

                <dt class="text-ink-muted">{{ t('billing.document.credit') }}</dt>
                <dd class="tabular text-right text-ink">{{ money(-invoice.creditAmount) }}</dd>
            </template>

            <dt class="self-end font-medium text-ink">{{ totalLabel }}</dt>
            <dd class="figure text-right text-2xl text-ink">{{ money(invoice.totalAmount) }}</dd>
        </dl>
    </Card>
</template>

<style>
/*
 * On paper only the invoice is printed: the shell, the buttons of the page
 * and the payment panels are left out. The rules are tied to a page that
 * holds an invoice, so no other page prints differently. System colours
 * stand in for the theme, because paper is white whichever theme is on.
 */
@media print {
    body:has([data-invoice-document]) * {
        visibility: hidden;
    }

    body:has([data-invoice-document]) [data-invoice-document],
    body:has([data-invoice-document]) [data-invoice-document] * {
        visibility: visible;
    }

    [data-invoice-document] {
        position: absolute;
        top: 0;
        right: 0;
        left: 0;
        color-scheme: light;
        border-color: transparent;
        background: none;
        box-shadow: none;
        -webkit-backdrop-filter: none;
        backdrop-filter: none;

        --ink: CanvasText;
        --ink-muted: CanvasText;
        --ink-subtle: GrayText;
        --line: GrayText;
        --hover: transparent;
        --ok: CanvasText;
        --warn: CanvasText;
        --danger: CanvasText;
        --ok-soft: transparent;
        --warn-soft: transparent;
        --danger-soft: transparent;
    }
}
</style>
