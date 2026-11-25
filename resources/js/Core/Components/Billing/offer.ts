/**
 * A plan's limited-time offer, as the pages show it (App\Core\Billing\Models\Plan::offerAmountFor()).
 */

/**
 * The whole percent an offer takes off a price, rounded down so it never claims more than it gives. 0 when there is
 * no saving.
 */
export function percentOff(list: number | null | undefined, offer: number | null | undefined): number {
    if (list === null || list === undefined || offer === null || offer === undefined || list <= 0 || offer >= list) {
        return 0;
    }

    return Math.floor(((list - offer) / list) * 100);
}

/** What paying for a year saves against twelve months, in whole percent; 0 when it saves nothing. */
export function yearlySaving(monthly: number | null | undefined, yearly: number | null | undefined): number {
    return monthly === null || monthly === undefined ? 0 : percentOff(monthly * 12, yearly);
}

/** The plan's own price for an interval while an offer runs below it, else null. */
export function listAmountBeside(
    plan: { monthlyAmount: number | null; yearlyAmount: number | null; listMonthlyAmount?: number | null; listYearlyAmount?: number | null },
    interval: 'monthly' | 'yearly',
): number | null {
    const amount = interval === 'monthly' ? plan.monthlyAmount : plan.yearlyAmount;
    const list = interval === 'monthly' ? plan.listMonthlyAmount : plan.listYearlyAmount;

    return amount === null || list === null || list === undefined || list <= amount ? null : list;
}
