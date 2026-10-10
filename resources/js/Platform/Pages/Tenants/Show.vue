<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CalendarPlus, CirclePause, CirclePlay, CircleCheck, ExternalLink, Layers, LifeBuoy, Lock, LogIn, Receipt, ScrollText } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceStatusBadge from '@/Core/Components/Billing/InvoiceStatusBadge.vue';
import SubscriptionStatusBadge from '@/Core/Components/Billing/SubscriptionStatusBadge.vue';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type { InvoiceSummary, PlanSummary, SubscriptionSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { eventLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import { useAbilities } from '@/Platform/Components/abilities';
import DetailRow from '@/Platform/Components/DetailRow.vue';
import TenantStatusBadge from '@/Platform/Components/TenantStatusBadge.vue';
import ApproveDialog from '@/Platform/Components/Workspaces/ApproveDialog.vue';
import ExtendTrialDialog from '@/Platform/Components/Workspaces/ExtendTrialDialog.vue';
import ReactivateDialog from '@/Platform/Components/Workspaces/ReactivateDialog.vue';
import SetPlanDialog from '@/Platform/Components/Workspaces/SetPlanDialog.vue';
import SupportAccessDialog from '@/Platform/Components/Workspaces/SupportAccessDialog.vue';
import SuspendDialog from '@/Platform/Components/Workspaces/SuspendDialog.vue';
import type { SupportSessionEntry, WorkspaceAbilities, WorkspaceActivity, WorkspaceDetail } from '@/Platform/Components/Workspaces/types';

defineOptions({ layout: PlatformLayout });

const props = defineProps<{
    workspace: WorkspaceDetail;
    /** Null when the workspace has no plan. */
    subscription: SubscriptionSummary | null;
    /** The twelve latest invoices. */
    invoices: InvoiceSummary[];
    /** Active plans, public or not: what "Set plan" can give. */
    plans: PlanSummary[];
    /** The ten latest entries of the workspace's own audit trail. */
    activity: WorkspaceActivity[];
    /** The five latest support sessions. */
    supportSessions: SupportSessionEntry[];
    can: WorkspaceAbilities;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();
const { allows } = useAbilities();

/** The address as people type it: host, and port when there is one. */
const host = computed(() => {
    try {
        return new URL(props.workspace.url).host;
    } catch {
        return props.workspace.url;
    }
});

const regional = computed(() =>
    [page.props.app.locales[props.workspace.locale] ?? props.workspace.locale, props.workspace.timezone, props.workspace.currency].join(' · '),
);

const contact = computed(() => [props.workspace.email, props.workspace.phone].filter((value): value is string => !!value));

/*
 * What this operator may do here. A control is shown only for something that
 * can be done to the workspace as it stands: the server refuses, for example,
 * to open a workspace that is not active or has no owner.
 */
const canApprove = computed(() => props.can.manage && props.workspace.status === 'pending');
const canSuspend = computed(() => props.can.manage && props.workspace.status === 'active');
const canReactivate = computed(() => props.can.manage && props.workspace.status === 'suspended');
const canImpersonate = computed(() => props.can.impersonate && props.workspace.status === 'active' && props.workspace.owner !== null);
const viewOnly = computed(() => !props.can.manage && !props.can.impersonate && !props.can.manageBilling);

// When the page was rendered: what "still running" and "in a paid period" are measured against.
const now = Date.now();

/** A trial can be given or moved unless the plan is free or a paid period is still running. */
const canExtendTrial = computed(() => {
    const subscription = props.subscription;

    if (!props.can.manageBilling || subscription === null || subscription.isFree) {
        return false;
    }

    const inPaidPeriod = subscription.status === 'active' && subscription.periodEnd !== null && new Date(subscription.periodEnd).getTime() > now;

    return !inPaidPeriod;
});

const approveOpen = ref(false);
const suspendOpen = ref(false);
const reactivateOpen = ref(false);
const impersonateOpen = ref(false);
const planOpen = ref(false);
const trialOpen = ref(false);

for (const dialog of [approveOpen, suspendOpen, reactivateOpen, impersonateOpen, planOpen, trialOpen]) {
    useFocusReturn(dialog);
}

const invoiceColumns = computed<Column[]>(() => [
    { key: 'number', label: t('platformWorkspaces.show.invoices.columns.number'), class: 'whitespace-nowrap' },
    { key: 'status', label: t('platformWorkspaces.show.invoices.columns.status'), class: 'whitespace-nowrap' },
    { key: 'totalAmount', label: t('platformWorkspaces.show.invoices.columns.total'), align: 'right', class: 'whitespace-nowrap' },
    { key: 'issuedAt', label: t('platformWorkspaces.show.invoices.columns.issued'), class: 'whitespace-nowrap' },
    { key: 'dueAt', label: t('platformWorkspaces.show.invoices.columns.due'), class: 'whitespace-nowrap' },
]);

/** Where a support session stands, in words. */
function sessionState(session: SupportSessionEntry): { text: string; running: boolean } {
    if (session.endedAt) {
        return { text: t('platformWorkspaces.show.support.state.ended', { time: format.date(session.endedAt, 'datetime') }), running: false };
    }

    // The link that carries the session to the workspace was never used.
    if (!session.startedAt) {
        return { text: t('platformWorkspaces.show.support.state.unused'), running: false };
    }

    const time = format.date(session.expiresAt, 'datetime');

    // A session whose time is up is closed by the next request made in it, if one is ever made.
    return new Date(session.expiresAt).getTime() > now
        ? { text: t('platformWorkspaces.show.support.state.running', { time }), running: true }
        : { text: t('platformWorkspaces.show.support.state.timedOut', { time }), running: false };
}
</script>

<template>
    <PageHeader :eyebrow="t('shell.nav.tenants')" :title="workspace.name">
        <template #actions>
            <Button variant="ghost" :href="route('platform.tenants.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('platformWorkspaces.back') }}
            </Button>
            <Button v-if="canImpersonate" @click="impersonateOpen = true">
                <template #icon><LogIn class="size-4" aria-hidden="true" /></template>
                {{ t('platformWorkspaces.show.actions.impersonate') }}
            </Button>
            <Button v-if="canSuspend" @click="suspendOpen = true">
                <template #icon><CirclePause class="size-4" aria-hidden="true" /></template>
                {{ t('platformWorkspaces.show.actions.suspend') }}
            </Button>
            <Button v-if="canApprove" variant="primary" @click="approveOpen = true">
                <template #icon><CircleCheck class="size-4" aria-hidden="true" /></template>
                {{ t('platformWorkspaces.show.actions.approve') }}
            </Button>
            <Button v-if="canReactivate" variant="primary" @click="reactivateOpen = true">
                <template #icon><CirclePlay class="size-4" aria-hidden="true" /></template>
                {{ t('platformWorkspaces.show.actions.reactivate') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">
        <Card :title="t('platformWorkspaces.show.overview.title')" flush class="min-w-0 lg:col-span-7">
            <dl>
                <DetailRow :label="t('platformWorkspaces.show.overview.status')">
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <TenantStatusBadge :status="workspace.status" />
                        <span v-if="workspace.status === 'suspended' && workspace.suspendedAt" class="text-[0.8125rem] text-ink-muted">
                            {{ t('platformWorkspaces.show.overview.suspendedAt', { date: format.date(workspace.suspendedAt, 'datetime') }) }}
                        </span>
                    </span>
                </DetailRow>

                <DetailRow :label="t('platformWorkspaces.show.overview.address')">
                    <a
                        :href="workspace.url"
                        target="_blank"
                        rel="noopener"
                        class="group/address inline-flex items-center gap-1.5 rounded-chip font-mono text-[0.8125rem] text-ink-muted transition-colors hover:text-brand-text"
                    >
                        <span class="break-all underline-offset-4 group-hover/address:underline">{{ host }}</span>
                        <ExternalLink class="size-3.5 shrink-0 text-ink-subtle group-hover/address:text-brand-text" aria-hidden="true" />
                        <span class="sr-only">({{ t('platform.tenants.opensNewTab') }})</span>
                    </a>
                </DetailRow>

                <DetailRow :label="t('platformWorkspaces.show.overview.owner')">
                    <template v-if="workspace.owner">
                        <span class="block">{{ workspace.owner.name }}</span>
                        <span class="block text-[0.8125rem] break-all text-ink-muted">{{ workspace.owner.email }}</span>
                    </template>
                    <span v-else class="text-ink-subtle">{{ t('platformWorkspaces.show.overview.noOwner') }}</span>
                </DetailRow>

                <DetailRow :label="t('platformWorkspaces.show.overview.members')">
                    <span class="tabular">{{ format.number(workspace.members) }}</span>
                </DetailRow>

                <DetailRow :label="t('platformWorkspaces.show.overview.created')">
                    <time :datetime="workspace.createdAt" class="tabular">{{ format.date(workspace.createdAt, 'datetime') }}</time>
                </DetailRow>

                <DetailRow :label="t('platformWorkspaces.show.overview.setup')">
                    <span v-if="workspace.onboarded">{{ t('platformWorkspaces.show.overview.setupDone') }}</span>
                    <span v-else class="text-ink-muted">{{ t('platformWorkspaces.show.overview.setupPending') }}</span>
                </DetailRow>

                <DetailRow :label="t('platformWorkspaces.show.overview.regional')">{{ regional }}</DetailRow>

                <DetailRow v-if="workspace.legalName" :label="t('platformWorkspaces.show.overview.legalName')">
                    {{ workspace.legalName }}
                </DetailRow>

                <DetailRow v-if="contact.length > 0" :label="t('platformWorkspaces.show.overview.contact')">
                    <span v-for="value in contact" :key="value" class="block break-all">{{ value }}</span>
                </DetailRow>
            </dl>

            <template v-if="viewOnly" #footer>
                <p class="flex items-start gap-2 text-[0.8125rem] text-ink-subtle">
                    <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    {{ t('platformWorkspaces.show.viewOnly') }}
                </p>
            </template>
        </Card>

        <Card :title="t('platformWorkspaces.show.subscription.title')" flush class="min-w-0 lg:col-span-5">
            <template v-if="can.manageBilling && subscription" #actions>
                <Button v-if="canExtendTrial" size="sm" variant="ghost" @click="trialOpen = true">
                    <template #icon><CalendarPlus class="size-3.5" aria-hidden="true" /></template>
                    {{ t('platformWorkspaces.show.subscription.extendTrial') }}
                </Button>
                <Button size="sm" @click="planOpen = true">{{ t('platformWorkspaces.show.subscription.setPlan') }}</Button>
            </template>

            <EmptyState
                v-if="subscription === null"
                :title="t('platformWorkspaces.show.subscription.none')"
                :description="t('platformWorkspaces.show.subscription.noneBody')"
                compact
            >
                <template #icon><Layers /></template>
                <template v-if="can.manageBilling" #default>
                    <Button size="sm" @click="planOpen = true">{{ t('platformWorkspaces.show.subscription.setPlan') }}</Button>
                </template>
            </EmptyState>

            <template v-else>
                <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2 px-5 pb-4">
                    <div class="min-w-0">
                        <p class="font-display text-lg font-semibold tracking-tight wrap-break-word text-ink">{{ subscription.plan.name }}</p>
                        <p class="mt-0.5 text-[0.8125rem] text-ink-muted">
                            <template v-if="subscription.isFree">{{ t('billing.terms.free') }}</template>
                            <template v-else>
                                <span class="tabular text-ink">{{ format.money(subscription.amount, subscription.currency) }}</span>
                                {{ terms.perInterval(subscription.interval) }}
                            </template>
                        </p>
                    </div>
                    <SubscriptionStatusBadge :status="subscription.status" />
                </div>

                <dl>
                    <DetailRow v-if="subscription.status === 'trialing' && subscription.trialEndsAt" :label="t('platformWorkspaces.show.subscription.trialEnds')">
                        <time :datetime="subscription.trialEndsAt" class="tabular">{{ format.date(subscription.trialEndsAt, 'datetime') }}</time>
                    </DetailRow>
                    <DetailRow v-if="!subscription.isFree && subscription.periodEnd" :label="t('platformWorkspaces.show.subscription.periodEnds')">
                        <time :datetime="subscription.periodEnd" class="tabular">{{ format.date(subscription.periodEnd, 'datetime') }}</time>
                    </DetailRow>
                    <DetailRow v-if="subscription.graceEndsAt" :label="t('platformWorkspaces.show.subscription.graceEnds')">
                        <time :datetime="subscription.graceEndsAt" class="tabular">{{ format.date(subscription.graceEndsAt, 'datetime') }}</time>
                    </DetailRow>
                    <DetailRow v-if="subscription.endedAt" :label="t('platformWorkspaces.show.subscription.ended')">
                        <time :datetime="subscription.endedAt" class="tabular">{{ format.date(subscription.endedAt, 'datetime') }}</time>
                    </DetailRow>
                </dl>

                <ul
                    v-if="subscription.locked || subscription.cancelAtPeriodEnd || subscription.pending"
                    class="flex flex-col gap-1.5 border-t border-line px-5 py-3 text-[0.8125rem] text-ink-muted"
                >
                    <li v-if="subscription.locked" class="flex items-start gap-2 text-warn">
                        <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        {{ t('platformWorkspaces.show.subscription.locked') }}
                    </li>
                    <li v-if="subscription.cancelAtPeriodEnd">{{ t('platformWorkspaces.show.subscription.cancels') }}</li>
                    <li v-if="subscription.pending">
                        {{
                            t('platformWorkspaces.show.subscription.pending', {
                                plan: subscription.pending.plan,
                                interval: terms.interval(subscription.pending.interval),
                            })
                        }}
                    </li>
                </ul>
            </template>
        </Card>

        <Card :title="t('platformWorkspaces.show.invoices.title')" flush class="min-w-0 lg:col-span-12">
            <EmptyState
                v-if="invoices.length === 0"
                :title="t('platformWorkspaces.show.invoices.empty')"
                :description="t('platformWorkspaces.show.invoices.emptyBody')"
                compact
            >
                <template #icon><Receipt /></template>
            </EmptyState>

            <DataTable v-else :columns="invoiceColumns" :rows="invoices" row-key="id" :caption="t('platformWorkspaces.show.invoices.caption')">
                <template #cell-number="{ row }">
                    <span class="flex flex-col">
                        <Link
                            v-if="allows('platform.billing.view')"
                            :href="route('platform.invoices.show', { invoice: row.id })"
                            class="rounded-chip font-mono text-[0.8125rem] text-ink underline-offset-4 hover:text-brand-text hover:underline"
                        >
                            {{ row.number }}
                        </Link>
                        <span v-else class="font-mono text-[0.8125rem] text-ink">{{ row.number }}</span>
                        <span class="text-xs text-ink-subtle">{{ terms.invoiceKind(row.kind) }}</span>
                    </span>
                </template>

                <template #cell-status="{ row }">
                    <InvoiceStatusBadge :status="row.status" :overdue="row.overdue" />
                </template>

                <template #cell-totalAmount="{ row }">
                    <span class="tabular text-ink">{{ format.money(row.totalAmount, row.currency) }}</span>
                </template>

                <template #cell-issuedAt="{ row }">
                    <time :datetime="row.issuedAt" :title="format.date(row.issuedAt, 'datetime')" class="tabular text-ink-muted">
                        {{ format.date(row.issuedAt) }}
                    </time>
                </template>

                <template #cell-dueAt="{ row }">
                    <time v-if="row.dueAt" :datetime="row.dueAt" :title="format.date(row.dueAt, 'datetime')" class="tabular text-ink-muted">
                        {{ format.date(row.dueAt) }}
                    </time>
                    <span v-else class="text-ink-subtle">—</span>
                </template>
            </DataTable>
        </Card>

        <Card
            :title="t('platformWorkspaces.show.activity.title')"
            :description="t('platformWorkspaces.show.activity.description')"
            flush
            class="min-w-0 lg:col-span-7"
        >
            <EmptyState
                v-if="activity.length === 0"
                :title="t('platformWorkspaces.show.activity.empty')"
                :description="t('platformWorkspaces.show.activity.emptyBody')"
                compact
            >
                <template #icon><ScrollText /></template>
            </EmptyState>

            <ul v-else>
                <li
                    v-for="entry in activity"
                    :key="entry.id"
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 border-t border-line px-5 py-3"
                >
                    <p class="min-w-0 text-sm text-ink">
                        <!-- The tooltip gives the event's own name, which is what the audit log is searched by. -->
                        <span :title="entry.event">{{ eventLabel(entry.event) }}</span>
                        <span v-if="entry.subject" class="text-ink-muted"> · {{ entry.subject }}</span>
                        <span class="block text-[0.8125rem] break-all text-ink-subtle">{{ entry.actor ?? t('common.system') }}</span>
                    </p>
                    <time
                        :datetime="entry.createdAt"
                        :title="format.date(entry.createdAt, 'datetime')"
                        class="tabular shrink-0 text-[0.8125rem] text-ink-subtle"
                    >
                        {{ format.relative(entry.createdAt) }}
                    </time>
                </li>
            </ul>
        </Card>

        <Card
            :title="t('platformWorkspaces.show.support.title')"
            :description="t('platformWorkspaces.show.support.description')"
            flush
            class="min-w-0 lg:col-span-5"
        >
            <EmptyState
                v-if="supportSessions.length === 0"
                :title="t('platformWorkspaces.show.support.empty')"
                :description="t('platformWorkspaces.show.support.emptyBody')"
                compact
            >
                <template #icon><LifeBuoy /></template>
            </EmptyState>

            <ul v-else>
                <li v-for="session in supportSessions" :key="session.id" class="border-t border-line px-5 py-3">
                    <p class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                        <span class="min-w-0 text-sm font-medium break-all text-ink">{{ session.by }}</span>
                        <time
                            :datetime="session.createdAt"
                            :title="format.date(session.createdAt, 'datetime')"
                            class="tabular shrink-0 text-[0.8125rem] text-ink-subtle"
                        >
                            {{ format.relative(session.createdAt) }}
                        </time>
                    </p>
                    <p class="mt-1 flex items-start gap-2 text-[0.8125rem] text-ink-muted">
                        <span
                            :class="['mt-1.75 size-1.5 shrink-0 rounded-full', sessionState(session).running ? 'bg-accent' : 'bg-line-strong']"
                            aria-hidden="true"
                        />
                        {{ sessionState(session).text }}
                    </p>
                    <p class="mt-1.5 text-[0.8125rem] wrap-break-word text-ink-muted">
                        <span class="eyebrow mr-1.5">{{ t('platformWorkspaces.show.support.reason') }}</span>
                        {{ session.reason }}
                    </p>
                </li>
            </ul>
        </Card>
    </div>

    <ApproveDialog v-if="can.manage" v-model:open="approveOpen" :workspace="workspace" />
    <SuspendDialog v-if="can.manage" v-model:open="suspendOpen" :workspace="workspace" />
    <ReactivateDialog v-if="can.manage" v-model:open="reactivateOpen" :workspace="workspace" />
    <SupportAccessDialog
        v-if="can.impersonate && workspace.owner"
        v-model:open="impersonateOpen"
        :workspace="workspace"
        :owner="workspace.owner"
    />
    <template v-if="can.manageBilling">
        <SetPlanDialog v-model:open="planOpen" :workspace="workspace" :plans="plans" />
        <ExtendTrialDialog v-model:open="trialOpen" :workspace="workspace" :trial-ends-at="subscription?.trialEndsAt ?? null" />
    </template>
</template>
