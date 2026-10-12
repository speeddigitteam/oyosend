import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { localeTag } from '@/Core/lib/format';

/** One leave day is two units: a half day is one. Units are shown as days. */
export const UNITS_PER_DAY = 2;

export function unitsToDays(units: number): number {
    return units / UNITS_PER_DAY;
}

/** ISO weekdays, 1 Monday to 7 Sunday. */
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** `Y-m-d` of a date, read in UTC so it never shifts with the browser's zone. */
export function isoDate(date: Date): string {
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}

/** Every date of a `Y-m` month, and how many blank cells precede the first when weeks start on Monday. */
export function monthGrid(month: string): { dates: string[]; leading: number } {
    const [year, mon] = month.split('-').map(Number) as [number, number];
    const first = new Date(Date.UTC(year, mon - 1, 1));
    const count = new Date(Date.UTC(year, mon, 0)).getUTCDate();
    const dates = Array.from({ length: count }, (_, index) => isoDate(new Date(Date.UTC(year, mon - 1, index + 1))));

    return { dates, leading: (first.getUTCDay() + 6) % 7 };
}

/** The ISO weekday (1 Monday to 7 Sunday) of a `Y-m-d` date. */
export function weekday(date: string): number {
    const day = new Date(`${date}T00:00:00Z`).getUTCDay();

    return day === 0 ? 7 : day;
}

export function shiftMonth(month: string, delta: number): string {
    const [year, mon] = month.split('-').map(Number) as [number, number];
    const next = new Date(Date.UTC(year, mon - 1 + delta, 1));

    return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Units for a number of days typed in a form ("1.5" is 3 units), or null when it is not a multiple of a half day. */
export function daysToUnits(text: string): number | null {
    const value = Number(text.trim().replace(',', '.'));

    if (text.trim() === '' || !Number.isFinite(value) || value < 0) {
        return null;
    }

    const units = value * UNITS_PER_DAY;

    return Number.isInteger(units) ? units : null;
}

function fractionDigits(currency: string): number {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        return 2;
    }
}

/** Major-unit text ("12.5") to integer minor units, or null when it is not an exact amount. */
export function toMinorUnits(text: string, currency: string): number | null {
    const digits = fractionDigits(currency);
    const match = /^(\d{1,12})(?:\.(\d+))?$/.exec(text.trim());

    if (match === null || (match[2]?.length ?? 0) > digits) {
        return null;
    }

    return Number(match[1]) * 10 ** digits + Number((match[2] ?? '').padEnd(digits, '0') || 0);
}

export function fromMinorUnits(amount: number | null, currency: string): string {
    if (amount === null) {
        return '';
    }

    const digits = fractionDigits(currency);

    return (amount / 10 ** digits).toFixed(digits);
}

/** `HH:MM` of an ISO instant in a time zone, as `input[type=time]` wants it. */
export function localTimeInput(iso: string, timezone: string): string {
    return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: timezone }).format(new Date(iso));
}

/** `Y-m-d` of an ISO instant in a time zone. */
export function localDateInput(iso: string, timezone: string): string {
    return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timezone }).format(new Date(iso));
}

/** Display helpers that follow the person's numerals and language. */
export function useHrmFormat() {
    const { t } = useI18n();
    const format = useFormat();
    const page = usePage();

    return {
        /** Today's date (`Y-m-d`) in the workspace time zone. */
        today: (): string => localDateInput(new Date().toISOString(), page.props.app.timezone),
        /** "October 2026" for a `Y-m` month, in the person's language and numerals. */
        month: (month: string): string => {
            const [year, mon] = month.split('-').map(Number) as [number, number];

            return new Intl.DateTimeFormat(localeTag(page.props.app), { month: 'long', year: 'numeric', timeZone: 'UTC' })
                .format(new Date(Date.UTC(year, mon - 1, 1)));
        },
        /** A wall-clock `HH:MM` in the person's numerals. */
        clock: (value: string): string => {
            const [hours, minutes] = value.split(':').map(Number) as [number, number];
            const two = { minimumIntegerDigits: 2, useGrouping: false };

            return `${format.number(hours, two)}:${format.number(minutes, two)}`;
        },
        /** Name of a calendar month (1 January to 12 December). */
        monthName: (month: number): string =>
            new Intl.DateTimeFormat(localeTag(page.props.app), { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, month - 1, 1))),
        /** Weekday name for an ISO weekday (1 Monday to 7 Sunday). */
        weekday: (isoWeekday: number, style: 'short' | 'long' = 'short'): string =>
            new Intl.DateTimeFormat(localeTag(page.props.app), { weekday: style, timeZone: 'UTC' })
                .format(new Date(Date.UTC(2024, 0, isoWeekday))),
        /** Leave units as days: 3 units is "1.5 days". */
        days: (units: number): string => {
            const days = unitsToDays(units);

            return t(days === 1 ? 'hrm.units.dayOne' : 'hrm.units.dayMany', {
                count: format.number(days, { maximumFractionDigits: 1 }),
            });
        },
        /** A file size in KB or MB. */
        size: (bytes: number): string =>
            bytes >= 1_048_576
                ? t('hrm.units.mb', { count: format.number(bytes / 1_048_576, { maximumFractionDigits: 1 }) })
                : t('hrm.units.kb', { count: format.number(Math.max(1, bytes / 1024), { maximumFractionDigits: 0 }) }),
        /** Whole minutes as "7 h 30 min". */
        duration: (minutes: number): string => {
            const hours = Math.floor(minutes / 60);
            const rest = minutes % 60;

            if (hours === 0) {
                return t('hrm.units.minutes', { count: format.number(rest) });
            }

            return rest === 0
                ? t('hrm.units.hours', { count: format.number(hours) })
                : t('hrm.units.hoursMinutes', { hours: format.number(hours), minutes: format.number(rest) });
        },
    };
}
