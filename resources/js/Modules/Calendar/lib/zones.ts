import { wallNow } from './dates';

/**
 * The UTC instant of a wall-clock time in a zone. Uses the zone's own offset at that moment (asked twice, so a time
 * right after a daylight-saving change still lands on the right side of it). A time the clocks skip moves forward.
 */
export function wallToInstant(wall: string, timezone: string): Date {
    const naive = Date.UTC(
        Number(wall.slice(0, 4)),
        Number(wall.slice(5, 7)) - 1,
        Number(wall.slice(8, 10)),
        Number(wall.slice(11, 13) || 0),
        Number(wall.slice(14, 16) || 0),
    );
    const offsetAt = (instant: number): number => {
        const shown = wallNow(timezone, new Date(instant));
        const asUtc = Date.UTC(Number(shown.slice(0, 4)), Number(shown.slice(5, 7)) - 1, Number(shown.slice(8, 10)), Number(shown.slice(11, 13)), Number(shown.slice(14, 16)));

        return asUtc - Math.floor(instant / 60_000) * 60_000;
    };
    const first = naive - offsetAt(naive);

    return new Date(naive - offsetAt(first));
}

/** The same moment as a wall-clock time in another zone. */
export function convertWall(wall: string, from: string, to: string): string {
    if (from === to) {
        return wall;
    }

    return wallNow(to, wallToInstant(wall, from));
}
