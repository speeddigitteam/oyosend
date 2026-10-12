import type { BadgeTone } from '@/Core/Components/Badge.vue';
import type { TicketPriority, TicketStatus, TicketSummary } from './types';

export const ACTIVE_STATUSES: TicketStatus[] = ['new', 'open', 'waiting_customer', 'on_hold'];

export function isFinished(status: TicketStatus): boolean {
    return status === 'resolved' || status === 'closed';
}

const STATUS_TONES: Record<TicketStatus, BadgeTone> = {
    new: 'info',
    open: 'brand',
    waiting_customer: 'warn',
    on_hold: 'neutral',
    resolved: 'ok',
    closed: 'neutral',
    merged: 'neutral',
};

const PRIORITY_TONES: Record<TicketPriority, BadgeTone> = {
    low: 'neutral',
    normal: 'neutral',
    high: 'warn',
    urgent: 'danger',
};

export function statusTone(status: TicketStatus): BadgeTone {
    return STATUS_TONES[status] ?? 'neutral';
}

export function priorityTone(priority: TicketPriority): BadgeTone {
    return PRIORITY_TONES[priority] ?? 'neutral';
}

/** Basis points (10000 = 100%) to a percentage figure; null stays null. */
export function basisPointsToPercent(basisPoints: number | null | undefined): number | null {
    return basisPoints === null || basisPoints === undefined ? null : basisPoints / 100;
}

/** Hundredths (450 = 4.50) to the rating; null stays null. */
export function hundredthsToRating(hundredths: number | null | undefined): number | null {
    return hundredths === null || hundredths === undefined ? null : hundredths / 100;
}

export interface DurationParts {
    days: number;
    hours: number;
    minutes: number;
}

/** Whole minutes to days, hours and minutes. Negative values are treated as their size. */
export function durationParts(totalMinutes: number): DurationParts {
    const total = Math.max(0, Math.round(Math.abs(totalMinutes)));

    return { days: Math.floor(total / 1440), hours: Math.floor((total % 1440) / 60), minutes: total % 60 };
}

export type ClockState = 'met' | 'missed' | 'breached' | 'paused' | 'overdue' | 'due' | 'none';

export interface ClockInput {
    /** The target has been reached (first response given, or ticket resolved). */
    done: boolean;
    dueAt: string | null;
    breached: boolean;
    paused: boolean;
    now: number;
}

/** What to say about one SLA clock. `breached` wins over `paused`; a finished clock is met or missed. */
export function clockState(input: ClockInput): ClockState {
    if (input.done) {
        return input.breached ? 'missed' : 'met';
    }

    if (input.breached) {
        return 'breached';
    }

    if (input.paused) {
        return 'paused';
    }

    if (input.dueAt === null) {
        return 'none';
    }

    return Date.parse(input.dueAt) < input.now ? 'overdue' : 'due';
}

/** Whole minutes from `now` until `dueAt` (negative when past); null without a due time. */
export function minutesUntil(dueAt: string | null, now: number): number | null {
    if (dueAt === null) {
        return null;
    }

    const due = Date.parse(dueAt);

    return Number.isNaN(due) ? null : Math.round((due - now) / 60000);
}

export interface TicketClocks {
    firstResponse: { state: ClockState; dueAt: string | null };
    resolution: { state: ClockState; dueAt: string | null };
}

export function ticketClocks(ticket: TicketSummary, now: number): TicketClocks {
    const finished = isFinished(ticket.status) || ticket.status === 'merged';

    return {
        firstResponse: {
            state: clockState({
                done: ticket.firstRespondedAt !== null,
                dueAt: ticket.firstResponseDueAt,
                breached: ticket.firstResponseBreached,
                paused: ticket.slaPaused,
                now,
            }),
            dueAt: ticket.firstResponseDueAt,
        },
        resolution: {
            state: clockState({
                done: finished,
                dueAt: ticket.resolutionDueAt,
                breached: ticket.resolutionBreached,
                paused: ticket.slaPaused,
                now,
            }),
            dueAt: ticket.resolutionDueAt,
        },
    };
}

export interface PrimaryClock {
    target: 'first_response' | 'resolution';
    state: ClockState;
    dueAt: string | null;
}

/** The clock a list row should show: first response until it is given, then resolution. */
export function primaryClock(ticket: TicketSummary, now: number): PrimaryClock {
    const clocks = ticketClocks(ticket, now);

    if (clocks.firstResponse.state !== 'met' && clocks.firstResponse.state !== 'none') {
        return { target: 'first_response', ...clocks.firstResponse };
    }

    return { target: 'resolution', ...clocks.resolution };
}

/** A file size as a numeric value plus a unit key. */
export function fileSize(bytes: number): { value: number; unit: 'b' | 'kb' | 'mb' } {
    if (bytes >= 1024 * 1024) {
        return { value: Math.round((bytes / (1024 * 1024)) * 10) / 10, unit: 'mb' };
    }

    if (bytes >= 1024) {
        return { value: Math.round(bytes / 1024), unit: 'kb' };
    }

    return { value: bytes, unit: 'b' };
}

/** Splits a free-text tag field into trimmed, non-empty, case-insensitively unique tags. */
export function parseTags(text: string): string[] {
    const seen = new Set<string>();
    const tags: string[] = [];

    for (const part of text.split(',')) {
        const tag = part.trim();
        const key = tag.toLowerCase();

        if (tag !== '' && !seen.has(key)) {
            seen.add(key);
            tags.push(tag);
        }
    }

    return tags;
}

/** ISO weekdays, Monday first. */
export const WEEKDAYS = ['1', '2', '3', '4', '5', '6', '7'] as const;

/** Parses "HH:MM" (or "24:00") into minutes since midnight; null when malformed. */
export function clockMinutes(value: string): number | null {
    const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim());

    if (!match) {
        return null;
    }

    const minutes = Number(match[1]) * 60 + Number(match[2]);

    return minutes <= 1440 && Number(match[2]) < 60 ? minutes : null;
}
