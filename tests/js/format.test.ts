import { describe, expect, it } from 'vitest';
import { formatDate, formatMoney, formatNumber, formatRelative, localeTag, type FormatContext } from '@/Core/lib/format';

const english: FormatContext = { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' };
const bengali: FormatContext = { locale: 'bn', numerals: 'beng', timezone: 'Asia/Dhaka', currency: 'BDT' };

describe('localeTag', () => {
    it('keeps the numeral system independent of the interface language', () => {
        expect(localeTag({ locale: 'en', numerals: 'latn' })).toBe('en-BD-u-nu-latn');
        expect(localeTag({ locale: 'bn', numerals: 'beng' })).toBe('bn-BD-u-nu-beng');
        expect(localeTag({ locale: 'bn', numerals: 'latn' })).toBe('bn-BD-u-nu-latn');
        expect(localeTag({ locale: 'en', numerals: 'beng' })).toBe('en-BD-u-nu-beng');
    });

    it('falls back to English for an unknown language', () => {
        expect(localeTag({ locale: 'xx', numerals: 'latn' })).toBe('en-BD-u-nu-latn');
    });
});

describe('formatNumber', () => {
    it('uses Latin digits when asked to', () => {
        expect(formatNumber(1234, english)).toBe('1,234');
    });

    it('uses Bengali digits when asked to', () => {
        expect(formatNumber(1234, bengali)).toBe('১,২৩৪');
        expect(formatNumber(5, bengali)).toBe('৫');
    });

    it('can show Latin digits in a Bengali interface', () => {
        expect(formatNumber(42, { ...bengali, numerals: 'latn' })).toBe('42');
    });
});

describe('formatMoney', () => {
    it('treats the amount as integer minor units', () => {
        // 1,250.50 BDT is stored as 125050 paisa.
        const formatted = formatMoney(125050, english);

        expect(formatted).toContain('1,250.50');
    });

    it('never produces floating point noise', () => {
        // 0.1 + 0.2 style inputs are impossible by construction: 30 paisa is 30.
        expect(formatMoney(30, english)).toContain('0.30');
    });

    it('respects currencies without minor units', () => {
        expect(formatMoney(500, english, 'JPY')).toContain('500');
        expect(formatMoney(500, english, 'JPY')).not.toContain('5.00');
    });

    it('formats in Bengali digits', () => {
        expect(formatMoney(125050, bengali)).toContain('১,২৫০.৫০');
    });
});

describe('formatDate', () => {
    const iso = '2026-10-08T18:30:00Z'; // 00:30 on 9 October in Dhaka (UTC+6)

    it('renders in the workspace timezone, not the browser one', () => {
        expect(formatDate(iso, english, 'date')).toContain('9');
        expect(formatDate(iso, { ...english, timezone: 'UTC' }, 'date')).toContain('8');
    });

    it('renders Bengali digits and month names', () => {
        const formatted = formatDate(iso, bengali, 'date');

        expect(formatted).toContain('৯');
        expect(formatted).toMatch(/অক্টোবর|অক্টো/);
    });

    it('returns an empty string for missing or invalid input', () => {
        expect(formatDate(null, english)).toBe('');
        expect(formatDate(undefined, english)).toBe('');
        expect(formatDate('not a date', english)).toBe('');
    });
});

describe('formatRelative', () => {
    const now = new Date('2026-10-08T12:00:00Z');

    it('picks a sensible unit', () => {
        expect(formatRelative('2026-10-08T11:35:00Z', english, now)).toBe('25 minutes ago');
        expect(formatRelative('2026-10-08T09:00:00Z', english, now)).toBe('3 hours ago');
        expect(formatRelative('2026-10-07T12:00:00Z', english, now)).toBe('yesterday');
        expect(formatRelative('2026-10-08T12:00:20Z', english, now)).toBe('now');
    });

    it('handles the future', () => {
        expect(formatRelative('2026-10-15T12:00:00Z', english, now)).toBe('next week');
    });

    it('uses Bengali digits', () => {
        expect(formatRelative('2026-10-08T11:35:00Z', bengali, now)).toContain('২৫');
    });

    it('returns an empty string for missing input', () => {
        expect(formatRelative(null, english, now)).toBe('');
    });
});
