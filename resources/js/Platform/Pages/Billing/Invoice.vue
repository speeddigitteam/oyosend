<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Printer } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceDocument from '@/Core/Components/Billing/InvoiceDocument.vue';
import PaymentList from '@/Core/Components/Billing/PaymentList.vue';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type { InvoiceDocumentData, PaymentSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import WorkspaceName from '@/Platform/Components/WorkspaceName.vue';

defineOptions({ layout: PlatformLayout });

const props = defineProps<{
    invoice: InvoiceDocumentData;
    /** Null when the workspace has been deleted since. */
    workspace: { id: string; name: string; slug: string } | null;
    /** Every attempt to pay it, newest first, with what the operator noted. */
    payments: PaymentSummary[];
    /** How money can arrive outside a gateway: values of `billing.terms.method`. */
    offlineMethods: string[];
    /** `platform.billing.manage`: may record a payment and withdraw the invoice. */
    canManage: boolean;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

// A link nobody may follow is not shown; the server checks the ability again.
const canOpenWorkspace = computed(() => (page.props.auth?.abilities ?? []).includes('platform.tenants.view'));

const open = computed(() => props.invoice.status === 'open');

/** The credit on an invoice for a change of plan was worked out for the day it was issued; past its due date nothing can be paid on it. */
const offerExpired = computed(() => open.value && props.invoice.kind === 'change' && props.invoice.overdue);

const canRecord = computed(() => props.canManage && open.value && props.invoice.totalAmount > 0 && !offerExpired.value);
const canWithdraw = computed(() => props.canManage && open.value);

const amount = computed(() => format.money(props.invoice.totalAmount, props.invoice.currency));

/*
|--------------------------------------------------------------------------
| Record a payment that arrived outside a gateway
|--------------------------------------------------------------------------
*/

/** Today as the date field writes it. The server reads it as a day on the workspace's clock. */
function today(): string {
    const now = new Date();
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

const recordOpen = ref(false);
const recordFormId = useId();
const recordForm = useForm({ method: '', reference: '', note: '', paid_at: today() });

// "This invoice is not waiting for payment" arrives under a key that is not a field.
const recordError = computed(() => (recordForm.errors as Record<string, string | undefined>).invoice);

const methodOptions = computed<SelectOption[]>(() => props.offlineMethods.map((value) => ({ value, label: terms.method(value) })));

useFocusReturn(recordOpen);

watch(recordOpen, (isOpen) => {
    if (isOpen) {
        recordForm.reset();
        recordForm.paid_at = today();
    }

    recordForm.clearErrors();
});

function record(): void {
    recordForm.post(route('platform.invoices.payments.store', { invoice: props.invoice.id }), {
        preserveScroll: true,
        onSuccess: () => {
            recordOpen.value = false;
        },
    });
}

/*
|--------------------------------------------------------------------------
| Withdraw the invoice
|--------------------------------------------------------------------------
*/

const withdrawOpen = ref(false);
const withdrawing = ref(false);
const withdrawReason = ref('');
const withdrawError = ref<string | null>(null);

useFocusReturn(withdrawOpen);

watch(withdrawOpen, (isOpen) => {
    if (isOpen) {
        withdrawReason.value = '';
        withdrawError.value = null;
    }
});

function withdraw(): void {
    withdrawing.value = true;
    withdrawError.value = null;

    router.post(
        route('platform.invoices.void', { invoice: props.invoice.id }),
        { reason: withdrawReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                withdrawOpen.value = false;
            },
            onError: (errors) => {
                withdrawError.value = errors.reason ?? Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                withdrawing.value = false;
            },
        },
    );
}

function print(): void {
    window.print();
}
</script>

<template>
    <PageHeader :eyebrow="t('platformBilling.invoices.title')" :title="t('billing.invoice.title', { number: invoice.number })">
        <template #actions>
            <Button variant="ghost" :href="route('platform.invoices.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('platformBilling.invoice.back') }}
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
            <Card :title="t('platformBilling.invoice.workspace.title')">
                <template v-if="workspace">
                    <WorkspaceName :name="workspace.name" />
                    <p class="mt-1.5 font-mono text-xs break-all text-ink-subtle">{{ workspace.slug }}</p>
                    <Button
                        v-if="canOpenWorkspace"
                        class="mt-4"
                        size="sm"
                        :href="route('platform.tenants.show', { workspace: workspace.id })"
                    >
                        {{ t('platformBilling.invoice.workspace.open') }}
                    </Button>
                </template>
                <p v-else class="text-ink-muted">{{ t('platformBilling.invoice.workspace.gone') }}</p>
            </Card>

            <Card v-if="open" :title="t('platformBilling.invoice.unpaid.title')">
                <dl class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <dt class="eyebrow">{{ t('billing.document.totalDue') }}</dt>
                    <dd class="figure text-2xl text-ink">{{ amount }}</dd>
                </dl>

                <p class="mt-3 text-ink-muted">
                    {{ offerExpired ? t('platformBilling.invoice.unpaid.expired') : t('platformBilling.invoice.unpaid.body') }}
                </p>

                <div v-if="canRecord || canWithdraw" class="mt-4 flex flex-wrap items-center gap-2">
                    <Button v-if="canRecord" variant="primary" @click="recordOpen = true">
                        {{ t('platformBilling.invoice.record.action') }}
                    </Button>
                    <Button v-if="canWithdraw" @click="withdrawOpen = true">{{ t('platformBilling.invoice.withdraw.action') }}</Button>
                </div>

                <template v-if="!canManage" #footer>
                    <p class="flex items-start gap-2 text-[0.8125rem] text-ink-subtle">
                        <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        {{ t('platformBilling.invoice.unpaid.viewOnly') }}
                    </p>
                </template>
            </Card>

            <Card flush :title="t('billing.payments.title')" :description="t('platformBilling.invoice.payments.description')">
                <PaymentList :payments="payments">
                    <template #details="{ payment }">
                        <p v-if="payment.bankReference" class="mt-1 text-[0.8125rem] wrap-break-word text-ink-muted">
                            {{ t('platformBilling.invoice.payments.bankReference', { reference: payment.bankReference }) }}
                        </p>
                        <p v-if="payment.note" class="mt-1 text-[0.8125rem] wrap-break-word whitespace-pre-line text-ink-muted">
                            {{ t('platformBilling.invoice.payments.note', { note: payment.note }) }}
                        </p>
                    </template>
                </PaymentList>
            </Card>
        </div>
    </div>

    <Modal
        v-if="canManage"
        v-model:open="recordOpen"
        :title="t('platformBilling.invoice.record.title')"
        :description="t('platformBilling.invoice.record.description', { number: invoice.number })"
        :persistent="recordForm.processing"
    >
        <form :id="recordFormId" class="flex flex-col gap-4" novalidate @submit.prevent="record">
            <p v-if="recordError" class="text-[0.8125rem] text-danger" role="alert">{{ recordError }}</p>

            <dl class="glass-well flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-control px-3 py-2.5">
                <dt class="eyebrow">{{ t('platformBilling.invoice.record.amount') }}</dt>
                <dd class="tabular font-medium text-ink">{{ amount }}</dd>
                <dd class="w-full text-[0.8125rem] text-ink-subtle">{{ t('platformBilling.invoice.record.amountHint') }}</dd>
            </dl>

            <div class="grid gap-4 sm:grid-cols-2">
                <Select
                    v-model="recordForm.method"
                    name="method"
                    required
                    :label="t('platformBilling.invoice.record.method')"
                    :options="methodOptions"
                    :placeholder="t('platformBilling.invoice.record.methodPlaceholder')"
                    :error="recordForm.errors.method"
                />

                <Input
                    v-model="recordForm.paid_at"
                    type="date"
                    name="paid_at"
                    required
                    :max="today()"
                    :label="t('platformBilling.invoice.record.paidAt')"
                    :hint="t('platformBilling.invoice.record.paidAtHint')"
                    :error="recordForm.errors.paid_at"
                />
            </div>

            <Input
                v-model="recordForm.reference"
                name="reference"
                optional
                mono
                maxlength="120"
                autocomplete="off"
                :label="t('platformBilling.invoice.record.reference')"
                :hint="t('platformBilling.invoice.record.referenceHint')"
                :error="recordForm.errors.reference"
            />

            <Textarea
                v-model="recordForm.note"
                name="note"
                optional
                maxlength="500"
                :label="t('platformBilling.invoice.record.note')"
                :hint="t('platformBilling.invoice.record.noteHint')"
                :error="recordForm.errors.note"
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="recordForm.processing" @click="recordOpen = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="recordFormId" :loading="recordForm.processing" :disabled="recordForm.method === ''">
                {{ t('platformBilling.invoice.record.submit') }}
            </Button>
        </template>
    </Modal>

    <ConfirmDialog
        v-if="canManage"
        v-model:open="withdrawOpen"
        :title="t('platformBilling.invoice.withdraw.title', { number: invoice.number })"
        :description="t('platformBilling.invoice.withdraw.body')"
        :confirm-label="t('platformBilling.invoice.withdraw.confirm')"
        :loading="withdrawing"
        @confirm="withdraw"
    >
        <Textarea
            v-model="withdrawReason"
            name="reason"
            maxlength="500"
            :label="t('platformBilling.invoice.withdraw.reason')"
            :hint="t('platformBilling.invoice.withdraw.reasonHint')"
            :error="withdrawError ?? undefined"
        />
    </ConfirmDialog>
</template>
