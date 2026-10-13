/** The Marketing API's object statuses, mapped to the badge tone the interface uses. */
export type Tone = 'neutral' | 'ok' | 'warn' | 'danger' | 'info';

export function statusTone(status: string | null | undefined): Tone {
    switch ((status ?? '').toUpperCase()) {
        case 'ACTIVE':
            return 'ok';
        case 'PAUSED':
        case 'CAMPAIGN_PAUSED':
        case 'ADSET_PAUSED':
            return 'neutral';
        case 'PENDING_REVIEW':
        case 'IN_PROCESS':
        case 'PREAPPROVED':
        case 'PENDING_BILLING_INFO':
            return 'info';
        case 'WITH_ISSUES':
            return 'warn';
        case 'DISAPPROVED':
            return 'danger';
        default:
            return 'neutral';
    }
}

/** Known statuses have a translation; anything else is shown as Meta sent it. */
export const KNOWN_STATUSES = [
    'ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'PENDING_REVIEW', 'IN_PROCESS', 'PREAPPROVED',
    'PENDING_BILLING_INFO', 'WITH_ISSUES', 'DISAPPROVED', 'ARCHIVED', 'DELETED',
];

/** Basis points (10000 = 100%) as a percentage string without the sign, e.g. 1250 -> 12.5. */
export function bpToPercent(basisPoints: number): number {
    return basisPoints / 100;
}

/** A new request id for one confirmation dialog: replaying it can never apply a change twice. */
export function newRequestId(): string {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** A budget in minor units as the plain decimal text of its currency, for an input. */
export function minorToDecimal(minor: number, currency: string): string {
    const digits = new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;

    if (digits === 0) {
        return String(minor);
    }

    const scale = 10 ** digits;

    return `${Math.floor(minor / scale)}.${String(minor % scale).padStart(digits, '0')}`;
}
