/**
 * The editor saves and validates in the background, so it talks JSON to the
 * same session-authenticated routes the pages use. Laravel's CSRF token comes
 * from the `XSRF-TOKEN` cookie; nothing is stored client-side.
 */

import type { FormDataConvertible } from '@inertiajs/core';

/** Free-form name/value data for Inertia's `router.post`, which wants form-convertible values. */
export function asPayload(value: Record<string, unknown>): Record<string, FormDataConvertible> {
    return value as Record<string, FormDataConvertible>;
}

export interface JsonResponse<T> {
    ok: boolean;
    status: number;
    data: T | null;
    /** The server's validation message, when it sent one. */
    message: string | null;
    errors: Record<string, string[]>;
}

export function readCookie(name: string, source: string = typeof document === 'undefined' ? '' : document.cookie): string | null {
    for (const part of source.split(';')) {
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

export async function sendJson<T>(method: 'PUT' | 'POST', url: string, body: unknown, signal?: AbortSignal): Promise<JsonResponse<T>> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = readCookie('XSRF-TOKEN');

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers,
            body: JSON.stringify(body),
            signal,
        });
        let payload: unknown = null;

        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        const record = typeof payload === 'object' && payload !== null ? (payload as Record<string, unknown>) : {};

        return {
            ok: response.ok,
            status: response.status,
            data: response.ok ? (payload as T) : null,
            message: typeof record.message === 'string' ? record.message : null,
            errors: typeof record.errors === 'object' && record.errors !== null ? (record.errors as Record<string, string[]>) : {},
        };
    } catch (reason: unknown) {
        if (reason instanceof DOMException && reason.name === 'AbortError') {
            throw reason;
        }

        return { ok: false, status: 0, data: null, message: null, errors: {} };
    }
}
