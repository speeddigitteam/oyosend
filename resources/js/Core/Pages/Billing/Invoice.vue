<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Printer } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceDocument from '@/Core/Components/Billing/InvoiceDocument.vue';
import PaymentList from '@/Core/Components/Billing/PaymentList.vue';
import type { GatewayOption, InvoiceDocumentData, PaymentSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    invoice: InvoiceDocumentData;
    /** Every attempt to pay it, newest first. */
    payments: PaymentSummary[];
    /** Gateways it can be paid through now. Empty when none has credentials, and once the invoice is no longer open. */
    gateways: GatewayOption[];
    /** How to pay outside a gateway, as the operator wrote it. Plain text. */
    offlineInstructions: string | null;
    /** Open, with something to pay, and the person holds `core.billing.manage`. */
    canPay: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const awaitingPayment = computed(() => props.invoice.status === 'open' && props.invoice.totalAmount > 0);

/** The credit on an invoice for a change of plan was worked out for the day it was issued; past its due date the server refuses it. */
const offerExpired = computed(() => awaitingPayment.value && props.invoice.kind === 'change' && props.invoice.overdue);

const amount = computed(() => format.money(props.invoice.totalAmount, props.invoice.currency));

/*
 * Paying. The server opens the payment at the gateway and answers with the
 * address to go to, which Inertia follows by leaving the page. The button
 * therefore keeps spinning after a success: the next thing to happen is the
 * provider's own page.
 */
const paying = ref<string | null>(null);
const payError = ref<string | null>(null);

function settle(): void {
    paying.value = null;
}

function pay(gateway: GatewayOption): void {
    if (paying.value !== null) {
        return;
    }

    paying.value = gateway.key;
    payError.value = null;

    router.post(
        route('core.billing.invoices.pay', { invoice: props.invoice.id }),
        { gateway: gateway.key },
        {
            preserveScroll: true,
            onError: (errors) => {
                payError.value = errors.gateway ?? errors.invoice ?? Object.values(errors)[0] ?? null;
                settle();
            },
            onSuccess: settle,
            onCancel: settle,
            onHttpException: settle,
            onNetworkError: settle,
        },
    );
}

// Back from the provider with the browser's Back button: the page is restored as it was left.
function onPageShow(event: PageTransitionEvent): void {
    if (event.persisted) {
        settle();
    }
}

onMounted(() => window.addEventListener('pageshow', onPageShow));
onBeforeUnmount(() => window.removeEventListener('pageshow', onPageShow));

function print(): void {
    window.print();
}
</script>

<template>
    <PageHeader :eyebrow="t('billing.index.title')" :title="t('billing.invoice.title', { number: invoice.number })">
        <template #actions>
            <Button variant="ghost" :href="route('core.billing.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('billing.invoice.back') }}
            </Button>
            <Button @click="print">
                <template #icon><Printer class="size-4" aria-hidden="true" /></template>
                {{ t('billing.invoice.print') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_23rem]">
        <InvoiceDocument :invoice="invoice" />

        <div class="flex min-w-0 flex-col gap-3">
            <Card v-if="offerExpired" :title="t('billing.pay.expired.title')">
                <p class="text-ink-muted">{{ t('billing.pay.expired.body', { date: format.date(invoice.dueAt) }) }}</p>
                <Button v-if="canPay" class="mt-4" :href="route('core.billing.index')">{{ t('billing.pay.expired.action') }}</Button>
            </Card>

            <Card v-else-if="awaitingPayment" :title="t('billing.pay.title')" :description="canPay && gateways.length > 0 ? t('billing.pay.description') : undefined">
                <template v-if="canPay">
                    <dl class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <dt class="eyebrow">{{ t('billing.pay.amount') }}</dt>
                        <dd class="figure text-2xl text-ink">{{ amount }}</dd>
                    </dl>

                    <div v-if="gateways.length > 0" class="mt-4 flex flex-col gap-2">
                        <Button
                            v-for="gateway in gateways"
                            :key="gateway.key"
                            variant="primary"
                            block
                            :loading="paying === gateway.key"
                            :disabled="paying !== null"
                            @click="pay(gateway)"
                        >
                            {{ t('billing.pay.with', { gateway: gateway.name }) }}
                        </Button>
                    </div>

                    <div v-else class="mt-4 rounded-control border border-line px-3.5 py-3">
                        <p class="font-medium text-ink">{{ t('billing.pay.noGateway.title') }}</p>
                        <p class="mt-0.5 text-[0.8125rem] text-ink-muted">{{ t('billing.pay.noGateway.body') }}</p>
                        <p v-if="!offlineInstructions" class="mt-0.5 text-[0.8125rem] text-ink-muted">
                            {{ t('billing.pay.noGateway.noOffline') }}
                        </p>
                    </div>

                    <p v-if="payError" class="mt-3 text-[0.8125rem] text-danger" role="alert">{{ payError }}</p>
                </template>

                <p v-else class="flex items-start gap-2 text-ink-muted">
                    <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ t('billing.pay.viewOnly') }}
                </p>

                <!-- Written by the operator: shown as text, line breaks kept, never as markup. -->
                <div v-if="offlineInstructions" class="mt-5 border-t border-line pt-4">
                    <h3 class="font-sans text-sm font-semibold tracking-normal text-ink">{{ t('billing.pay.offline.title') }}</h3>
                    <p class="mt-1.5 wrap-break-word whitespace-pre-line text-ink-muted">{{ offlineInstructions }}</p>
                    <p class="mt-2 text-[0.8125rem] text-ink-subtle">{{ t('billing.pay.offline.note') }}</p>
                </div>
            </Card>

            <Card flush :title="t('billing.payments.title')" :description="t('billing.payments.description')">
                <PaymentList :payments="payments" :gateways="gateways" />
            </Card>
        </div>
    </div>
</template>
