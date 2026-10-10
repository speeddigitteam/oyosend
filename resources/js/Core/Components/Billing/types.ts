import type { SubscriptionStatus } from '@/Core/types';

/*
 * The shapes App\Core\Billing\Support\BillingPresenter sends. The workspace's
 * billing pages and the Super Admin panel read the same fields.
 */

export type BillingInterval = 'monthly' | 'yearly';
export type InvoiceStatus = 'open' | 'paid' | 'void';
export type InvoiceKind = 'subscription' | 'renewal' | 'change' | 'app_purchase' | 'app_renewal';
export type PaymentStatus = 'pending' | 'succeeded' | 'failed' | 'canceled';

/** Money is always an integer in minor units, with its currency beside it. */
export interface PlanSummary {
    id: string;
    key: string;
    name: string;
    description: string | null;
    currency: string;
    /** Price of one period, or null when the plan is not sold for that interval. */
    monthlyAmount: number | null;
    yearlyAmount: number | null;
    trialDays: number;
    /** Keys of the optional modules the plan includes. */
    modules: string[];
    /** Limit key to maximum. A key the plan does not name is not limited. */
    limits: Record<string, number>;
    isFree: boolean;
    isPublic: boolean;
    isActive: boolean;
    sortOrder: number;
}

export interface SubscriptionSummary {
    status: SubscriptionStatus;
    plan: { id: string; key: string; name: string };
    interval: BillingInterval;
    /** The price the plan was taken at. */
    amount: number;
    currency: string;
    isFree: boolean;
    /** Ended: the workspace is locked down to billing. */
    locked: boolean;
    trialEndsAt: string | null;
    periodStart: string | null;
    periodEnd: string | null;
    graceEndsAt: string | null;
    cancelAtPeriodEnd: boolean;
    endedAt: string | null;
    /** A cheaper plan waiting for the paid period to end. */
    pending: { plan: string; interval: BillingInterval } | null;
}

export interface InvoiceSummary {
    id: string;
    number: string;
    kind: InvoiceKind;
    status: InvoiceStatus;
    /** Open and past its due date. */
    overdue: boolean;
    currency: string;
    subtotalAmount: number;
    creditAmount: number;
    totalAmount: number;
    interval: BillingInterval | null;
    periodStart: string | null;
    periodEnd: string | null;
    issuedAt: string;
    dueAt: string | null;
    paidAt: string | null;
    voidedAt: string | null;
}

/** `plan` lines name the plan and interval; a `credit` line has a negative amount. */
export interface InvoiceLine {
    type: string;
    plan?: string;
    interval?: string;
    app?: string;
    offer?: string;
    billing_type?: string;
    quantity?: number;
    amount: number;
}

/** Who the invoice was made out to, as it stood on the day of issue. */
export interface InvoiceRecipient {
    name?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    city?: string | null;
    postal_code?: string | null;
    /** ISO 3166-1 alpha-2. */
    country?: string | null;
    tax_id?: string | null;
}

export interface InvoiceIssuer {
    name?: string | null;
    address?: string | null;
    email?: string | null;
    tax_id?: string | null;
}

/** An invoice with everything printed on it. */
export interface InvoiceDocumentData extends InvoiceSummary {
    lines: InvoiceLine[];
    billedTo: InvoiceRecipient | null;
    issuer: InvoiceIssuer | null;
}

export interface PaymentSummary {
    id: string;
    /** Our own reference, the one given to the gateway. */
    reference: string;
    /** Gateway key, or `offline` for money recorded by an operator. */
    gateway: string;
    method: string | null;
    status: PaymentStatus;
    amount: number;
    currency: string;
    paidAt: string | null;
    failureReason: string | null;
    createdAt: string;
    /** Super Admin panel only. */
    note?: string | null;
    bankReference?: string | null;
}

export interface UsageEntry {
    /** A registered limit key: `core.members`. */
    key: string;
    /** `gauge`: what exists now. `metered`: what was consumed in this billing period. */
    kind: 'gauge' | 'metered';
    /** Null when the plan sets no limit. */
    limit: number | null;
    used: number;
}

export interface GatewayOption {
    key: string;
    name: string;
}

/** Gateway key of a payment an operator recorded. */
export const OFFLINE_GATEWAY = 'offline';
