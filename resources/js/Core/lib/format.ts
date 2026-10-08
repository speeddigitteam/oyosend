import type { Numerals } from '@/Core/types';

export interface FormatContext {
    locale: string;
    numerals: Numerals;
    timezone: string;
    currency: string;
}

export type DateStyle = 'date' | 'datetime' | 'time' | 'short';

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
export function formatMoney(minorUnits: number, context: FormatContext, currency?: string): string {
    const code = currency ?? context.currency;
    const formatter = new Intl.NumberFormat(localeTag(context), { style: 'currency', currency: code });
    const digits = formatter.resolvedOptions().maximumFractionDigits ?? 2;

    return formatter.format(minorUnits / 10 ** digits);
}

const DATE_OPTIONS: Record<DateStyle, Intl.DateTimeFormatOptions> = {
    date: { day: 'numeric', month: 'short', year: 'numeric' },
    datetime: { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' },
    time: { hour: 'numeric', minute: '2-digit' },
    short: { day: 'numeric', month: 'short' },
};

export function formatDate(value: string | Date | null | undefined, context: FormatContext, style: DateStyle = 'date'): string {
    if (!value) {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(localeTag(context), { ...DATE_OPTIONS[style], timeZone: context.timezone }).format(date);
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
