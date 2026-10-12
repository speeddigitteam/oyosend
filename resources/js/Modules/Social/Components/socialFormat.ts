import type { PostStatus } from './types';

export const FIGURES = ['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'] as const;
export type Figure = (typeof FIGURES)[number];

/** Post statuses in the order of the pipeline. */
export const POST_STATUSES: PostStatus[] = [
    'draft', 'pending_approval', 'approved', 'scheduled', 'publishing', 'published', 'partially_failed', 'failed',
];

/** `YYYY-MM-DD` of an instant as seen in `timezone`. Falls back to UTC for an unknown zone. */
export function dayKey(iso: string, timezone: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    try {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' })
            .formatToParts(date);
        const get = (type: string): string => parts.find((part) => part.type === type)?.value ?? '';

        return `${get('year')}-${get('month')}-${get('day')}`;
    } catch {
        return date.toISOString().slice(0, 10);
    }
}

export interface GridDay {
    date: string;
    day: number;
    inMonth: boolean;
}

/** Whole weeks (Sunday first) covering a `YYYY-MM` month. */
export function monthGrid(month: string): GridDay[][] {
    const match = /^(\d{4})-(\d{2})$/.exec(month);

    if (!match) {
        return [];
    }

    const year = Number(match[1]);
    const index = Number(match[2]) - 1;
    const first = new Date(Date.UTC(year, index, 1));
    const last = new Date(Date.UTC(year, index + 1, 0));
    const cursor = new Date(Date.UTC(year, index, 1 - first.getUTCDay()));
    const weeks: GridDay[][] = [];

    while (cursor <= last) {
        const week: GridDay[] = [];

        for (let i = 0; i < 7; i++) {
            week.push({
                date: cursor.toISOString().slice(0, 10),
                day: cursor.getUTCDate(),
                inMonth: cursor.getUTCMonth() === index,
            });
            cursor.setUTCDate(cursor.getUTCDate() + 1);
        }

        weeks.push(week);
    }

    return weeks;
}

/** `YYYY-MM` moved by `delta` months. */
export function shiftMonth(month: string, delta: number): string {
    const match = /^(\d{4})-(\d{2})$/.exec(month);

    if (!match) {
        return month;
    }

    const moved = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1 + delta, 1));

    return `${moved.getUTCFullYear()}-${String(moved.getUTCMonth() + 1).padStart(2, '0')}`;
}

export function monthParts(month: string): { year: number; month: number } {
    const match = /^(\d{4})-(\d{2})$/.exec(month);

    return { year: match ? Number(match[1]) : 0, month: match ? Number(match[2]) : 1 };
}

/** Only absolute http(s) links are ever rendered as links or images. */
export function safeUrl(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'https:' || url.protocol === 'http:' ? url.toString() : null;
    } catch {
        return null;
    }
}

/** Bytes as whole KB or MB (one decimal); the caller formats the digits. */
export function sizeParts(bytes: number): { unit: 'kb' | 'mb'; value: number } {
    if (bytes >= 1024 * 1024) {
        return { unit: 'mb', value: Math.round((bytes / (1024 * 1024)) * 10) / 10 };
    }

    return { unit: 'kb', value: Math.max(1, Math.round(bytes / 1024)) };
}

/** Splits free text on whitespace, commas and semicolons, dropping empty parts. */
export function splitTags(text: string): string[] {
    return text.split(/[\s,;]+/u).map((tag) => tag.trim()).filter((tag) => tag !== '');
}

/** One leading `#`, however many were typed. */
export function displayTag(tag: string): string {
    return `#${tag.replace(/^#+/u, '')}`;
}

export type LengthState = 'ok' | 'near' | 'over' | 'none';

/** How a character count stands against a platform limit (`0` means no limit is known). */
export function lengthState(length: number, max: number): LengthState {
    if (max <= 0) {
        return 'none';
    }

    if (length > max) {
        return 'over';
    }

    return length >= max * 0.9 ? 'near' : 'ok';
}

/** The only shape the server accepts for a planned time: `Y-m-d\TH:i`. */
export function isLocalDateTime(value: string): boolean {
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value);
}

/** A figure a platform did not return is `null`; it is never treated as zero. */
export function isReported(value: number | null | undefined): value is number {
    return typeof value === 'number';
}

/** Kilobytes limit from the server turned into the whole megabytes shown to people. */
export function kbToMb(kb: number): number {
    return Math.round((kb / 1024) * 10) / 10;
}
