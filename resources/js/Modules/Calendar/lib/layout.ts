import { addDays, datePart, daysBetween, MINUTES_PER_DAY, minuteOfDay, minutesBetween } from './dates';
import type { CalendarEvent } from '../types';

/** A timed event's piece on one day column: minutes from that day's midnight. */
export interface DayPiece {
    event: CalendarEvent;
    date: string;
    start: number;
    end: number;
    /** The piece continues from the previous day / into the next. */
    continuesBefore: boolean;
    continuesAfter: boolean;
}

export interface PlacedPiece extends DayPiece {
    column: number;
    columns: number;
}

/** Whether an event belongs in the all-day strip: an all-day event, or a timed one of a full day or more. */
export function isBanner(event: CalendarEvent): boolean {
    return event.allDay || minutesBetween(event.start, event.end) >= MINUTES_PER_DAY;
}

/** The timed events of one date, cut at its midnights. */
export function piecesOn(events: CalendarEvent[], date: string): DayPiece[] {
    const pieces: DayPiece[] = [];

    for (const event of events) {
        if (isBanner(event)) {
            continue;
        }

        const startDate = datePart(event.start);
        const endDate = datePart(event.end);

        if (date < startDate || date > endDate) {
            continue;
        }

        const start = date === startDate ? minuteOfDay(event.start) : 0;
        const end = date === endDate ? minuteOfDay(event.end) : MINUTES_PER_DAY;

        // An event ending exactly at midnight has nothing on its last date.
        if (date === endDate && date !== startDate && end === 0) {
            continue;
        }

        pieces.push({ event, date, start, end: Math.max(end, start), continuesBefore: date !== startDate, continuesAfter: date !== endDate && !(addDays(date, 1) === endDate && minuteOfDay(event.end) === 0) });
    }

    return pieces;
}

/**
 * Side-by-side columns for overlapping pieces of one day, the way a paper diary would draw them: pieces that overlap
 * (directly or through a chain) form a cluster, each takes the first free column, and every piece in the cluster
 * shares the cluster's column count. A piece shorter than `minimum` minutes is treated as that tall, since that is
 * how tall it is drawn.
 */
export function layoutDay(pieces: DayPiece[], minimum = 20): PlacedPiece[] {
    const sorted = [...pieces].sort((a, b) => a.start - b.start || b.end - a.end);
    const placed: PlacedPiece[] = [];
    let cluster: PlacedPiece[] = [];
    let columnsEnd: number[] = [];
    let clusterEnd = -1;

    const close = (): void => {
        const columns = columnsEnd.length;

        for (const piece of cluster) {
            piece.columns = columns;
        }

        cluster = [];
        columnsEnd = [];
    };

    for (const piece of sorted) {
        const end = Math.max(piece.end, piece.start + minimum);

        if (piece.start >= clusterEnd) {
            close();
        }

        let column = columnsEnd.findIndex((columnEnd) => columnEnd <= piece.start);

        if (column === -1) {
            column = columnsEnd.length;
            columnsEnd.push(end);
        } else {
            columnsEnd[column] = end;
        }

        const item: PlacedPiece = { ...piece, column, columns: 1 };
        cluster.push(item);
        placed.push(item);
        clusterEnd = Math.max(clusterEnd, end);
    }

    close();

    return placed;
}

/** A banner (or, in the month grid, any event) laid across a run of day columns. */
export interface Segment {
    event: CalendarEvent;
    /** First and last column it covers (inclusive), within the row. */
    from: number;
    to: number;
    lane: number;
    continuesBefore: boolean;
    continuesAfter: boolean;
}

/** The last date an event covers (an all-day end is exclusive; a timed end at midnight does not reach that day). */
export function lastDate(event: CalendarEvent): string {
    if (event.allDay) {
        return addDays(event.end, -1);
    }

    const end = datePart(event.end);

    return end !== datePart(event.start) && minuteOfDay(event.end) === 0 ? addDays(end, -1) : end;
}

/**
 * Lay events across a row of consecutive dates in lanes, longest first, so that no two overlap: the all-day strip of a
 * week, or one week of the month grid.
 */
export function layoutRow(events: CalendarEvent[], dates: string[]): Segment[] {
    if (dates.length === 0) {
        return [];
    }

    const first = dates[0]!;
    const last = dates[dates.length - 1]!;
    const items = events
        .map((event) => {
            const start = datePart(event.start);
            const end = lastDate(event);

            if (end < first || start > last) {
                return null;
            }

            return {
                event,
                from: Math.max(0, daysBetween(first, start)),
                to: Math.min(dates.length - 1, daysBetween(first, end)),
                continuesBefore: start < first,
                continuesAfter: end > last,
            };
        })
        .filter((item): item is Omit<Segment, 'lane'> => item !== null)
        .sort((a, b) => a.from - b.from || (b.to - b.from) - (a.to - a.from) || Number(b.event.allDay) - Number(a.event.allDay) || a.event.start.localeCompare(b.event.start));

    const lanes: number[] = [];

    return items.map((item) => {
        let lane = lanes.findIndex((laneEnd) => laneEnd < item.from);

        if (lane === -1) {
            lane = lanes.length;
            lanes.push(item.to);
        } else {
            lanes[lane] = item.to;
        }

        return { ...item, lane };
    });
}

/** How many segments start in or cross each column, beyond the lanes shown: the "+3 more" counts. */
export function hiddenPerColumn(segments: Segment[], columns: number, visibleLanes: number): number[] {
    const hidden = Array.from({ length: columns }, () => 0);

    for (const segment of segments) {
        if (segment.lane < visibleLanes) {
            continue;
        }

        for (let column = segment.from; column <= segment.to; column++) {
            hidden[column]!++;
        }
    }

    return hidden;
}

/** The events that touch a date, all-day first, then by start. */
export function eventsOn(events: CalendarEvent[], date: string): CalendarEvent[] {
    return events
        .filter((event) => datePart(event.start) <= date && lastDate(event) >= date)
        .sort((a, b) => Number(b.allDay) - Number(a.allDay) || a.start.localeCompare(b.start));
}
