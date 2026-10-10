import { describe, expect, it } from 'vitest';
import { fractionDigits, fromMinorUnits, latinDigits, toMinorUnits, toWholeNumber } from '@/Platform/Components/Plans/amount';
import { dateInZone, tomorrowInZone } from '@/Platform/Components/Workspaces/dates';

describe('a price typed into the plan form', () => {
    it('becomes integer minor units without passing through a float', () => {
        // The classic float traps: 19.99 * 100 and 0.07 * 100 are not whole numbers.
        expect(toMinorUnits('19.99', 2)).toEqual({ ok: true, value: 1999 });
        expect(toMinorUnits('0.07', 2)).toEqual({ ok: true, value: 7 });
        expect(toMinorUnits('1500', 2)).toEqual({ ok: true, value: 150000 });
        expect(toMinorUnits('1500.5', 2)).toEqual({ ok: true, value: 150050 });
        expect(toMinorUnits('1500.', 2)).toEqual({ ok: true, value: 150000 });
        expect(toMinorUnits('  0012.30 ', 2)).toEqual({ ok: true, value: 1230 });
        expect(toMinorUnits('4.35', 2)).toEqual({ ok: true, value: 435 });
    });

    it('tells an empty field from a price of zero', () => {
        expect(toMinorUnits('', 2)).toEqual({ ok: true, value: null });
        expect(toMinorUnits('   ', 2)).toEqual({ ok: true, value: null });
        expect(toMinorUnits('0', 2)).toEqual({ ok: true, value: 0 });
        expect(toMinorUnits('0.00', 2)).toEqual({ ok: true, value: 0 });
    });

    it('follows the currency: none of its decimals are assumed', () => {
        expect(fractionDigits('BDT')).toBe(2);
        expect(fractionDigits('JPY')).toBe(0);
        expect(toMinorUnits('1500', 0)).toEqual({ ok: true, value: 1500 });
        expect(toMinorUnits('1500.5', 0)).toEqual({ ok: false, reason: 'format' });
        expect(toMinorUnits('1.234', 3)).toEqual({ ok: true, value: 1234 });
    });

    it('reads Bengali digits', () => {
        expect(latinDigits('১৫০০.৫০')).toBe('1500.50');
        expect(toMinorUnits('১৫০০.৫০', 2)).toEqual({ ok: true, value: 150050 });
    });

    it('refuses what is not plainly an amount, instead of guessing', () => {
        for (const input of ['abc', '-5', '1,500', '1500,50', '1.2.3', '.5', '1e3', '12.345', '৳1500', '15 00']) {
            expect(toMinorUnits(input, 2), input).toEqual({ ok: false, reason: 'format' });
        }
    });

    it('refuses more than the server accepts', () => {
        expect(toMinorUnits('999999999.99', 2)).toEqual({ ok: true, value: 99_999_999_999 });
        expect(toMinorUnits('1000000000', 2)).toEqual({ ok: false, reason: 'range' });
        expect(toMinorUnits('99999999999999999999', 2)).toEqual({ ok: false, reason: 'range' });
    });

    it('is shown again the way it would be typed', () => {
        expect(fromMinorUnits(150050, 2)).toBe('1500.50');
        expect(fromMinorUnits(150000, 2)).toBe('1500');
        expect(fromMinorUnits(7, 2)).toBe('0.07');
        expect(fromMinorUnits(0, 2)).toBe('0');
        expect(fromMinorUnits(1500, 0)).toBe('1500');
        expect(fromMinorUnits(null, 2)).toBe('');
        expect(fromMinorUnits(undefined, 2)).toBe('');
    });

    it('survives a round trip for every amount of a currency unit', () => {
        for (let minor = 0; minor <= 1000; minor++) {
            expect(toMinorUnits(fromMinorUnits(minor, 2), 2)).toEqual({ ok: true, value: minor });
        }
    });
});

describe('a count typed into a text field', () => {
    it('is a whole number, null when empty, and left as typed when it is neither', () => {
        expect(toWholeNumber('14')).toBe(14);
        expect(toWholeNumber(' ১৪ ')).toBe(14);
        expect(toWholeNumber('-3')).toBe(-3);
        expect(toWholeNumber('')).toBeNull();
        expect(toWholeNumber('  ')).toBeNull();
        expect(toWholeNumber('1.5')).toBe('1.5');
        expect(toWholeNumber('many')).toBe('many');
    });
});

describe('a day on a workspace\'s own clock', () => {
    it('is the calendar date there, not here', () => {
        // 20:30 UTC on the 10th is already the 11th in Dhaka (UTC+6).
        const instant = new Date('2026-10-10T20:30:00Z');

        expect(dateInZone(instant, 'Asia/Dhaka')).toBe('2026-10-11');
        expect(dateInZone(instant, 'UTC')).toBe('2026-10-10');
        expect(dateInZone(instant, 'America/New_York')).toBe('2026-10-10');
        expect(tomorrowInZone('Asia/Dhaka', instant)).toBe('2026-10-12');
    });

    it('falls back to UTC for a zone the browser does not know', () => {
        expect(dateInZone(new Date('2026-10-10T20:30:00Z'), 'Nowhere/Land')).toBe('2026-10-10');
    });
});
