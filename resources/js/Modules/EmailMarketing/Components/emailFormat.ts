import type { Block, BlockType, SequenceStepRow } from './types';

export const BLOCK_TYPES: BlockType[] = ['heading', 'text', 'image', 'button', 'divider', 'spacer'];

/** A new, valid block of a type with its defaults. */
export function newBlock(type: BlockType): Block {
    switch (type) {
        case 'heading':
            return { type, text: '', level: 2, align: 'left' };
        case 'text':
            return { type, text: '', align: 'left' };
        case 'image':
            return { type, url: '', alt: '', link_url: null, width: 600 };
        case 'button':
            return { type, label: '', url: '', align: 'left', style: 'primary' };
        case 'divider':
            return { type };
        case 'spacer':
            return { type, height: 24 };
    }
}

/** Move the item at `index` by `offset` places; out-of-range moves return the list unchanged. */
export function moveItem<T>(items: T[], index: number, offset: number): T[] {
    const target = index + offset;

    if (index < 0 || index >= items.length || target < 0 || target >= items.length) {
        return items;
    }

    const copy = items.slice();
    const [item] = copy.splice(index, 1);
    copy.splice(target, 0, item as T);

    return copy;
}

/** Basis points (10000 = 100%) as a percentage number, or null when there is nothing to report. */
export function percent(basisPoints: number | null): number | null {
    return basisPoints === null ? null : basisPoints / 100;
}

export type DelayUnit = 'minutes' | 'hours' | 'days';

/** Split a delay in minutes into the largest whole unit that holds it exactly. */
export function delayParts(minutes: number): { value: number; unit: DelayUnit } {
    if (minutes > 0 && minutes % 1440 === 0) {
        return { value: minutes / 1440, unit: 'days' };
    }

    if (minutes > 0 && minutes % 60 === 0) {
        return { value: minutes / 60, unit: 'hours' };
    }

    return { value: minutes, unit: 'minutes' };
}

export function delayMinutes(value: number, unit: DelayUnit): number {
    const factor = unit === 'days' ? 1440 : unit === 'hours' ? 60 : 1;

    return Math.max(0, Math.round(value)) * factor;
}

/** An empty step for the sequence form. */
export function newStep(position: number): SequenceStepRow {
    return { id: null, position, delayMinutes: position === 1 ? 0 : 1440, subject: '', preheader: null, blocks: [newBlock('text')] };
}

/** Whether a link is one the server will accept in an email: https or http with a host, or the unsubscribe variable. */
export function isSafeLink(value: string, allowMailto = false): boolean {
    const url = value.trim();

    if (/^\{\{\s*unsubscribe_url\s*\}\}$/.test(url)) {
        return true;
    }

    if (url === '' || url.length > 1000 || /[\s<>"'\\`]/.test(url)) {
        return false;
    }

    if (allowMailto && /^mailto:[^\s@]+@[^\s@]+$/i.test(url)) {
        return true;
    }

    try {
        const parsed = new URL(url);

        return (parsed.protocol === 'https:' || parsed.protocol === 'http:') && parsed.username === '' && parsed.password === '';
    } catch {
        return false;
    }
}

/** `YYYY-MM-DDTHH:mm` (a local date and time, as `<input type="datetime-local">` wants it) or an empty string. */
export function isLocalDateTime(value: string): boolean {
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value);
}

export function variableToken(name: string): string {
    return `{{${name}}}`;
}
