/**
 * Plain calendar dates and wall-clock times for the calendar page.
 *
 * The server sends every time already in the viewer's own zone as wall-clock text (`2026-10-17T13:00`), and dates as
 * `2026-10-17`, so the page lays out text, never instants: no browser time zone ever leaks into a calendar that
 * someone in Dhaka shares with someone in London. A date is carried as a UTC midnight internally.
 */

export type CalendarView = 'day' | 'four_days' | 'week' | 'month' | 'year' | 'schedule';

export const VIEWS: CalendarView[] = ['day', 'week', 'month', 'year', 'schedule', 'four_days'];

/** Days the schedule view lists from its anchor; the server's `CalendarRange::SCHEDULE_DAYS`. */
export const SCHEDULE_DAYS = 31;

export const MINUTES_PER_DAY = 1440;

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

export function parseDate(value: string): Date {
    const match = DATE.exec(value.slice(0, 10));

    if (!match) {
        throw new RangeError(`Not a date: ${value}`);
    }

    return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
}

export function toDateString(date: Date): string {
    return `${String(date.getUTCFullYear()).padStart(4, '0')}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

export function pad(value: number): string {
    return String(value).padStart(2, '0');
}

export function addDays(value: string, days: number): string {
    const date = parseDate(value);
    date.setUTCDate(date.getUTCDate() + days);

    return toDateString(date);
}

/** Months later or earlier, keeping the day where the month has it and the month's last day where it does not. */
export function addMonths(value: string, months: number): string {
    const date = parseDate(value);
    const day = date.getUTCDate();
    const target = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1));
    const last = daysInMonth(target.getUTCFullYear(), target.getUTCMonth());
    target.setUTCDate(Math.min(day, last));

    return toDateString(target);
}

export function daysInMonth(year: number, month0: number): number {
    return new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();
}

/** 0 = Sunday ... 6 = Saturday. */
export function weekday(value: string): number {
    return parseDate(value).getUTCDay();
}

export function daysBetween(from: string, to: string): number {
    return Math.round((parseDate(to).getTime() - parseDate(from).getTime()) / 86_400_000);
}

export function startOfWeek(value: string, weekStartsOn: number): string {
    return addDays(value, -((weekday(value) - weekStartsOn + 7) % 7));
}

export function startOfMonth(value: string): string {
    return `${value.slice(0, 7)}-01`;
}

export function endOfMonth(value: string): string {
    const date = parseDate(startOfMonth(value));

    return addDays(value.slice(0, 7) + '-01', daysInMonth(date.getUTCFullYear(), date.getUTCMonth()) - 1);
}

export interface DateRange {
    start: string;
    /** Exclusive. */
    end: string;
}

/** The dates a view shows around an anchor; the same as the server's `CalendarRange::for()`. */
export function rangeFor(view: CalendarView, anchor: string, weekStartsOn: number): DateRange {
    switch (view) {
        case 'day':
            return { start: anchor, end: addDays(anchor, 1) };
        case 'four_days':
            return { start: anchor, end: addDays(anchor, 4) };
        case 'week': {
            const start = startOfWeek(anchor, weekStartsOn);

            return { start, end: addDays(start, 7) };
        }
        case 'month':
            return {
                start: startOfWeek(startOfMonth(anchor), weekStartsOn),
                end: addDays(startOfWeek(endOfMonth(anchor), weekStartsOn), 7),
            };
        case 'year':
            return { start: `${anchor.slice(0, 4)}-01-01`, end: `${Number(anchor.slice(0, 4)) + 1}-01-01` };
        case 'schedule':
            return { start: anchor, end: addDays(anchor, SCHEDULE_DAYS) };
    }
}

/** The anchor one step forward (+1) or back (-1) in a view. */
export function shiftAnchor(view: CalendarView, anchor: string, direction: 1 | -1): string {
    switch (view) {
        case 'day':
            return addDays(anchor, direction);
        case 'four_days':
            return addDays(anchor, 4 * direction);
        case 'week':
            return addDays(anchor, 7 * direction);
        case 'month':
            return addMonths(anchor, direction);
        case 'year':
            return addMonths(anchor, 12 * direction);
        case 'schedule':
            return addDays(anchor, SCHEDULE_DAYS * direction);
    }
}

/** Every date from start (inclusive) to end (exclusive). */
export function datesIn(range: DateRange): string[] {
    const dates: string[] = [];

    for (let date = range.start; date < range.end; date = addDays(date, 1)) {
        dates.push(date);
    }

    return dates;
}

/** Six weeks of dates covering a month, for a month grid. */
export function monthMatrix(anyDayInMonth: string, weekStartsOn: number): string[][] {
    const first = startOfWeek(startOfMonth(anyDayInMonth), weekStartsOn);

    return Array.from({ length: 6 }, (_, week) => Array.from({ length: 7 }, (_, day) => addDays(first, week * 7 + day)));
}

/** The date part of a wall-clock time or a date. */
export function datePart(value: string): string {
    return value.slice(0, 10);
}

/** Minutes since midnight of a wall-clock time (`2026-10-17T13:30` -> 810). */
export function minuteOfDay(value: string): number {
    if (value.length < 16) {
        return 0;
    }

    return Number(value.slice(11, 13)) * 60 + Number(value.slice(14, 16));
}

/** A wall-clock time from a date and minutes since its midnight (which may run past it). */
export function wallTime(date: string, minutes: number): string {
    const days = Math.floor(minutes / MINUTES_PER_DAY);
    const rest = minutes - days * MINUTES_PER_DAY;

    return `${addDays(date, days)}T${pad(Math.floor(rest / 60))}:${pad(rest % 60)}`;
}

/** Minutes from one wall-clock time to another (dates included). */
export function minutesBetween(from: string, to: string): number {
    return daysBetween(datePart(from), datePart(to)) * MINUTES_PER_DAY + minuteOfDay(to) - minuteOfDay(from);
}

export function addMinutes(value: string, minutes: number): string {
    return wallTime(datePart(value), minuteOfDay(value) + minutes);
}

/** Now as a wall-clock time in a zone (`2026-10-17T13:05`). */
export function wallNow(timezone: string, now: Date = new Date()): string {
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(now);
        const get = (type: Intl.DateTimeFormatPartTypes): string => parts.find((part) => part.type === type)?.value ?? '00';

        return `${get('year')}-${get('month')}-${get('day')}T${get('hour') === '24' ? '00' : get('hour')}:${get('minute')}`;
    } catch {
        return now.toISOString().slice(0, 16);
    }
}

/** Snap minutes to a step (15 by default), rounding to the nearest. */
export function snap(minutes: number, step = 15): number {
    return Math.round(minutes / step) * step;
}

/** The zone's offset from UTC at a moment, as `GMT+06` / `GMT-03:30`. */
export function offsetLabel(timezone: string, now: Date = new Date()): string {
    try {
        const part = new Intl.DateTimeFormat('en-US', { timeZone: timezone, timeZoneName: 'shortOffset' })
            .formatToParts(now)
            .find((item) => item.type === 'timeZoneName')?.value;

        if (!part || part === 'GMT') {
            return 'GMT+00';
        }

        const match = /^GMT([+-])(\d{1,2})(?::(\d{2}))?$/.exec(part);

        return match ? `GMT${match[1]}${match[2]!.padStart(2, '0')}${match[3] ? `:${match[3]}` : ''}` : part;
    } catch {
        return 'GMT';
    }
}

/** Every IANA zone the browser knows, or just the given ones when it cannot list them. */
export function timezones(fallback: string[]): string[] {
    const list = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
    const all = new Set([...list, ...fallback.filter(Boolean)]);
    all.add('UTC');

    return [...all].sort();
}
