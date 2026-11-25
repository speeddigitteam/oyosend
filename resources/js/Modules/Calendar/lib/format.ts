import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { localeTag } from '@/Core/lib/format';
import type { Numerals } from '@/Core/types';
import { parseDate } from './dates';
import type { EventColor } from '../types';

export type TimeFormat = '12h' | '24h';

/** Dates and times as the person reads them: their language, numerals and 12/24-hour clock. Pure. */
export function createCalendarFormat(locale: string, numerals: Numerals, timeFormat: TimeFormat) {
    const tag = localeTag({ locale, numerals });
    const hourCycle = timeFormat === '24h' ? 'h23' : 'h12';
    const cache = new Map<string, Intl.DateTimeFormat>();
    const formatter = (options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat => {
        const key = JSON.stringify(options);
        let made = cache.get(key);

        if (!made) {
            made = new Intl.DateTimeFormat(tag, { ...options, timeZone: 'UTC' });
            cache.set(key, made);
        }

        return made;
    };
    const clock = (minutes: number): Date => new Date(Date.UTC(2000, 0, 1, Math.floor(minutes / 60) % 24, minutes % 60));

    return {
        tag,
        /** A date with any Intl options (always read as the calendar date it is, never shifted by a zone). */
        date: (value: string, options: Intl.DateTimeFormatOptions) => formatter(options).format(parseDate(value)),
        /** A time of day from minutes since midnight: `1:30 PM` or `13:30`. */
        time: (minutes: number) => formatter({ hour: 'numeric', minute: '2-digit', hourCycle }).format(clock(minutes)),
        /** An hour label for the grid's gutter: `1 PM` or `13:00`. */
        hour: (hour: number) =>
            timeFormat === '24h'
                ? formatter({ hour: '2-digit', minute: '2-digit', hourCycle }).format(clock(hour * 60))
                : formatter({ hour: 'numeric', hourCycle }).format(clock(hour * 60)),
        number: (value: number) => new Intl.NumberFormat(tag).format(value),
    };
}

export type CalendarFormat = ReturnType<typeof createCalendarFormat>;

/** {@see createCalendarFormat} bound to the page: the person's language and numerals and the calendar's clock. */
export function useCalendarFormat() {
    const page = usePage();

    return computed(() =>
        createCalendarFormat(
            page.props.app.locale,
            page.props.app.numerals,
            ((page.props as unknown as { preferences?: { timeFormat?: TimeFormat } }).preferences?.timeFormat ?? '12h'),
        ),
    );
}

/** Inline style that hands a calendar colour to the classes `bg-(--ev)`, `border-(--ev)` and `text-(--ev)`. */
export function colorVar(color: EventColor | null | undefined): Record<string, string> {
    return { '--ev': `var(--event-${color ?? 'blue'})` };
}
