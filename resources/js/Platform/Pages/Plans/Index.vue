<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Layers, Lock, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import { listAmountBeside } from '@/Core/Components/Billing/offer';
import type { BillingInterval, PlanSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import { INTERVALS, amountFor } from '@/Platform/Components/Plans/prices';

defineOptions({ layout: PlatformLayout });

interface PlanRow extends PlanSummary {
    /** Workspaces on the plan, whatever state their subscription is in. */
    subscribers: number;
}

const props = defineProps<{
    /** Active plans first, then by sort order and name. */
    plans: PlanRow[];
    /** Whether this operator may create and edit plans. */
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const count = computed(() =>
    t(props.plans.length === 1 ? 'platformPlans.index.totalOne' : 'platformPlans.index.total', { count: format.number(props.plans.length) }),
);

const columns = computed<Column[]>(() => {
    const list: Column[] = [
        { key: 'name', label: t('platformPlans.index.columns.plan'), class: 'min-w-48' },
        { key: 'monthlyAmount', label: t('platformPlans.index.columns.monthly'), align: 'right', class: 'whitespace-nowrap' },
        { key: 'yearlyAmount', label: t('platformPlans.index.columns.yearly'), align: 'right', class: 'whitespace-nowrap' },
        { key: 'trialDays', label: t('platformPlans.index.columns.trial'), class: 'whitespace-nowrap' },
        { key: 'subscribers', label: t('platformPlans.index.columns.subscribers'), align: 'right', class: 'whitespace-nowrap' },
        { key: 'isPublic', label: t('platformPlans.index.columns.visibility'), class: 'whitespace-nowrap' },
        { key: 'isActive', label: t('platformPlans.index.columns.state'), class: 'whitespace-nowrap' },
    ];

    if (props.canManage) {
        list.push({ key: 'actions', label: t('platformPlans.index.columns.actions'), srOnly: true, align: 'right', class: 'w-px' });
    }

    return list;
});

/** A price as the list shows it: an amount, "Free" for zero, or null when the plan is not sold that way. */
function price(plan: PlanRow, interval: BillingInterval): string | null {
    const amount = amountFor(plan, interval);

    if (amount === null) {
        return null;
    }

    return amount === 0 ? t('billing.terms.free') : format.money(amount, plan.currency);
}

/** The plan's own price while an offer runs below it, else null. */
function listPrice(plan: PlanRow, interval: BillingInterval): string | null {
    const list = listAmountBeside(plan, interval);

    return list === null ? null : format.money(list, plan.currency);
}

function trial(days: number): string {
    return t(days === 1 ? 'platformPlans.index.trialDay' : 'platformPlans.index.trialDays', { count: format.number(days) });
}
</script>

<template>
    <PageHeader :eyebrow="t('shell.nav.sections.platform')" :title="t('shell.nav.plans')" :description="t('platformPlans.index.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" :href="route('platform.plans.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('platformPlans.index.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card v-if="plans.length === 0">
        <EmptyState :title="t('platformPlans.index.empty.title')" :description="t('platformPlans.index.empty.body')">
            <template #icon><Layers /></template>
            <template v-if="canManage" #default>
                <Button size="sm" :href="route('platform.plans.create')">{{ t('platformPlans.index.create') }}</Button>
            </template>
        </EmptyState>
    </Card>

    <Card v-else flush>
        <DataTable :columns="columns" :rows="plans" row-key="id" :caption="t('platformPlans.index.caption')">
            <template #toolbar>
                <p class="eyebrow tabular px-1" role="status">{{ count }}</p>
            </template>

            <template #cell-name="{ row }">
                <p class="font-medium wrap-break-word text-ink">
                    <Link
                        v-if="canManage"
                        :href="route('platform.plans.edit', { plan: row.id })"
                        class="rounded-chip underline-offset-4 hover:underline"
                    >
                        {{ row.name }}
                    </Link>
                    <template v-else>{{ row.name }}</template>
                </p>
                <p class="font-mono text-xs text-ink-subtle">{{ row.key }}</p>
                <Badge v-if="row.offerEndsAt" tone="accent" class="mt-1">{{ t('billing.terms.offer.until', { date: format.date(row.offerEndsAt) }) }}</Badge>
            </template>

            <template v-for="interval in INTERVALS" :key="interval" #[`cell-${interval}Amount`]="{ row }">
                <template v-if="price(row, interval) !== null">
                    <span class="tabular text-ink">{{ price(row, interval) }}</span>
                    <!-- Beside a running offer, the plan's own price it takes off from. -->
                    <span v-if="listPrice(row, interval) !== null" class="block text-xs text-ink-subtle">
                        <span class="sr-only">{{ t('billing.terms.offer.was') }}</span>
                        <del class="tabular">{{ listPrice(row, interval) }}</del>
                    </span>
                </template>
                <span v-else class="text-ink-subtle">{{ t('billing.terms.notOffered') }}</span>
            </template>

            <template #cell-trialDays="{ row }">
                <span v-if="row.trialDays > 0" class="tabular text-ink-muted">{{ trial(row.trialDays) }}</span>
                <span v-else class="text-ink-subtle">{{ t('platformPlans.index.noTrial') }}</span>
            </template>

            <template #cell-subscribers="{ row }">
                <span :class="['tabular', row.subscribers > 0 ? 'text-ink' : 'text-ink-subtle']">{{ format.number(row.subscribers) }}</span>
            </template>

            <!-- The usual case stays quiet; what differs from it gets the badge. -->
            <template #cell-isPublic="{ row }">
                <span v-if="row.isPublic" class="text-ink-muted" :title="t('platformPlans.index.publicHint')">
                    {{ t('platformPlans.index.public') }}
                </span>
                <span v-else :title="t('platformPlans.index.hiddenHint')">
                    <Badge>{{ t('platformPlans.index.hidden') }}</Badge>
                </span>
            </template>

            <template #cell-isActive="{ row }">
                <span v-if="row.isActive" class="inline-flex items-center gap-1.5 text-ink-muted">
                    <span class="size-1.5 rounded-full bg-ok" aria-hidden="true" />
                    {{ t('platformPlans.index.active') }}
                </span>
                <Badge v-else tone="warn" dot>{{ t('platformPlans.index.archived') }}</Badge>
            </template>

            <template #cell-actions="{ row }">
                <Button size="sm" :href="route('platform.plans.edit', { plan: row.id })" :aria-label="t('platformPlans.index.editPlan', { name: row.name })">
                    {{ t('platformPlans.index.edit') }}
                </Button>
            </template>
        </DataTable>

        <template v-if="!canManage" #footer>
            <p class="flex items-start gap-2 text-[0.8125rem] text-ink-subtle">
                <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {{ t('platformPlans.index.viewOnly') }}
            </p>
        </template>
    </Card>
</template>
