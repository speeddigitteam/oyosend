/**
 * Pieces the central site's landing page and sign-up form share: the plans on
 * offer and what they cost, and the workspace address someone is choosing.
 */

export type BillingInterval = 'monthly' | 'yearly';

/** In the order the server tries them when a plan is not sold for the interval asked for. */
export const INTERVALS: readonly BillingInterval[] = ['monthly', 'yearly'];

/** A plan on offer, as `BillingPresenter::plan()` sends it. Only what these pages read. */
export interface PlanOffer {
    id: string;
    /** What the sign-up form posts and `?plan=` carries. */
    key: string;
    name: string;
    description: string | null;
    /** ISO 4217. */
    currency: string;
    /** Minor units for one month; null when the plan is not sold monthly. */
    monthlyAmount: number | null;
    /** Minor units for one year; null when the plan is not sold yearly. */
    yearlyAmount: number | null;
    trialDays: number;
    isFree: boolean;
}

export interface PlanPrice {
    /** Minor units, always more than zero. */
    amount: number;
    interval: BillingInterval;
}

export function amountFor(plan: PlanOffer, interval: BillingInterval): number | null {
    return interval === 'monthly' ? plan.monthlyAmount : plan.yearlyAmount;
}

/**
 * The interval a plan is billed at when `preferred` is asked for. The server
 * falls back to the first interval the plan is sold for, and so does this.
 */
export function billedInterval(plan: PlanOffer, preferred: BillingInterval): BillingInterval | null {
    if (amountFor(plan, preferred) !== null) {
        return preferred;
    }

    return INTERVALS.find((interval) => amountFor(plan, interval) !== null) ?? null;
}

/** What a plan costs when `preferred` is asked for, or null when that costs nothing. */
export function priceFor(plan: PlanOffer, preferred: BillingInterval): PlanPrice | null {
    const interval = billedInterval(plan, preferred);
    const amount = interval === null ? null : amountFor(plan, interval);

    return interval === null || amount === null || amount <= 0 ? null : { amount, interval };
}

/** Every price a plan has, monthly first. Empty for a plan that costs nothing. */
export function pricesOf(plan: PlanOffer): PlanPrice[] {
    return INTERVALS.flatMap((interval) => {
        const amount = amountFor(plan, interval);

        return amount !== null && amount > 0 ? [{ amount, interval }] : [];
    });
}

/** The intervals somebody would have to choose between: those at least one plan charges for. */
export function intervalsCharged(plans: PlanOffer[]): BillingInterval[] {
    return INTERVALS.filter((interval) => plans.some((plan) => (amountFor(plan, interval) ?? 0) > 0));
}

/** A trial only exists where there is something to pay afterwards. */
export function trialDaysFor(plan: PlanOffer, preferred: BillingInterval): number {
    return priceFor(plan, preferred) === null ? 0 : Math.max(0, plan.trialDays);
}

/** A workspace address is one DNS label. */
export const ADDRESS_MAX_LENGTH = 63;

/**
 * Room to leave at the end of an address field for the domain shown inside
 * it. `Input` reserves a fixed 2.5rem for its trailing slot, so the field sets
 * its own: field and suffix share one monospace size, which makes the suffix
 * exactly its length in `ch` (plus the letter-spacing `mono` adds) wide.
 */
export function suffixRoom(suffix: string): string {
    const characters = suffix.length;

    return `calc(${characters}ch + ${(characters * 0.025).toFixed(3)}em + 0.75rem)`;
}

/**
 * What has been typed into the address field so far, kept to what an address
 * can hold: lower-case letters, digits and hyphens. Works one character at a
 * time, so the caret can be put back where it was (`cleanAddress(before caret).length`).
 * A hyphen at the end is left alone - more is probably coming.
 */
export function cleanAddress(value: string): string {
    return value
        .toLowerCase()
        .replace(/[\s_.]/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/^-+/, '')
        .slice(0, ADDRESS_MAX_LENGTH);
}

/** The address as it will be sent: no hyphen left hanging at the end. */
export function finishAddress(value: string): string {
    return cleanAddress(value).replace(/-+$/, '');
}

/**
 * An address suggested from a company name: "Acme Trading Ltd." becomes
 * `acme-trading-ltd`. Empty when the name has nothing an address can hold (a
 * name written in Bengali, say) - the person then chooses one themselves.
 */
export function suggestAddress(company: string): string {
    return company
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+/, '')
        .slice(0, ADDRESS_MAX_LENGTH)
        .replace(/-+$/, '');
}
