<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CalendarClock, CalendarSync, Lock, ReceiptText, TriangleAlert, X } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import BillingNotice from '@/Core/Components/Billing/BillingNotice.vue';
import InvoiceStatusBadge from '@/Core/Components/Billing/InvoiceStatusBadge.vue';
import { listAmountBeside, yearlySaving } from '@/Core/Components/Billing/offer';
import { planAmount, planChangeOutcome, planInterval, type OfferedPlan, type PlanChangeOutcome } from '@/Core/Components/Billing/planChange';
import SubscriptionStatusBadge from '@/Core/Components/Billing/SubscriptionStatusBadge.vue';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type {
    BillingInterval,
    GatewayOption,
    InvoiceSummary,
    PaymentSummary,
    SubscriptionSummary,
    UsageEntry,
} from '@/Core/Components/Billing/types';
import UsageMeter from '@/Core/Components/Billing/UsageMeter.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { limitLabel, moduleLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    /** Null when the workspace has never taken a plan. */
    subscription: SubscriptionSummary | null;
    /** The plans on offer, plus the one the workspace is on even if it is no longer sold. */
    plans: OfferedPlan[];
    /** Every limit a plan can set, with what the workspace uses of it. */
    usage: UsageEntry[];
    /** The most recent invoices, newest first. */
    invoices: InvoiceSummary[];
    /** The invoice waiting to be paid, if there is one. */
    openInvoice: InvoiceSummary | null;
    /** Gateways the open invoice can be paid through. Paying happens on the invoice's own page. */
    gateways: GatewayOption[];
    offlineInstructions: string | null;
    /** The payment the payer has just come back from a gateway with (`?payment=`). */
    returnedPayment: PaymentSummary | null;
    /** `core.billing.manage`: may change the plan, cancel and pay. */
    canManage: boolean;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

const workspace = computed(() => page.props.tenant?.name ?? '');

/** A date inside a sentence; a dash keeps the sentence whole if the server ever sends none. */
function on(iso: string | null | undefined): string {
    return format.date(iso) || '—';
}

function invoiceUrl(invoice: InvoiceSummary): string {
    return route('core.billing.invoices.show', { invoice: invoice.id });
}

/*
|--------------------------------------------------------------------------
| The payment the payer came back with
|--------------------------------------------------------------------------
*/

const returned = computed(() => {
    const payment = props.returnedPayment;

    if (!payment) {
        return null;
    }

    const amount = format.money(payment.amount, payment.currency);

    if (payment.status === 'succeeded') {
        return { tone: 'ok' as const, title: t('billing.returned.succeeded.title'), body: t('billing.returned.succeeded.body', { amount }) };
    }

    if (payment.status === 'failed') {
        return { tone: 'danger' as const, title: t('billing.returned.failed.title'), body: t('billing.returned.failed.body') };
    }

    if (payment.status === 'canceled') {
        return { tone: 'warn' as const, title: t('billing.returned.canceled.title'), body: t('billing.returned.canceled.body') };
    }

    // Not confirmed yet: the provider tells the server, not this page.
    return { tone: 'info' as const, title: t('billing.returned.pending.title'), body: t('billing.returned.pending.body') };
});

const checking = ref(false);

function checkAgain(): void {
    checking.value = true;

    router.reload({
        onFinish: () => {
            checking.value = false;
        },
    });
}

/** Drops `?payment=` from the address, and the notice with it. */
function dismissReturned(): void {
    router.get(route('core.billing.index'), {}, { replace: true, preserveScroll: true, preserveState: true });
}

/*
|--------------------------------------------------------------------------
| What is owed
|--------------------------------------------------------------------------
*/

/** An invoice for a change of plan can only be paid until its due date: its credit was worked out for the day it was issued. */
const offerExpired = computed(() => props.openInvoice?.kind === 'change' && props.openInvoice.overdue);

const due = computed(() => {
    const invoice = props.openInvoice;

    if (!invoice) {
        return null;
    }

    const amount = format.money(invoice.totalAmount, invoice.currency);
    const date = on(invoice.dueAt);
    const title = t(invoice.overdue ? 'billing.due.titleOverdue' : 'billing.due.title', { number: invoice.number });

    if (offerExpired.value) {
        return { title, lines: [t('billing.due.offerExpired', { date })] };
    }

    const lines = [
        invoice.dueAt === null
            ? t('billing.due.amount', { amount })
            : t(invoice.overdue ? 'billing.due.wasDue' : 'billing.due.payBy', { amount, date }),
    ];

    if (props.subscription?.locked) {
        lines.push(t('billing.due.unlocks'));
    } else if (invoice.kind === 'change') {
        lines.push(t('billing.due.changes'));
    }

    return { title, lines };
});

const canPay = computed(() => props.canManage && !offerExpired.value);

/*
|--------------------------------------------------------------------------
| The current plan
|--------------------------------------------------------------------------
*/

interface Standing {
    text: string;
    icon: Component;
    tone: string;
}

/** The one date that matters for the state the subscription is in. */
const standing = computed<Standing | null>(() => {
    const subscription = props.subscription;

    if (!subscription) {
        return null;
    }

    if (subscription.locked) {
        return {
            icon: Lock,
            tone: 'text-danger',
            text: t(subscription.status === 'expired' ? 'billing.current.state.expired' : 'billing.current.state.canceled', {
                date: on(subscription.endedAt),
            }),
        };
    }

    if (subscription.status === 'past_due') {
        return {
            icon: TriangleAlert,
            tone: 'text-warn',
            text: t('billing.current.state.pastDue', { date: on(subscription.graceEndsAt) }),
        };
    }

    if (subscription.cancelAtPeriodEnd) {
        return {
            icon: CalendarClock,
            tone: 'text-warn',
            text: t('billing.current.state.ending', { date: on(subscription.periodEnd ?? subscription.trialEndsAt) }),
        };
    }

    if (subscription.status === 'trialing') {
        return {
            icon: CalendarClock,
            tone: 'text-ink-muted',
            text: t('billing.current.state.trial', { date: on(subscription.trialEndsAt) }),
        };
    }

    if (subscription.isFree) {
        return { icon: CalendarSync, tone: 'text-ink-muted', text: t('billing.current.state.free') };
    }

    if (subscription.periodEnd === null) {
        return null;
    }

    if (subscription.pending) {
        return {
            icon: CalendarClock,
            tone: 'text-ink-muted',
            text: t('billing.current.pending', {
                plan: subscription.pending.plan,
                interval: terms.interval(subscription.pending.interval),
                date: on(subscription.periodEnd),
            }),
        };
    }

    return {
        icon: CalendarSync,
        tone: 'text-ink-muted',
        text: t('billing.current.state.renews', { date: on(subscription.periodEnd) }),
    };
});

const paidPeriod = computed(() => {
    const subscription = props.subscription;

    return subscription && subscription.status === 'active' && !subscription.isFree
        ? terms.period(subscription.periodStart, subscription.periodEnd)
        : '';
});

// A free plan has nothing to cancel, and one that has ended is taken again instead.
const canCancel = computed(() => {
    const subscription = props.subscription;

    return props.canManage && subscription !== null && !subscription.locked && !subscription.isFree && !subscription.cancelAtPeriodEnd;
});

const canResume = computed(() => props.canManage && props.subscription?.cancelAtPeriodEnd === true && !props.subscription.locked);

const cancelOpen = ref(false);
const canceling = ref(false);
const cancelError = ref<string | null>(null);

useFocusReturn(cancelOpen);

const cancelBody = computed(() => {
    const subscription = props.subscription;

    if (!subscription) {
        return '';
    }

    if (subscription.status === 'past_due') {
        return t('billing.cancel.pastDue');
    }

    return subscription.status === 'trialing'
        ? t('billing.cancel.trial', { date: on(subscription.trialEndsAt) })
        : t('billing.cancel.paid', { date: on(subscription.periodEnd) });
});

function askCancel(): void {
    cancelError.value = null;
    cancelOpen.value = true;
}

function cancel(): void {
    canceling.value = true;
    cancelError.value = null;

    router.post(
        route('core.billing.subscription.cancel'),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelOpen.value = false;
            },
            onError: (errors) => {
                cancelError.value = Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                canceling.value = false;
            },
        },
    );
}

// Taking a cancellation back gives something back, so it needs no confirmation.
const resuming = ref(false);
const resumeError = ref<string | null>(null);

function resume(): void {
    resuming.value = true;
    resumeError.value = null;

    router.post(
        route('core.billing.subscription.resume'),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                resumeError.value = Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                resuming.value = false;
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Usage
|--------------------------------------------------------------------------
*/

function usageText(entry: UsageEntry): string {
    const used = format.number(entry.used);

    return entry.limit === null ? t('billing.usage.noLimit', { used }) : t('billing.usage.of', { used, limit: format.number(entry.limit) });
}

/*
|--------------------------------------------------------------------------
| Plans
|--------------------------------------------------------------------------
*/

const INTERVALS: BillingInterval[] = ['monthly', 'yearly'];

/** The intervals at least one paid plan is sold for. With fewer than two there is nothing to switch between. */
const intervalsSold = computed(() =>
    INTERVALS.filter((candidate) => props.plans.some((plan) => !plan.isFree && planAmount(plan, candidate) !== null)),
);

const chosenInterval = ref<BillingInterval>(props.subscription?.interval ?? 'monthly');

const interval = computed<BillingInterval>({
    get: () => (intervalsSold.value.length === 1 ? intervalsSold.value[0]! : chosenInterval.value),
    set: (value) => {
        chosenInterval.value = value;
    },
});

const intervalOptions = computed<Segment<BillingInterval>[]>(() =>
    INTERVALS.map((value) => ({ value, label: terms.interval(value) })),
);

interface PlanCard {
    plan: OfferedPlan;
    /** The interval that would be asked for. */
    interval: BillingInterval;
    /** The price of one period, or null when the plan is not sold for the interval shown. */
    amount: number | null;
    /** The plan's own price while a limited-time offer runs below it, and when that offer ends. */
    listAmount: number | null;
    offerEndsAt: string | null;
    /** Whole percent the yearly price saves against twelve months; 0 when it is not shown. */
    yearlySaving: number;
    outcome: PlanChangeOutcome;
    limits: Array<{ key: string; label: string; value: string }>;
    modules: string[];
    /** The button, or null when there is nothing this person can do with the plan. `name` is what a screen reader says. */
    action: { label: string; name?: string } | null;
}

function actionFor(plan: OfferedPlan, asked: BillingInterval, outcome: PlanChangeOutcome): PlanCard['action'] {
    if (!props.canManage || outcome.kind === 'unavailable' || outcome.kind === 'current') {
        return null;
    }

    if (outcome.kind === 'keepEnding' || outcome.kind === 'keepPending') {
        return { label: t('billing.plans.keep') };
    }

    const subscription = props.subscription;

    // The plan the workspace is on, shown for the other interval.
    if (plan.current && subscription && !subscription.locked && subscription.interval !== asked) {
        return { label: t(`billing.plans.switchBilling.${asked}`) };
    }

    // Every card has this button, so each one names its plan.
    return { label: t('billing.plans.choose'), name: t('billing.plans.chooseNamed', { plan: plan.name }) };
}

const cards = computed<PlanCard[]>(() =>
    props.plans.map((plan) => {
        const asked = planInterval(plan, interval.value);
        const outcome = planChangeOutcome(props.subscription, plan, asked);

        return {
            plan,
            interval: asked,
            amount: planAmount(plan, asked),
            listAmount: listAmountBeside(plan, asked),
            offerEndsAt: listAmountBeside(plan, asked) === null ? null : (plan.offerEndsAt ?? null),
            // Shown on the yearly price: what it saves against paying monthly.
            yearlySaving: asked === 'yearly' ? yearlySaving(planAmount(plan, 'monthly'), planAmount(plan, 'yearly')) : 0,
            outcome,
            // Every limit that exists, so the plans can be compared line by line.
            limits: props.usage.map((entry) => {
                const limit = plan.limits[entry.key];

                return {
                    key: entry.key,
                    label: limitLabel(entry.key),
                    value: limit === undefined ? t('billing.terms.unlimited') : format.number(limit),
                };
            }),
            modules: plan.modules.map((key) => moduleLabel(key)),
            action: actionFor(plan, asked, outcome),
        };
    }),
);

const choice = ref<PlanCard | null>(null);
const chooseOpen = ref(false);
const choosing = ref(false);
const chooseError = ref<string | null>(null);

useFocusReturn(chooseOpen);

function choose(card: PlanCard): void {
    choice.value = card;
    chooseError.value = null;
    chooseOpen.value = true;
}

function price(card: PlanCard): string {
    if (card.plan.isFree || card.amount === null) {
        return t('billing.terms.free');
    }

    return `${format.money(card.amount, card.plan.currency)} ${terms.perInterval(card.interval)}`;
}

/** What will happen, in the words of the outcome the server is about to produce. */
const choiceCopy = computed(() => {
    const card = choice.value;

    if (!card) {
        return { title: '', body: '', confirm: '', price: '' };
    }

    const outcome = card.outcome;
    const keeps = outcome.kind === 'keepEnding' || outcome.kind === 'keepPending';
    const plan = card.plan.name;

    return {
        title: t(keeps ? 'billing.plans.confirm.titleKeep' : 'billing.plans.confirm.title', { plan }),
        body: t(`billing.plans.confirm.outcome.${outcome.kind}`, {
            plan,
            amount: card.amount === null ? '' : format.money(card.amount, card.plan.currency),
            date: 'at' in outcome ? on(outcome.at) : '',
        }),
        confirm: t(keeps ? 'billing.plans.confirm.submitKeep' : 'billing.plans.confirm.submit'),
        price: price(card),
    };
});

function confirmChoice(): void {
    const card = choice.value;

    if (!card) {
        return;
    }

    choosing.value = true;
    chooseError.value = null;

    router.post(
        route('core.billing.subscription.change'),
        { plan_id: card.plan.id, interval: card.interval },
        {
            // A plan that needs paying for leads to its invoice, which should open at the top.
            preserveScroll: 'errors',
            onSuccess: () => {
                chooseOpen.value = false;
            },
            onError: (errors) => {
                chooseError.value = errors.plan ?? errors.interval ?? Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                choosing.value = false;
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Invoices
|--------------------------------------------------------------------------
*/

const invoiceColumns = computed<Column[]>(() => [
    { key: 'number', label: t('billing.invoices.columns.number'), class: 'whitespace-nowrap' },
    { key: 'issuedAt', label: t('billing.invoices.columns.issued'), class: 'whitespace-nowrap' },
    { key: 'kind', label: t('billing.invoices.columns.kind'), class: 'whitespace-nowrap' },
    { key: 'period', label: t('billing.invoices.columns.period'), class: 'whitespace-nowrap' },
    { key: 'totalAmount', label: t('billing.invoices.columns.total'), align: 'right', class: 'whitespace-nowrap' },
    { key: 'status', label: t('billing.invoices.columns.status'), class: 'whitespace-nowrap' },
]);
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.manage')"
        :title="t('billing.index.title')"
        :description="t('billing.index.description', { workspace })"
    />

    <div class="flex flex-col gap-3">
        <BillingNotice v-if="returned && returnedPayment" :tone="returned.tone" :title="returned.title" role="status">
            <p>{{ returned.body }}</p>
            <p v-if="returnedPayment.status === 'failed' && openInvoice && canPay">{{ t('billing.returned.retry') }}</p>
            <p v-if="returnedPayment.failureReason" class="wrap-break-word">
                {{ t('billing.returned.reason', { reason: returnedPayment.failureReason }) }}
            </p>
            <p class="text-[0.8125rem] text-ink-subtle">
                {{ t('billing.returned.reference', { reference: returnedPayment.reference }) }}
            </p>

            <template #actions>
                <Button v-if="returnedPayment.status === 'pending'" size="sm" :loading="checking" @click="checkAgain">
                    {{ t('billing.returned.pending.refresh') }}
                </Button>
                <Button icon size="sm" variant="ghost" @click="dismissReturned">
                    <template #icon><X class="size-4" aria-hidden="true" /></template>
                    {{ t('common.dismiss') }}
                </Button>
            </template>
        </BillingNotice>

        <BillingNotice v-if="due && openInvoice" :tone="openInvoice.overdue ? 'danger' : 'warn'" :title="due.title">
            <p v-for="line in due.lines" :key="line">{{ line }}</p>

            <template #actions>
                <Button :variant="canPay ? 'primary' : 'secondary'" :href="invoiceUrl(openInvoice)">
                    {{ canPay ? t('billing.due.pay') : t('billing.due.view') }}
                </Button>
            </template>
        </BillingNotice>

        <div class="grid gap-3 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <Card :title="t('billing.current.title')">
                <template v-if="subscription" #actions>
                    <SubscriptionStatusBadge :status="subscription.status" />
                </template>

                <template v-if="subscription">
                    <p class="font-display text-2xl font-semibold tracking-tight wrap-break-word text-ink">{{ subscription.plan.name }}</p>

                    <p class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <template v-if="subscription.isFree">
                            <span class="figure text-[1.75rem] text-ink">{{ t('billing.terms.free') }}</span>
                        </template>
                        <template v-else>
                            <span class="figure text-[1.75rem] text-ink">{{ format.money(subscription.amount, subscription.currency) }}</span>
                            <span class="text-ink-muted">{{ terms.perInterval(subscription.interval) }}</span>
                        </template>
                    </p>

                    <p v-if="standing" :class="['mt-4 flex max-w-xl items-start gap-2', standing.tone]">
                        <component :is="standing.icon" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{{ standing.text }}</span>
                    </p>

                    <p v-if="subscription.pending && canManage" class="mt-1 pl-6 text-[0.8125rem] text-ink-subtle">
                        {{ t('billing.current.pendingUndo') }}
                    </p>

                    <dl v-if="paidPeriod" class="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                        <dt class="eyebrow">{{ t('billing.current.period') }}</dt>
                        <dd class="tabular text-ink-muted">{{ paidPeriod }}</dd>
                    </dl>

                    <div v-if="canCancel || canResume" class="mt-5 flex flex-wrap items-center gap-2">
                        <Button v-if="canResume" variant="primary" :loading="resuming" @click="resume">
                            {{ t('billing.current.resume') }}
                        </Button>
                        <Button v-if="canCancel" @click="askCancel">{{ t('billing.current.cancel') }}</Button>
                    </div>

                    <p v-if="resumeError" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ resumeError }}</p>
                </template>

                <template v-else>
                    <p class="font-display text-2xl font-semibold tracking-tight text-ink">{{ terms.subscriptionStatus(null) }}</p>
                    <p class="mt-2 max-w-xl text-ink-muted">
                        {{ canManage ? t('billing.current.none.manage') : t('billing.current.none.view') }}
                    </p>
                </template>

                <template v-if="!canManage" #footer>
                    <p class="flex items-start gap-2 text-[0.8125rem] text-ink-subtle">
                        <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        {{ t('billing.current.viewOnly') }}
                    </p>
                </template>
            </Card>

            <Card :title="t('billing.usage.title')" :description="t('billing.usage.description')">
                <p v-if="usage.length === 0" class="text-ink-muted">{{ t('billing.usage.empty') }}</p>

                <ul v-else class="flex flex-col gap-4">
                    <li v-for="entry in usage" :key="entry.key">
                        <p class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                            <span class="font-medium text-ink">{{ limitLabel(entry.key) }}</span>
                            <span class="tabular text-[0.8125rem] text-ink-muted">{{ usageText(entry) }}</span>
                        </p>

                        <UsageMeter v-if="entry.limit !== null" :value="entry.used" :max="entry.limit" class="mt-1.5" />

                        <p v-if="entry.limit !== null && entry.used >= entry.limit" class="mt-1.5 text-[0.8125rem] text-danger">
                            {{ t('billing.usage.reached') }}
                        </p>
                        <p v-else-if="entry.kind === 'metered'" class="mt-1.5 text-[0.8125rem] text-ink-subtle">
                            {{ t('billing.usage.metered') }}
                        </p>
                    </li>
                </ul>
            </Card>
        </div>

        <section aria-labelledby="billing-plans">
            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 px-1 pt-5 pb-3">
                <div class="min-w-0">
                    <h2 id="billing-plans" class="text-xl text-ink">{{ t('billing.plans.title') }}</h2>
                    <p class="mt-1 max-w-2xl text-[0.8125rem] text-ink-subtle">
                        {{ canManage ? t('billing.plans.description') : t('billing.plans.viewOnly') }}
                    </p>
                </div>

                <SegmentedControl
                    v-if="intervalsSold.length > 1"
                    v-model="interval"
                    :options="intervalOptions"
                    :label="t('billing.plans.interval')"
                />
            </div>

            <Card v-if="plans.length === 0">
                <EmptyState :title="t('billing.plans.empty.title')" :description="t('billing.plans.empty.body')">
                    <template #icon><ReceiptText /></template>
                </EmptyState>
            </Card>

            <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="card in cards" :key="card.plan.id" class="flex">
                    <Card as="article" :class="['w-full', card.plan.current ? 'outline-2 -outline-offset-1 outline-brand' : '']">
                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                            <h3 class="min-w-0 text-lg wrap-break-word text-ink">{{ card.plan.name }}</h3>
                            <Badge v-if="card.plan.current" tone="brand">{{ t('billing.plans.current') }}</Badge>
                            <Badge v-if="!card.plan.isActive">{{ t('billing.plans.retired') }}</Badge>
                        </div>

                        <p v-if="card.plan.description" class="mt-1 text-[0.8125rem] wrap-break-word text-ink-muted">
                            {{ card.plan.description }}
                        </p>

                        <p v-if="card.plan.isFree" class="figure mt-4 text-[1.75rem] text-ink">{{ t('billing.terms.free') }}</p>
                        <template v-else-if="card.amount !== null">
                            <p class="mt-4 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="figure text-[1.75rem] text-ink">{{ format.money(card.amount, card.plan.currency) }}</span>
                                <span class="text-ink-muted">{{ terms.perInterval(card.interval) }}</span>
                                <!-- A running offer: the plan's own price it takes off from. -->
                                <span v-if="card.listAmount !== null" class="text-[0.8125rem] text-ink-subtle">
                                    <span class="sr-only">{{ t('billing.terms.offer.was') }}</span>
                                    <del class="tabular">{{ format.money(card.listAmount, card.plan.currency) }}</del>
                                </span>
                            </p>
                            <div v-if="card.offerEndsAt || card.yearlySaving > 0" class="mt-2 flex flex-wrap gap-1.5">
                                <Badge v-if="card.offerEndsAt" tone="accent">{{ t('billing.terms.offer.until', { date: format.date(card.offerEndsAt) }) }}</Badge>
                                <Badge v-if="card.yearlySaving > 0" tone="ok">
                                    {{ t('billing.terms.offer.yearlySaving', { percent: format.number(card.yearlySaving / 100, { style: 'percent' }) }) }}
                                </Badge>
                            </div>
                            <p v-if="card.offerEndsAt && !card.plan.current" class="mt-1.5 text-[0.8125rem] text-ink-subtle">{{ t('billing.terms.offer.keeps') }}</p>
                        </template>
                        <div v-else class="mt-4">
                            <p class="font-medium text-ink-muted">{{ t(`billing.plans.notSold.${card.interval}`) }}</p>
                            <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">
                                {{ t(`billing.plans.soldOnly.${card.interval === 'monthly' ? 'yearly' : 'monthly'}`) }}
                            </p>
                        </div>

                        <p v-if="card.plan.trialDays > 0 && !card.plan.isFree" class="mt-2 text-[0.8125rem] text-ink-subtle">
                            {{ t('billing.plans.trial', { count: format.number(card.plan.trialDays) }) }}
                        </p>

                        <div v-if="card.limits.length > 0" class="mt-4 border-t border-line pt-3">
                            <p class="eyebrow">{{ t('billing.plans.limits') }}</p>
                            <dl class="mt-2 flex flex-col gap-1">
                                <div v-for="limit in card.limits" :key="limit.key" class="flex items-baseline justify-between gap-4">
                                    <dt class="text-ink-muted">{{ limit.label }}</dt>
                                    <dd class="tabular text-ink">{{ limit.value }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div v-if="card.modules.length > 0" class="mt-4 border-t border-line pt-3">
                            <p class="eyebrow">{{ t('billing.plans.includes') }}</p>
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                <li v-for="module in card.modules" :key="module">
                                    <Badge>{{ module }}</Badge>
                                </li>
                            </ul>
                        </div>

                        <template v-if="card.action" #footer>
                            <Button
                                block
                                :variant="card.plan.current ? 'secondary' : 'primary'"
                                :aria-label="card.action.name"
                                @click="choose(card)"
                            >
                                {{ card.action.label }}
                            </Button>
                        </template>
                        <template v-else-if="canManage && card.outcome.kind === 'current'" #footer>
                            <p class="text-[0.8125rem] text-ink-subtle">{{ t('billing.plans.onThisPlan') }}</p>
                        </template>
                    </Card>
                </li>
            </ul>
        </section>

        <Card flush :title="t('billing.invoices.title')" :description="t('billing.invoices.description')" class="mt-5">
            <EmptyState v-if="invoices.length === 0" :title="t('billing.invoices.empty.title')" :description="t('billing.invoices.empty.body')">
                <template #icon><ReceiptText /></template>
            </EmptyState>

            <DataTable v-else :columns="invoiceColumns" :rows="invoices" row-key="id" :caption="t('billing.invoices.caption')">
                <template #cell-number="{ row }">
                    <Link
                        :href="invoiceUrl(row)"
                        class="rounded-chip font-mono text-[0.8125rem] font-medium text-ink underline-offset-4 hover:text-brand-text hover:underline"
                        :aria-label="t('billing.invoices.open', { number: row.number })"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell-issuedAt="{ row }">
                    <time :datetime="row.issuedAt" class="tabular text-ink-muted">{{ format.date(row.issuedAt) }}</time>
                </template>

                <template #cell-kind="{ row }">
                    <span class="text-ink-muted">{{ terms.invoiceKind(row.kind) }}</span>
                </template>

                <template #cell-period="{ row }">
                    <span v-if="row.periodStart && row.periodEnd" class="tabular text-ink-muted">
                        {{ terms.period(row.periodStart, row.periodEnd) }}
                    </span>
                    <span v-else class="text-ink-subtle">—</span>
                </template>

                <template #cell-totalAmount="{ row }">
                    <span class="tabular font-medium text-ink">{{ format.money(row.totalAmount, row.currency) }}</span>
                </template>

                <template #cell-status="{ row }">
                    <InvoiceStatusBadge :status="row.status" :overdue="row.overdue" />
                </template>
            </DataTable>
        </Card>
    </div>

    <ConfirmDialog
        v-model:open="cancelOpen"
        :title="t('billing.cancel.title')"
        :description="cancelBody"
        :confirm-label="t('billing.cancel.confirm')"
        :loading="canceling"
        @confirm="cancel"
    >
        <p v-if="openInvoice" class="text-[0.8125rem] text-ink-muted">
            {{ t('billing.cancel.withdrawsInvoice', { number: openInvoice.number }) }}
        </p>
        <p v-if="cancelError" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ cancelError }}</p>
    </ConfirmDialog>

    <ConfirmDialog
        v-model:open="chooseOpen"
        tone="default"
        :title="choiceCopy.title"
        :description="choiceCopy.body"
        :confirm-label="choiceCopy.confirm"
        :loading="choosing"
        @confirm="confirmChoice"
    >
        <dl class="flex items-baseline justify-between gap-4 rounded-control border border-line px-3 py-2.5">
            <dt class="eyebrow">{{ t('billing.plans.confirm.price') }}</dt>
            <dd class="tabular text-right font-medium text-ink">{{ choiceCopy.price }}</dd>
        </dl>
        <p v-if="openInvoice" class="mt-3 text-[0.8125rem] text-ink-muted">
            {{ t('billing.plans.confirm.replacesInvoice', { number: openInvoice.number }) }}
        </p>
        <p v-if="chooseError" class="mt-3 text-[0.8125rem] text-danger" role="alert">{{ chooseError }}</p>
    </ConfirmDialog>
</template>
