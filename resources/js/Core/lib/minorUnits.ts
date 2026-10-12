/**
 * Exact conversion between the decimal text a person types and the integer
 * minor units the server stores. No floating point is involved: a value with
 * more fraction digits than the currency has is rejected, never rounded.
 */

/** Fraction digits of a currency (2 for BDT, 0 for JPY); 2 for anything unknown. */
export function currencyDigits(currency: string): number {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        return 2;
    }
}

/** "12.5" in BDT becomes 1250, or null when the text is not an exact non-negative amount. */
export function toMinorUnits(text: string, currency: string): number | null {
    const digits = currencyDigits(currency);
    const match = /^(\d{1,12})(?:[.,](\d+))?$/.exec(text.trim());

    if (match === null || (match[2]?.length ?? 0) > digits) {
        return null;
    }

    return Number(match[1]) * 10 ** digits + Number((match[2] ?? '').padEnd(digits, '0') || 0);
}

/** 1250 in BDT becomes "12.50"; null becomes an empty string. */
export function fromMinorUnits(amount: number | null, currency: string): string {
    if (amount === null) {
        return '';
    }

    const digits = currencyDigits(currency);

    return (amount / 10 ** digits).toFixed(digits);
}
