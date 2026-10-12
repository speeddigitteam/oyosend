<script setup lang="ts">
import { ArrowUpRight, Calculator } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import PayrollBadge from './PayrollBadge.vue';

/** Workspace payroll status for people who may view runs. */
defineProps<{
    data: {
        currency: string;
        openPeriods: number;
        awaitingApproval: number;
        unpaidPayslips: number;
        pendingLoans: number;
        latestRun: {
            id: string;
            number: string | null;
            periodName: string;
            status: 'draft' | 'submitted' | 'approved';
            netAmount: number;
            employeeCount: number;
        } | null;
    };
}>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('payroll.widget.overview.title')" class="h-full" data-testid="payroll-overview-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('payroll.runs.index')">
                {{ t('payroll.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <div class="grid gap-4">
            <dl class="grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-4">
                <div>
                    <dt class="eyebrow">{{ t('payroll.widget.overview.openPeriods') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink" data-testid="open-periods">{{ format.number(data.openPeriods) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('payroll.widget.overview.awaitingApproval') }}</dt>
                    <dd class="figure mt-2 text-2xl" :class="data.awaitingApproval > 0 ? 'text-brand-text' : 'text-ink'" data-testid="awaiting-approval">
                        {{ format.number(data.awaitingApproval) }}
                    </dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('payroll.widget.overview.unpaid') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink" data-testid="unpaid-payslips">{{ format.number(data.unpaidPayslips) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('payroll.widget.overview.pendingLoans') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink" data-testid="pending-loans">{{ format.number(data.pendingLoans) }}</dd>
                </div>
            </dl>

            <EmptyState v-if="data.latestRun === null" :title="t('payroll.widget.overview.noRun')" :description="t('payroll.widget.overview.noRunBody')" compact>
                <template #icon><Calculator /></template>
            </EmptyState>
            <div v-else class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
                <div class="min-w-0">
                    <p class="eyebrow">{{ t('payroll.widget.overview.latest') }}</p>
                    <Button size="sm" variant="ghost" :href="route('payroll.runs.show', { run: data.latestRun.id })" class="mt-1 max-w-full">
                        <span class="truncate">{{ data.latestRun.periodName }}</span>
                        <span v-if="data.latestRun.number" class="font-mono text-ink-subtle">{{ data.latestRun.number }}</span>
                    </Button>
                </div>
                <div class="flex items-center gap-3">
                    <span class="tabular font-mono text-sm text-ink">{{ format.money(data.latestRun.netAmount, data.currency) }}</span>
                    <PayrollBadge kind="run" :value="data.latestRun.status" />
                </div>
            </div>
        </div>
    </Card>
</template>
