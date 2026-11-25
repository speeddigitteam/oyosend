/**
 * Voice input (ADR 0038): send one recorded clip to the workspace's transcription route and get its text
 * back. The clip goes to the server only; nothing is kept in the browser.
 */

import { route } from '@/Core/lib/route';

export type TranscribeResult = { ok: true; text: string } | { ok: false; status: number; message: string | null };

function readCookie(name: string): string | null {
    if (typeof document === 'undefined') return null;

    for (const part of document.cookie.split(';')) {
        const [key, ...rest] = part.trim().split('=');

        if (key === name) {
            try {
                return decodeURIComponent(rest.join('='));
            } catch {
                return null;
            }
        }
    }

    return null;
}

/** The file extension a server recognises for a recorder's MIME type (`audio/webm;codecs=opus` → `webm`). */
export function extensionFor(mimeType: string): string {
    const base = mimeType.split(';')[0].trim().toLowerCase();

    if (base.endsWith('/ogg')) return 'ogg';
    if (base.endsWith('/mp4') || base.endsWith('/aac')) return 'mp4';
    if (base.endsWith('/mpeg')) return 'mp3';
    if (base.endsWith('/wav')) return 'wav';

    return 'webm';
}

/** The first container this browser's recorder can produce, in order of how well servers take it. */
export function preferredMimeType(isSupported: (type: string) => boolean): string | undefined {
    return ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'].find((type) => {
        try {
            return isSupported(type);
        } catch {
            return false;
        }
    });
}

/** Lower case, punctuation (including the Bengali full stop) and symbols turned into spaces, spaces collapsed. */
export function normaliseSpoken(text: string): string {
    return text
        .toLocaleLowerCase()
        .replace(/[\p{P}\p{S}]+/gu, ' ')
        .replace(/\s+/gu, ' ')
        .trim();
}

/**
 * The entries a spoken sentence names. People say "open contacts" or "কন্টাক্ট খোলো", not just the label,
 * so an entry matches when its whole label appears as words in what was said; when several do, only the
 * longest labels stay ("AI settings" over "Settings"). Failing that, a short utterance matches the labels
 * that contain it. Typed search does not use this.
 */
export function matchSpoken<T extends { label: string }>(entries: T[], spoken: string): T[] {
    const said = normaliseSpoken(spoken);

    if (said === '') return [];

    const padded = ` ${said} `;
    const named = entries
        .map((entry) => ({ entry, label: normaliseSpoken(entry.label) }))
        .filter(({ label }) => label !== '' && padded.includes(` ${label} `));

    if (named.length > 0) {
        const longest = Math.max(...named.map(({ label }) => label.length));

        return named.filter(({ label }) => label.length === longest).map(({ entry }) => entry);
    }

    return entries.filter((entry) => normaliseSpoken(entry.label).includes(said));
}

export async function transcribeClip(clip: Blob, language: string, signal?: AbortSignal): Promise<TranscribeResult> {
    const body = new FormData();
    body.append('audio', clip, `speech.${extensionFor(clip.type)}`);
    body.append('language', language);

    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = readCookie('XSRF-TOKEN');

    if (token) headers['X-XSRF-TOKEN'] = token;

    let response: Response;

    try {
        response = await fetch(route('ai.voice.transcribe'), { method: 'POST', credentials: 'same-origin', headers, body, signal });
    } catch (reason: unknown) {
        if (reason instanceof DOMException && reason.name === 'AbortError') throw reason;

        return { ok: false, status: 0, message: null };
    }

    let payload: Record<string, unknown> = {};

    try {
        const parsed: unknown = await response.json();
        payload = typeof parsed === 'object' && parsed !== null ? (parsed as Record<string, unknown>) : {};
    } catch {
        payload = {};
    }

    if (response.ok && typeof payload.text === 'string') {
        return { ok: true, text: payload.text };
    }

    // A refusal says why in the member's language (`{state, message}`); a validation error names the field.
    const errors = typeof payload.errors === 'object' && payload.errors !== null ? (payload.errors as Record<string, unknown>) : {};
    const fieldError = Array.isArray(errors.audio) && typeof errors.audio[0] === 'string' ? errors.audio[0] : null;

    return { ok: false, status: response.status, message: fieldError ?? (typeof payload.message === 'string' ? payload.message : null) };
}
