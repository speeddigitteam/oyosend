import { describe, expect, it } from 'vitest';
import en from '@/Core/i18n/locales/en/calendar';
import bn from '@/Core/i18n/locales/bn/calendar';
import { addMonths, minutesBetween, monthMatrix, offsetLabel, rangeFor, shiftAnchor, wallNow, wallTime, type CalendarView } from '@/Modules/Calendar/lib/dates';
import { describeReminder, reminderUnit } from '@/Modules/Calendar/lib/describe';
import { createCalendarFormat } from '@/Modules/Calendar/lib/format';
import { hiddenPerColumn, isBanner, lastDate, layoutDay, layoutRow, piecesOn } from '@/Modules/Calendar/lib/layout';
import { describeRule, presetOf, presetRule, workWeekFrom } from '@/Modules/Calendar/lib/recurrence';
import { convertWall, wallToInstant } from '@/Modules/Calendar/lib/zones';
import type { CalendarEvent, EventColor } from '@/Modules/Calendar/types';

function event(start: string, end: string, overrides: Partial<CalendarEvent> = {}): CalendarEvent {
    return {
        key: `${start}-${end}-${Math.random()}`,
        id: 'e',
        seriesId: null,
        occurrence: null,
        calendarId: 'c',
        color: 'blue',
        allDay: start.length === 10,
        start,
        end,
        busy: false,
        title: 'Event',
        editable: true,
        recurring: false,
        ...overrides,
    };
}

const t = (key: string, values: Record<string, unknown> = {}): string => {
    const message = key.split('.').slice(1).reduce<unknown>((node, part) => (node as Record<string, unknown>)?.[part], en);

    return String(message).replace(/\{(\w+)\}/g, (_, name: string) => String(values[name] ?? ''));
};

describe('calendar dates', () => {
    it('computes the same ranges as the server', () => {
        const cases: Array<[CalendarView, string, number, string, string]> = [
            ['day', '2026-10-17', 0, '2026-10-17', '2026-10-18'],
            ['four_days', '2026-10-17', 0, '2026-10-17', '2026-10-21'],
            ['week', '2026-10-17', 0, '2026-10-11', '2026-10-18'],
            ['week', '2026-10-17', 6, '2026-10-17', '2026-10-24'],
            ['week', '2026-10-17', 1, '2026-10-12', '2026-10-19'],
            ['month', '2026-10-17', 0, '2026-09-27', '2026-11-01'],
            ['month', '2026-08-05', 0, '2026-07-26', '2026-09-06'],
            ['year', '2026-10-17', 0, '2026-01-01', '2027-01-01'],
            ['schedule', '2026-10-17', 0, '2026-10-17', '2026-11-17'],
        ];

        for (const [view, anchor, weekStart, start, end] of cases) {
            expect(rangeFor(view, anchor, weekStart)).toEqual({ start, end });
        }
    });

    it('steps through views and keeps month ends sensible', () => {
        expect(shiftAnchor('week', '2026-10-17', 1)).toBe('2026-10-24');
        expect(shiftAnchor('month', '2026-01-31', 1)).toBe('2026-02-28');
        expect(addMonths('2024-02-29', 12)).toBe('2025-02-28');
        expect(monthMatrix('2026-10-17', 0)[0]![0]).toBe('2026-09-27');
    });

    it('does wall-clock arithmetic across days', () => {
        expect(wallTime('2026-10-17', 25 * 60)).toBe('2026-10-18T01:00');
        expect(minutesBetween('2026-10-17T23:00', '2026-10-18T01:30')).toBe(150);
    });

    it('reads time zones without the browser\'s own', () => {
        const moment = new Date(Date.UTC(2026, 9, 17, 7, 0));

        expect(wallNow('Asia/Dhaka', moment)).toBe('2026-10-17T13:00');
        expect(wallNow('America/New_York', moment)).toBe('2026-10-17T03:00');
        expect(offsetLabel('Asia/Dhaka', moment)).toBe('GMT+06');
        expect(offsetLabel('Asia/Kolkata', moment)).toBe('GMT+05:30');
        expect(wallToInstant('2026-10-17T13:00', 'Asia/Dhaka').toISOString()).toBe('2026-10-17T07:00:00.000Z');
        expect(convertWall('2026-10-17T13:00', 'Asia/Dhaka', 'Europe/London')).toBe('2026-10-17T08:00');
        // New York is on summer time until 1 November 2026.
        expect(wallToInstant('2026-11-02T09:00', 'America/New_York').toISOString()).toBe('2026-11-02T14:00:00.000Z');
    });
});

describe('calendar layout', () => {
    it('puts overlapping events side by side and lets others use the full width', () => {
        const pieces = piecesOn([
            event('2026-10-17T09:00', '2026-10-17T10:00'),
            event('2026-10-17T09:30', '2026-10-17T11:00'),
            event('2026-10-17T12:00', '2026-10-17T13:00'),
        ], '2026-10-17');
        const placed = layoutDay(pieces);

        expect(placed.map((piece) => [piece.column, piece.columns])).toEqual([[0, 2], [1, 2], [0, 1]]);
    });

    it('cuts an overnight event at midnight and moves day-long ones to the all-day strip', () => {
        const overnight = event('2026-10-17T22:00', '2026-10-18T02:00');

        expect(piecesOn([overnight], '2026-10-17')[0]).toMatchObject({ start: 1320, end: 1440, continuesAfter: true });
        expect(piecesOn([overnight], '2026-10-18')[0]).toMatchObject({ start: 0, end: 120, continuesBefore: true });
        expect(isBanner(event('2026-10-17T08:00', '2026-10-18T08:00'))).toBe(true);
        expect(lastDate(event('2026-10-17', '2026-10-19'))).toBe('2026-10-18');
        expect(lastDate(event('2026-10-17T20:00', '2026-10-18T00:00'))).toBe('2026-10-17');
    });

    it('lays banners in lanes and counts what does not fit', () => {
        const dates = ['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17'];
        const segments = layoutRow([
            event('2026-10-12', '2026-10-15'),
            event('2026-10-13', '2026-10-14'),
            event('2026-10-09', '2026-10-12'),
        ], dates);

        expect(segments.map((segment) => [segment.from, segment.to, segment.lane, segment.continuesBefore])).toEqual([
            [0, 0, 0, true],
            [1, 3, 0, false],
            [2, 2, 1, false],
        ]);
        expect(hiddenPerColumn(segments, 7, 1)).toEqual([0, 0, 1, 0, 0, 0, 0]);
    });
});

describe('calendar repetition', () => {
    const format = createCalendarFormat('en', 'latn', '12h');

    it('offers presets and recognises them again', () => {
        const weekly = presetRule('weekly', '2026-10-17', workWeekFrom(0));

        expect(weekly).toMatchObject({ frequency: 'weekly', weekdays: [6] });
        expect(presetOf(weekly, '2026-10-17', workWeekFrom(0))).toBe('weekly');
        expect(presetOf({ ...weekly!, interval: 2 }, '2026-10-17', workWeekFrom(0))).toBe('custom');
        expect(workWeekFrom(6)).toEqual([0, 1, 2, 3, 4]);
        expect(workWeekFrom(0)).toEqual([1, 2, 3, 4, 5]);
    });

    it('describes a rule in words', () => {
        expect(describeRule({ frequency: 'weekly', interval: 2, weekdays: [1, 3], monthly: 'day', count: null, until: '2026-12-31' }, '2026-10-12', t, format))
            .toBe('Every 2 weeks on Monday and Wednesday, until Dec 31, 2026');
        expect(describeRule({ frequency: 'monthly', interval: 1, weekdays: [], monthly: 'weekday', count: 5, until: null }, '2026-10-17', t, format))
            .toBe('Monthly on the third Saturday, 5 times');
    });

    it('describes notifications', () => {
        expect(reminderUnit(120)).toEqual(['hours', 2]);
        expect(reminderUnit(90)).toEqual(['minutes', 90]);
        expect(describeReminder(10, false, t, format)).toBe('10 minutes before');
        expect(describeReminder(1440, true, t, format)).toBe('The day before at 9:00 AM');
    });
});

describe('calendar locale', () => {
    it('names every colour in both languages', () => {
        const colors: EventColor[] = ['red', 'pink', 'orange', 'yellow', 'sage', 'green', 'teal', 'blue', 'lavender', 'purple', 'graphite'];

        for (const color of colors) {
            expect(en.colors[color]).toBeTruthy();
            expect(bn.colors[color]).toBeTruthy();
        }
    });
});
