<script setup lang="ts">
import { ArrowUpRight, Receipt } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import PayrollBadge from './PayrollBadge.vue';

/** The signed-in person's own latest payslip on the dashboard. */
defineProps<{
    data: {
        count: number;
        latest: {
            id: string;
            number: string | null;
            periodName: string;
            netAmount: number;
            currency: string;
            paymentStatus: 'unpaid' | 'paid' | 'failed';
            paidOn: string | null;
        } | null;
    };
}>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('payroll.widget.self.title')" class="h-full" data-testid="payroll-self-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('payroll.payslips.index')">
                {{ t('payroll.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <EmptyState v-if="data.latest === null" :title="t('payroll.widget.self.empty')" :description="t('payroll.widget.self.emptyBody')" compact>
            <template #icon><Receipt /></template>
        </EmptyState>

        <div v-else class="grid gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm text-ink-muted">{{ data.latest.periodName }}</span>
                <PayrollBadge kind="payment" :value="data.latest.paymentStatus" />
            </div>
            <div>
                <p class="eyebrow">{{ t('payroll.fields.net') }}</p>
                <p class="figure mt-2 text-2xl text-ink" data-testid="self-net">{{ format.money(data.latest.netAmount, data.latest.currency) }}</p>
            </div>
            <p class="text-[0.8125rem] text-ink-subtle">
                <template v-if="data.latest.paidOn">{{ t('payroll.widget.self.paidOn', { date: format.date(data.latest.paidOn) }) }}</template>
                <template v-else>{{ t('payroll.widget.self.count', { count: format.number(data.count) }) }}</template>
            </p>
            <div>
                <Button size="sm" variant="secondary" :href="route('payroll.payslips.show', { payslip: data.latest.id })">
                    {{ t('payroll.widget.self.view') }}
                </Button>
            </div>
        </div>
    </Card>
</template>
