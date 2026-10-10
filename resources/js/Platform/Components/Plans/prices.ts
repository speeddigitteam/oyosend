import type { BillingInterval, PlanSummary } from '@/Core/Components/Billing/types';

/** Every interval a plan can be sold for, in the order they are shown. */
export const INTERVALS: readonly BillingInterval[] = ['monthly', 'yearly'];

/** The price of one period in minor units, or null when the plan is not sold for that interval. */
export function amountFor(plan: Pick<PlanSummary, 'monthlyAmount' | 'yearlyAmount'>, interval: BillingInterval): number | null {
    return interval === 'monthly' ? plan.monthlyAmount : plan.yearlyAmount;
}

/** The intervals a plan is sold for. */
export function intervalsOf(plan: Pick<PlanSummary, 'monthlyAmount' | 'yearlyAmount'>): BillingInterval[] {
    return INTERVALS.filter((interval) => amountFor(plan, interval) !== null);
}
