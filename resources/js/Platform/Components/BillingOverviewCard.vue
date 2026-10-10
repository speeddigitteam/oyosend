<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { SUBSCRIPTION_TONES, useBillingTerms } from '@/Core/Components/Billing/terms';
import Badge from '@/Core/Components/Badge.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { SubscriptionStatus } from '@/Core/types';
import { useAbilities } from './abilities';

/** The dashboard's `billing` prop: counts across every workspace. */
export interface BillingOverview {
    /** Subscriptions by status. A status nobody is in is absent. */
    subscriptions: Record<string, number>;
    /** Invoices issued and not paid yet. */
    openInvoices: number;
    /** Of those, the ones past their due date. */
    overdueInvoices: number;
    /** Sign-ups waiting for an operator. */
    pendingApprovals: number;
}

/**
 * Where the workspaces stand with their plans, and the three counts an
 * operator has to act on. Each of those leads to the list it was counted
 * from, when the operator's role can open that list.
 */
const props = defineProps<{ billing: BillingOverview }>();

const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();
const { allows } = useAbilities();

// Every status is listed, in the order a subscription moves through them, so the card does not change shape.
const STATUSES: SubscriptionStatus[] = ['trialing', 'active', 'past_due', 'expired', 'canceled'];

const statuses = computed(() =>
    [
        ...STATUSES,
        // A status this build does not know yet is still counted.
        ...Object.keys(props.billing.subscriptions).filter((status) => !(STATUSES as string[]).includes(status)),
    ].map((status) => ({ status, count: props.billing.subscriptions[status] ?? 0 })),
);

const subscribed = computed(() => Object.values(props.billing.subscriptions).reduce((sum, count) => sum + count, 0));

const waiting = computed(() => [
    {
        key: 'openInvoices',
        label: t('platform.dashboard.billing.openInvoices'),
        hint: t('platform.dashboard.billing.openInvoicesHint'),
        count: props.billing.openInvoices,
        warn: false,
        href: allows('platform.billing.view') ? route('platform.invoices.index', { status: 'open' }) : null,
        link: t('platform.dashboard.billing.viewInvoices'),
    },
    {
        key: 'overdueInvoices',
        label: t('platform.dashboard.billing.overdueInvoices'),
        hint: t('platform.dashboard.billing.overdueInvoicesHint'),
        count: props.billing.overdueInvoices,
        warn: props.billing.overdueInvoices > 0,
        href: null,
        link: '',
    },
    {
        key: 'pendingApprovals',
        label: t('platform.dashboard.billing.pendingApprovals'),
        hint: t('platform.dashboard.billing.pendingApprovalsHint'),
        count: props.billing.pendingApprovals,
        warn: false,
        href: allows('platform.tenants.view') ? route('platform.tenants.index', { status: 'pending' }) : null,
        link: t('platform.dashboard.billing.reviewPending'),
    },
]);
</script>

<template>
    <Card :title="t('platform.dashboard.billing.title')" :description="t('platform.dashboard.billing.description')" flush>
        <div class="grid grid-cols-1 border-t border-line md:grid-cols-2">
            <section class="min-w-0 px-5 py-4">
                <p class="eyebrow">{{ t('platform.dashboard.billing.subscriptions') }}</p>

                <p v-if="subscribed === 0" class="mt-3 text-[0.8125rem] text-ink-subtle">
                    {{ t('platform.dashboard.billing.noSubscriptions') }}
                </p>

                <dl v-else class="mt-3 flex flex-col gap-2">
                    <div v-for="entry in statuses" :key="entry.status" class="flex items-center justify-between gap-4">
                        <dt>
                            <Badge :tone="SUBSCRIPTION_TONES[entry.status] ?? 'neutral'" dot>{{ terms.subscriptionStatus(entry.status) }}</Badge>
                        </dt>
                        <dd :class="['tabular text-sm', entry.count > 0 ? 'font-medium text-ink' : 'text-ink-subtle']">
                            {{ format.number(entry.count) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="min-w-0 border-t border-line md:border-t-0 md:border-l">
                <p class="eyebrow px-5 pt-4">{{ t('platform.dashboard.billing.attention') }}</p>

                <dl class="mt-1">
                    <div
                        v-for="(entry, index) in waiting"
                        :key="entry.key"
                        :class="['grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 px-5 py-3', index > 0 ? 'border-t border-line' : '']"
                    >
                        <dt class="text-sm font-medium text-ink">{{ entry.label }}</dt>
                        <dd :class="['figure row-span-2 text-[1.625rem]', entry.warn ? 'text-warn' : entry.count > 0 ? 'text-ink' : 'text-ink-subtle']">
                            {{ format.number(entry.count) }}
                        </dd>
                        <dd class="col-start-1 text-[0.8125rem] text-ink-subtle">
                            {{ entry.hint }}
                            <!-- The way to the list is offered only when there is something on it. -->
                            <Link
                                v-if="entry.href && entry.count > 0"
                                :href="entry.href"
                                class="mt-0.5 flex w-fit items-center gap-1 rounded-chip font-medium text-brand-text underline-offset-4 hover:underline"
                            >
                                {{ entry.link }}
                                <ArrowUpRight class="size-3.5" aria-hidden="true" />
                            </Link>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </Card>
</template>
