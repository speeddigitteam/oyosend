import { addDays, datePart, minuteOfDay } from './dates';
import type { CalendarFormat } from './format';
import type { CalendarEvent } from '../types';

type Translate = (key: string, values?: Record<string, unknown>) => string;

/** "Saturday, 17 October · 1:00 – 2:00 PM", "17 – 19 October", or a span across days, in the reader's terms. */
export function describeWhen(event: Pick<CalendarEvent, 'start' | 'end' | 'allDay'>, t: Translate, format: CalendarFormat): string {
    const long: Intl.DateTimeFormatOptions = { weekday: 'long', day: 'numeric', month: 'long' };
    const short: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };

    if (event.allDay) {
        const last = addDays(event.end, -1);

        return last === event.start
            ? format.date(event.start, long)
            : t('calendar.grid.timeRange', { start: format.date(event.start, short), end: format.date(last, { day: 'numeric', month: 'short', year: 'numeric' }) });
    }

    const startDate = datePart(event.start);
    const endDate = datePart(event.end);
    const startTime = format.time(minuteOfDay(event.start));
    const endTime = format.time(minuteOfDay(event.end));

    if (startDate === endDate || (addDays(startDate, 1) === endDate && minuteOfDay(event.end) === 0)) {
        return t('calendar.event.dateAndTime', { date: format.date(startDate, long), time: t('calendar.grid.timeRange', { start: startTime, end: endTime }) });
    }

    return t('calendar.grid.timeRange', {
        start: t('calendar.event.dateAndTime', { date: format.date(startDate, short), time: startTime }),
        end: t('calendar.event.dateAndTime', { date: format.date(endDate, short), time: endTime }),
    });
}

/** "10 minutes before", "1 day before at 9:00", in the reader's terms. */
export function describeReminder(minutes: number, allDay: boolean, t: Translate, format: CalendarFormat): string {
    if (allDay) {
        const days = Math.ceil(minutes / 1440);

        return days === 0
            ? t('calendar.reminders.onTheDay', { time: format.time(9 * 60) })
            : t(`calendar.reminders.daysBeforeAt.${days === 1 ? 'one' : 'other'}`, { count: format.number(days), time: format.time(9 * 60) });
    }

    if (minutes === 0) {
        return t('calendar.reminders.atStart');
    }

    const [unit, amount] = reminderUnit(minutes);

    return t(`calendar.reminders.before.${unit}.${amount === 1 ? 'one' : 'other'}`, { count: format.number(amount) });
}

export type ReminderUnit = 'minutes' | 'hours' | 'days' | 'weeks';

export const REMINDER_FACTORS: Record<ReminderUnit, number> = { minutes: 1, hours: 60, days: 1440, weeks: 10_080 };

/** The largest whole unit a lead is written in: 120 -> hours 2, 90 -> minutes 90. */
export function reminderUnit(minutes: number): [ReminderUnit, number] {
    for (const unit of ['weeks', 'days', 'hours'] as const) {
        if (minutes >= REMINDER_FACTORS[unit] && minutes % REMINDER_FACTORS[unit] === 0) {
            return [unit, minutes / REMINDER_FACTORS[unit]];
        }
    }

    return ['minutes', minutes];
}
