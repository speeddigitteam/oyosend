import { daysInMonth, parseDate, weekday } from './dates';
import type { Frequency, MonthlyMode, Recurrence } from '../types';

/** The repeat choices offered for an event starting on a date, as Google-style presets. */
export type RepeatPreset = 'none' | 'daily' | 'weekly' | 'weekdays' | 'monthly_day' | 'monthly_weekday' | 'monthly_last' | 'yearly' | 'custom';

export const FREQUENCIES: Frequency[] = ['daily', 'weekly', 'monthly', 'yearly'];

/** Which week of its month a date falls in: 1 to 5. */
export function weekOfMonth(date: string): number {
    return Math.floor((parseDate(date).getUTCDate() - 1) / 7) + 1;
}

/** Whether the date is the last of its weekday in its month. */
export function isLastWeekday(date: string): boolean {
    const day = parseDate(date);

    return day.getUTCDate() + 7 > daysInMonth(day.getUTCFullYear(), day.getUTCMonth());
}

export function presetRule(preset: RepeatPreset, start: string, workWeek: number[]): Recurrence | null {
    const base = { interval: 1, weekdays: [] as number[], monthly: 'day' as MonthlyMode, count: null, until: null };

    switch (preset) {
        case 'daily':
            return { ...base, frequency: 'daily' };
        case 'weekly':
            return { ...base, frequency: 'weekly', weekdays: [weekday(start)] };
        case 'weekdays':
            return { ...base, frequency: 'weekly', weekdays: [...workWeek] };
        case 'monthly_day':
            return { ...base, frequency: 'monthly' };
        case 'monthly_weekday':
            return { ...base, frequency: 'monthly', monthly: 'weekday' };
        case 'monthly_last':
            return { ...base, frequency: 'monthly', monthly: 'last_weekday' };
        case 'yearly':
            return { ...base, frequency: 'yearly' };
        default:
            return null;
    }
}

/** The preset a rule is, or `custom` when it is something more particular. */
export function presetOf(rule: Recurrence | null | undefined, start: string, workWeek: number[]): RepeatPreset {
    if (!rule) {
        return 'none';
    }

    if (rule.interval !== 1 || rule.count !== null || rule.until !== null) {
        return 'custom';
    }

    const days = [...rule.weekdays].sort().join();

    switch (rule.frequency) {
        case 'daily':
            return 'daily';
        case 'weekly':
            if (days === String(weekday(start)) || days === '') {
                return 'weekly';
            }

            return days === [...workWeek].sort().join() ? 'weekdays' : 'custom';
        case 'monthly':
            return rule.monthly === 'weekday' ? 'monthly_weekday' : rule.monthly === 'last_weekday' ? 'monthly_last' : 'monthly_day';
        case 'yearly':
            return 'yearly';
    }
}

/**
 * The working week the "every weekday" choice means. A week that starts on Saturday is read as a Friday-Saturday
 * weekend (Sunday to Thursday, as in Bangladesh and much of the Middle East); otherwise Monday to Friday. The label
 * names the days, so nobody has to guess.
 */
export function workWeekFrom(weekStartsOn: number): number[] {
    return weekStartsOn === 6 ? [0, 1, 2, 3, 4] : [1, 2, 3, 4, 5];
}

type Translate = (key: string, values?: Record<string, unknown>) => string;

interface Formatter {
    tag: string;
    date: (value: string, options: Intl.DateTimeFormatOptions) => string;
    number: (value: number) => string;
}

/** A weekday's name from its number (0 = Sunday), via a known Sunday. */
export function weekdayName(day: number, format: Formatter, style: 'long' | 'short' | 'narrow' = 'long'): string {
    return format.date(`2026-01-${String(4 + day).padStart(2, '0')}`, { weekday: style });
}

/** "Every 2 weeks on Monday and Wednesday, until 31 Dec 2026", in the reader's language. */
export function describeRule(rule: Recurrence, start: string, t: Translate, format: Formatter): string {
    const plural = rule.interval === 1 ? 'one' : 'other';
    const count = format.number(rule.interval);
    const startDay = weekday(start);
    let text: string;

    switch (rule.frequency) {
        case 'daily':
            text = t(`calendar.repeat.summary.daily.${plural}`, { count });
            break;
        case 'weekly': {
            const days = (rule.weekdays.length ? rule.weekdays : [startDay]).map((day) => weekdayName(day, format));
            const list = new Intl.ListFormat(format.tag, { style: 'long', type: 'conjunction' }).format(days);
            text = t(`calendar.repeat.summary.weekly.${plural}`, { count, days: list });
            break;
        }
        case 'monthly':
            if (rule.monthly === 'day') {
                text = t(`calendar.repeat.summary.monthlyDay.${plural}`, { count, day: format.number(parseDate(start).getUTCDate()) });
            } else if (rule.monthly === 'weekday') {
                text = t(`calendar.repeat.summary.monthlyWeekday.${plural}`, { count, nth: t(`calendar.repeat.nth.n${weekOfMonth(start)}`), weekday: weekdayName(startDay, format) });
            } else {
                text = t(`calendar.repeat.summary.monthlyLast.${plural}`, { count, weekday: weekdayName(startDay, format) });
            }
            break;
        case 'yearly':
            text = t(`calendar.repeat.summary.yearly.${plural}`, { count, date: format.date(start, { day: 'numeric', month: 'long' }) });
            break;
    }

    if (rule.until) {
        return t('calendar.repeat.summary.until', { rule: text, date: format.date(rule.until, { day: 'numeric', month: 'short', year: 'numeric' }) });
    }

    if (rule.count !== null) {
        return t(`calendar.repeat.summary.times.${rule.count === 1 ? 'one' : 'other'}`, { rule: text, count: format.number(rule.count) });
    }

    return text;
}
