import type { BillingInterval, PlanSummary, SubscriptionSummary } from './types';

/** A plan as the billing page lists it: `current` marks the one the workspace is on. */
export type OfferedPlan = PlanSummary & { current: boolean };

/**
 * What choosing a plan will do. Each case is one path through
 * App\Core\Billing\SubscriptionManager::changePlan().
 *
 * It exists to tell the person what will happen before they confirm. The
 * server decides; a subscription that changed in the meantime may answer
 * differently, and the page then shows what really happened.
 */
export type PlanChangeOutcome =
    /** Not sold for this interval, or no longer sold at all. */
    | { kind: 'unavailable' }
    /** Already on exactly this: there is nothing to change. */
    | { kind: 'current' }
    /** Takes back a cancellation that has not taken effect. */
    | { kind: 'keepEnding' }
    /** Drops a scheduled move to a cheaper plan. */
    | { kind: 'keepPending' }
    /** A free plan, at once. */
    | { kind: 'freeNow' }
    /** A free plan, once the paid period is used up. */
    | { kind: 'freeLater'; at: string }
    /** Another plan during the trial: at once, the trial's end does not move. */
    | { kind: 'trial'; at: string | null }
    /** Not more expensive: waits for the paid period to end. */
    | { kind: 'later'; at: string }
    /** More expensive, inside a paid period: an invoice with credit, applied when paid. */
    | { kind: 'upgrade' }
    /** The workspace's first plan: on it at once, with the grace period to pay. */
    | { kind: 'first' }
    /** A payment is overdue: the unpaid invoice is replaced by one for this plan. */
    | { kind: 'replace' }
    /** The subscription has ended: paying the invoice brings the workspace back. */
    | { kind: 'reopen' }
    /** Anything else that costs money: starts when its invoice is paid. */
    | { kind: 'invoice' };

const MONTHS: Record<BillingInterval, number> = { monthly: 1, yearly: 12 };

/** The price of one period, or null when the plan is not sold for that interval. */
export function planAmount(plan: PlanSummary, interval: BillingInterval): number | null {
    return interval === 'monthly' ? plan.monthlyAmount : plan.yearlyAmount;
}

/**
 * The interval to ask for. A free plan is the same whichever one is shown,
 * so it is taken for the one it is listed under.
 */
export function planInterval(plan: PlanSummary, shown: BillingInterval): BillingInterval {
    if (!plan.isFree || planAmount(plan, shown) !== null) {
        return shown;
    }

    return shown === 'monthly' ? 'yearly' : 'monthly';
}

export function planChangeOutcome(
    subscription: SubscriptionSummary | null,
    plan: OfferedPlan,
    interval: BillingInterval,
    now: Date = new Date(),
): PlanChangeOutcome {
    const amount = planAmount(plan, interval);

    if (amount === null || !plan.isActive) {
        return { kind: 'unavailable' };
    }

    if (subscription === null) {
        return { kind: amount === 0 ? 'freeNow' : 'first' };
    }

    const unchanged = plan.current && (plan.isFree || subscription.interval === interval);

    if (unchanged && !subscription.locked && subscription.pending === null && !subscription.cancelAtPeriodEnd) {
        return { kind: 'current' };
    }

    const periodEnd = subscription.periodEnd;
    const inPaidPeriod =
        subscription.status === 'active' && !subscription.isFree && periodEnd !== null && new Date(periodEnd).getTime() > now.getTime();

    if (amount === 0) {
        return inPaidPeriod ? { kind: 'freeLater', at: periodEnd } : { kind: 'freeNow' };
    }

    if (subscription.status === 'trialing') {
        return unchanged ? { kind: 'keepEnding' } : { kind: 'trial', at: subscription.trialEndsAt };
    }

    if (inPaidPeriod) {
        if (unchanged) {
            return { kind: subscription.cancelAtPeriodEnd ? 'keepEnding' : 'keepPending' };
        }

        // More expensive per month than what the workspace pays now.
        const upgrade = amount * MONTHS[subscription.interval] > subscription.amount * MONTHS[interval];

        return upgrade ? { kind: 'upgrade' } : { kind: 'later', at: periodEnd };
    }

    if (subscription.status === 'past_due') {
        return { kind: 'replace' };
    }

    return { kind: subscription.locked ? 'reopen' : 'invoice' };
}
