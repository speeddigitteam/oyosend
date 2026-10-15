import type { Numerals } from '@/Core/types';

export interface FormatContext {
    locale: string;
    numerals: Numerals;
    timezone: string;
    currency: string;
}

export type DateStyle = 'date' | 'datetime' | 'time' | 'short' | 'weekday';
export type CanonicalIntegerString = `${bigint}`;
export type MoneyMinorUnits = number | CanonicalIntegerString;

/**
 * BCP 47 tag for Intl. The region is fixed to Bangladesh conventions
 * (day-month-year, lakh/crore grouping in Bengali); the numbering system is
 * the person's own choice and independent of the interface language.
 */
export function localeTag(context: Pick<FormatContext, 'locale' | 'numerals'>): string {
    const language = context.locale === 'bn' ? 'bn' : 'en';

    return `${language}-BD-u-nu-${context.numerals}`;
}

export function formatNumber(value: number, context: FormatContext, options: Intl.NumberFormatOptions = {}): string {
    return new Intl.NumberFormat(localeTag(context), options).format(value);
}

/**
 * Money is always stored and transported as an integer in minor units
 * (paisa, cents) with a currency code - never as a float.
 */
export function formatMoney(minorUnits: MoneyMinorUnits, context: FormatContext, currency?: string): string {
    const code = currency ?? context.currency;
    const formatter = new Intl.NumberFormat(localeTag(context), { style: 'currency', currency: code });
    const digits = formatter.resolvedOptions().maximumFractionDigits ?? 2;
    const canonical = typeof minorUnits === 'string';

    if (canonical && !/^(?:0|[1-9]\d*|-[1-9]\d*)$/.test(minorUnits)) {
        throw new RangeError('Money minor units must be a canonical decimal integer string.');
    }

    if (!canonical && !Number.isSafeInteger(minorUnits)) {
        throw new RangeError('Money minor units supplied as a number must be a safe integer.');
    }

    const amount = BigInt(minorUnits);
    const negative = amount < 0n;
    const absolute = negative ? -amount : amount;
    const divisor = 10n ** BigInt(digits);
    const major = absolute / divisor;
    const remainder = absolute % divisor;
    // BigInt has no negative zero, but the currency formatter needs one to
    // place the sign correctly for values such as -5 cents.
    const signedMajor: bigint | number = negative ? (major === 0n ? -0 : -major) : major;
    const fraction = digits === 0
        ? ''
        : new Intl.NumberFormat(localeTag(context), {
              useGrouping: false,
              minimumIntegerDigits: digits,
              maximumFractionDigits: 0,
          }).format(remainder);

    return formatter.formatToParts(signedMajor).map((part) => part.type === 'fraction' ? fraction : part.value).join('');
}

const DATE_OPTIONS: Record<DateStyle, Intl.DateTimeFormatOptions> = {
    date: { day: 'numeric', month: 'short', year: 'numeric' },
    datetime: { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' },
    time: { hour: 'numeric', minute: '2-digit' },
    short: { day: 'numeric', month: 'short' },
    // The name of the day alone ("Tuesday"), for dates within the last week.
    weekday: { weekday: 'long' },
};

export function formatDate(value: string | Date | null | undefined, context: FormatContext, style: DateStyle = 'date'): string {
    if (!value) {
        return '';
    }

    const isDateOnly = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
    const date = value instanceof Date ? value : new Date(isDateOnly ? `${value}T00:00:00Z` : value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(localeTag(context), { ...DATE_OPTIONS[style], timeZone: isDateOnly ? 'UTC' : context.timezone }).format(date);
}

const RELATIVE_STEPS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

export function formatRelative(value: string | Date | null | undefined, context: FormatContext, now: Date = new Date()): string {
    if (!value) {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const formatter = new Intl.RelativeTimeFormat(localeTag(context), { numeric: 'auto' });

    for (const [unit, size] of RELATIVE_STEPS) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(0, 'second');
}
