<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import RecoveryCodes from '@/Core/Components/RecoveryCodes.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import BillingOverviewCard, { type BillingOverview } from '@/Platform/Components/BillingOverviewCard.vue';
import PanelActivityCard, { type ActivityEntry } from '@/Platform/Components/PanelActivityCard.vue';
import RecentWorkspacesCard, { type RecentTenant } from '@/Platform/Components/RecentWorkspacesCard.vue';
import SystemHealthCard, { type HealthCheck } from '@/Platform/Components/SystemHealthCard.vue';

defineOptions({ layout: PlatformLayout });

interface Stats {
    /** Workspaces in any status. */
    tenants: number;
    active: number;
    suspended: number;
    pending: number;
    newThisMonth: number;
    /** Accounts across every workspace. */
    users: number;
    /** Seats: one account can hold several. */
    memberships: number;
    /** Modules compiled into this build. */
    modules: number;
}

const props = defineProps<{
    stats: Stats;
    /** Subscriptions by status, and what is waiting on an operator, across every workspace. */
    billing: BillingOverview;
    /** The six newest workspaces. */
    recentTenants: RecentTenant[];
    /** The eight latest platform-level audit entries. */
    activity: ActivityEntry[];
    health: HealthCheck[];
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();

/*
 * Recovery codes arrive once, as flash data, straight after enrolment. They
 * are copied out of the page object so that re-running the health checks (a
 * partial reload, which brings an empty flash) cannot take them off the
 * screen before they have been saved. Leaving the page still does.
 */
const recoveryCodes = ref<string[]>([]);

watch(
    () => page.flash?.recoveryCodes,
    (codes) => {
        if (codes && codes.length > 0) {
            recoveryCodes.value = codes;
        }
    },
    { immediate: true },
);

/*
 * The accent marker goes on one tile at most, and only for a count whose
 * normal value is zero. Pending workspaces are waiting on an operator, so
 * they outrank suspended ones, which an operator already dealt with.
 */
const attention = computed<'pending' | 'suspended' | null>(() => {
    if (props.stats.pending > 0) {
        return 'pending';
    }

    return props.stats.suspended > 0 ? 'suspended' : null;
});

// The counts that describe size rather than state: quieter than the tiles above them.
const totals = computed(() => [
    {
        key: 'users',
        label: t('platform.dashboard.totals.users'),
        hint: t('platform.dashboard.totals.usersHint'),
        value: props.stats.users,
    },
    {
        key: 'memberships',
        label: t('platform.dashboard.totals.memberships'),
        hint: t('platform.dashboard.totals.membershipsHint'),
        value: props.stats.memberships,
    },
    {
        key: 'modules',
        label: t('platform.dashboard.totals.modules'),
        hint: t('platform.dashboard.totals.modulesHint'),
        value: props.stats.modules,
    },
]);
</script>

<template>
    <!-- The notice is a tint made to lie on a card. Here it comes before any card, so it gets the glass itself. -->
    <div v-if="recoveryCodes.length > 0" class="glass mb-6 max-w-2xl overflow-hidden rounded-card">
        <RecoveryCodes :codes="recoveryCodes" />
    </div>

    <PageHeader
        :eyebrow="t('shell.nav.sections.platform')"
        :title="t('shell.nav.dashboard')"
        :description="t('platform.dashboard.description')"
    />

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatTile
            :label="t('platform.dashboard.stats.tenants')"
            :value="format.number(stats.tenants)"
            :hint="
                stats.newThisMonth > 0
                    ? t('platform.dashboard.stats.newThisMonth', { count: format.number(stats.newThisMonth) })
                    : t('platform.dashboard.stats.noneThisMonth')
            "
        />
        <StatTile
            :label="t('platform.dashboard.stats.active')"
            :value="format.number(stats.active)"
            :hint="t('platform.dashboard.stats.activeHint')"
        />
        <StatTile
            :label="t('platform.dashboard.stats.pending')"
            :value="format.number(stats.pending)"
            :hint="t('platform.dashboard.stats.pendingHint')"
            :accent="attention === 'pending'"
        />
        <StatTile
            :label="t('platform.dashboard.stats.suspended')"
            :value="format.number(stats.suspended)"
            :hint="t('platform.dashboard.stats.suspendedHint')"
            :accent="attention === 'suspended'"
        />
    </div>

    <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-12">
        <SystemHealthCard :checks="health" class="min-w-0 lg:col-span-7" />

        <Card :title="t('platform.dashboard.totals.title')" flush class="min-w-0 lg:col-span-5">
            <dl>
                <div
                    v-for="total in totals"
                    :key="total.key"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 border-t border-line px-5 py-3"
                >
                    <dt class="text-sm font-medium text-ink">{{ total.label }}</dt>
                    <dd class="figure row-span-2 text-[1.625rem] text-ink">{{ format.number(total.value) }}</dd>
                    <dd class="col-start-1 text-[0.8125rem] text-ink-subtle">{{ total.hint }}</dd>
                </div>
            </dl>
        </Card>

        <BillingOverviewCard :billing="billing" class="min-w-0 lg:col-span-12" />
        <RecentWorkspacesCard :tenants="recentTenants" class="min-w-0 lg:col-span-12" />
        <PanelActivityCard :entries="activity" class="min-w-0 lg:col-span-12" />
    </div>
</template>
