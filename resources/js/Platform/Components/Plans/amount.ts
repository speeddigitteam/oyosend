/*
 * A price is typed the way it is written ("1500", "1500.50") and sent to the
 * server as an integer in minor units. The conversion works on the digits as
 * text: no float ever holds the amount, so 19.99 cannot become
 * 1998.9999999999998 on its way to the database.
 */

/** The largest amount the server accepts, in minor units. */
export const MAX_MINOR_UNITS = 99_999_999_999;

const BENGALI_ZERO = '০'.charCodeAt(0);

/** Bengali digits written as Latin ones; everything else is left alone. */
export function latinDigits(text: string): string {
    return text.replace(/[০-৯]/g, (digit) => String(digit.charCodeAt(0) - BENGALI_ZERO));
}

/** How many digits a currency has after the decimal point: 2 for BDT, 0 for JPY. */
export function fractionDigits(currency: string): number {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        // Not a currency this browser knows; the server decides whether it is one at all.
        return 2;
    }
}

export type ParsedAmount =
    /** `null` is an empty field: the plan is not sold for that interval. */
    | { ok: true; value: number | null }
    /** `format`: not an amount, or more decimals than the currency has. `range`: more than the server accepts. */
    | { ok: false; reason: 'format' | 'range' };

/**
 * What was typed, as minor units. Digits and one decimal point only: a comma
 * is refused rather than guessed at, because "1,500" and "1500,50" mean
 * different things to different people.
 */
export function toMinorUnits(input: string, digits: number): ParsedAmount {
    const text = latinDigits(input).trim();

    if (text === '') {
        return { ok: true, value: null };
    }

    const match = /^(\d+)(?:\.(\d*))?$/.exec(text);

    if (match === null) {
        return { ok: false, reason: 'format' };
    }

    const whole = match[1]!;
    const fraction = match[2] ?? '';

    if (fraction.length > digits) {
        return { ok: false, reason: 'format' };
    }

    // "1500" + "5" padded to "50" = "150050": the amount in minor units, still as text.
    const minor = (whole + fraction.padEnd(digits, '0')).replace(/^0+(?=\d)/, '');

    if (minor.length > String(MAX_MINOR_UNITS).length) {
        return { ok: false, reason: 'range' };
    }

    // At most eleven digits: an integer a number holds exactly.
    const value = Number(minor);

    return value > MAX_MINOR_UNITS ? { ok: false, reason: 'range' } : { ok: true, value };
}

/** Minor units as someone would type them: `150050` is "1500.50", `150000` is "1500". */
export function fromMinorUnits(amount: number | null | undefined, digits: number): string {
    if (amount === null || amount === undefined) {
        return '';
    }

    const text = String(amount);

    if (digits === 0) {
        return text;
    }

    const padded = text.padStart(digits + 1, '0');
    const whole = padded.slice(0, -digits);
    const fraction = padded.slice(-digits);

    return /^0+$/.test(fraction) ? whole : `${whole}.${fraction}`;
}

/**
 * A whole number typed into a text field, or null when the field is empty.
 * Anything else comes back as it was typed, for the server to refuse in words.
 */
export function toWholeNumber(input: string): number | string | null {
    const text = latinDigits(input).trim();

    if (text === '') {
        return null;
    }

    return /^-?\d{1,15}$/.test(text) ? Number(text) : input;
}
