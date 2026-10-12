/**
 * Client-side helpers for SMS text. The segment counter mirrors the server's `SegmentCounter` (GSM 03.38
 * and UCS-2) so the editor can show a live count; the server counts again when saving and sending, and its
 * answer is the one that is used.
 */

export const GSM_SINGLE = 160;
export const GSM_MULTI = 153;
export const UCS_SINGLE = 70;
export const UCS_MULTI = 67;

const BASIC =
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const EXTENDED = '\f^{}\\[~]|€';

const basicSet = new Set(Array.from(BASIC));
const extendedSet = new Set(Array.from(EXTENDED));

export type SmsEncoding = 'gsm7' | 'ucs2';

export interface SegmentCount {
    encoding: SmsEncoding;
    characters: number;
    units: number;
    segments: number;
    perSegment: number;
    remaining: number;
    unicodeCharacters: string[];
}

export function countSegments(text: string): SegmentCount {
    const characters = Array.from(text);

    if (characters.length === 0) {
        return { encoding: 'gsm7', characters: 0, units: 0, segments: 0, perSegment: GSM_SINGLE, remaining: GSM_SINGLE, unicodeCharacters: [] };
    }

    const unicode = new Set<string>();

    for (const character of characters) {
        if (!basicSet.has(character) && !extendedSet.has(character)) {
            unicode.add(character);
        }
    }

    const isGsm = unicode.size === 0;
    const single = isGsm ? GSM_SINGLE : UCS_SINGLE;
    const multi = isGsm ? GSM_MULTI : UCS_MULTI;
    const widths = characters.map((character) => (isGsm ? (extendedSet.has(character) ? 2 : 1) : (character.codePointAt(0) ?? 0) > 0xffff ? 2 : 1));
    const units = widths.reduce((total, width) => total + width, 0);
    const encoding: SmsEncoding = isGsm ? 'gsm7' : 'ucs2';
    const unicodeCharacters = Array.from(unicode).slice(0, 5);

    if (units <= single) {
        return { encoding, characters: characters.length, units, segments: 1, perSegment: single, remaining: single - units, unicodeCharacters };
    }

    let segments = 1;
    let used = 0;

    for (const width of widths) {
        if (used + width > multi) {
            segments += 1;
            used = 0;
        }

        used += width;
    }

    return { encoding, characters: characters.length, units, segments, perSegment: multi, remaining: multi - used, unicodeCharacters };
}

/** `{{name}}` or `{{name|fallback}}`: the names of the variables a text uses, once each. */
export function usedVariables(text: string): string[] {
    const found = new Set<string>();

    for (const match of text.matchAll(/\{\{\s*([a-z_]{1,32})\s*(?:\|[^{}|]{0,30})?\}\}/gu)) {
        found.add(match[1]!);
    }

    return Array.from(found);
}

/** Variables in the text that are not in `allowed`. */
export function unknownVariables(text: string, allowed: readonly string[]): string[] {
    return usedVariables(text).filter((name) => !allowed.includes(name));
}

/** A `datetime-local` value for "now plus `minutes`" in the browser's clock, minute precision. */
export function localDateTime(date: Date): string {
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function isLocalDateTime(value: string): boolean {
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) && !Number.isNaN(new Date(value).getTime());
}

/** Whole percent of `part` in `whole`, or null when there is nothing to divide by. */
export function percent(part: number, whole: number): number | null {
    return whole > 0 ? Math.round((part / whole) * 100) : null;
}
