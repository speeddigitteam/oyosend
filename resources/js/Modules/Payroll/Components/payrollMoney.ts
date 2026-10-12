/**
 * Exact money and rate text handling for Payroll forms. Server amounts are
 * integer minor units and rates are basis points; nothing here uses a float.
 * Text is parsed digit by digit into BigInt, so "1250.50" is exactly 125050.
 */

/** Fraction digits of a currency (2 for BDT, 0 for JPY); 2 for anything unknown. */
export function currencyDigits(code: string): number {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        return 2;
    }
}

/** Bengali digits typed into a field become Latin ones, so both numeral settings can type an amount. */
function latinDigits(text: string): string {
    return text.replace(/[০-৯]/g, (digit) => String(digit.charCodeAt(0) - 0x09e6));
}

/**
 * "12.5" with scale 2 becomes 1250. Null for anything that is not a plain
 * non-negative decimal, that has more significant fraction digits than the
 * scale (nothing is rounded silently), or that does not fit a safe integer.
 */
export function parseDecimal(text: string, scale: number): number | null {
    const value = latinDigits(text.trim());

    if (!/^\d+(\.\d+)?$/.test(value)) {
        return null;
    }

    const [whole = '0', fraction = ''] = value.split('.');

    if (fraction.length > scale && /[1-9]/.test(fraction.slice(scale))) {
        return null;
    }

    const units = BigInt(whole + fraction.slice(0, scale).padEnd(scale, '0'));

    return units <= BigInt(Number.MAX_SAFE_INTEGER) ? Number(units) : null;
}

/** 1250 with scale 2 becomes "12.50". With `trim`, trailing zeros go: 750 with scale 2 -> "7.5". */
export function toDecimal(units: number, scale: number, trim = false): string {
    const negative = units < 0;
    const digits = String(Math.abs(units)).padStart(scale + 1, '0');
    const whole = digits.slice(0, digits.length - scale);
    let fraction = digits.slice(digits.length - scale);

    if (trim) {
        fraction = fraction.replace(/0+$/, '');
    }

    return `${negative ? '-' : ''}${whole}${fraction === '' ? '' : `.${fraction}`}`;
}

/** Money text ("1250.50") to integer minor units of the currency, or null when it is not an exact amount. */
export function parseMoney(text: string, currency: string): number | null {
    return parseDecimal(text, currencyDigits(currency));
}

/** Integer minor units to the text a money field shows (125050 -> "1250.50"). */
export function moneyText(minorUnits: number | null, currency: string): string {
    return minorUnits === null ? '' : toDecimal(minorUnits, currencyDigits(currency));
}

/** Percent text ("7.5") to basis points (750); at most two decimals. */
export function parseBps(text: string): number | null {
    return parseDecimal(text, 2);
}

/** Basis points to percent text, without trailing zeros (750 -> "7.5", 10000 -> "100"). */
export function bpsText(bps: number | null): string {
    return bps === null ? '' : toDecimal(bps, 2, true);
}

/** A fresh idempotency key; a retry of one submission reuses it, a new decision gets a new one. */
export function newIdempotencyKey(): string {
    const random = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;

    return `payroll-${random}`;
}

/** Today's date as Y-m-d in the given IANA time zone. */
export function todayIn(timezone: string, now: Date = new Date()): string {
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    } catch {
        return now.toISOString().slice(0, 10);
    }
}

/** Amount shown for a payslip: a reversal payslip carries positive amounts and is displayed negated. */
export function displayAmount(amount: number, isReversal: boolean): number {
    return isReversal && amount !== 0 ? -amount : amount;
}
