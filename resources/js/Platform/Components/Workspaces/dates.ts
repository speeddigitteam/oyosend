/*
 * The Super Admin panel shows dates on the platform's clock, but a day an
 * operator picks for a workspace (the end of a trial, of a granted plan) is
 * a day on the workspace's own clock: the server takes it to the end of that
 * day there.
 */

/** `YYYY-MM-DD` of an instant on the clock of a time zone: what a date field holds. */
export function dateInZone(instant: Date, timezone: string): string {
    let parts: Intl.DateTimeFormatPart[];

    try {
        parts = new Intl.DateTimeFormat('en-US', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(instant);
    } catch {
        // A zone this browser does not know: fall back to UTC rather than fail.
        return instant.toISOString().slice(0, 10);
    }

    const pick = (type: Intl.DateTimeFormatPartTypes): string => parts.find((part) => part.type === type)?.value ?? '';

    return `${pick('year')}-${pick('month')}-${pick('day')}`;
}

/** The first day the server accepts for a date that must lie in the future. */
export function tomorrowInZone(timezone: string, now: Date = new Date()): string {
    return dateInZone(new Date(now.getTime() + 86_400_000), timezone);
}
