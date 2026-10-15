/**
 * The softphone keeps the call log in step in the background, so it talks JSON to the same session-authenticated
 * routes the pages use. Laravel's CSRF token comes from the `XSRF-TOKEN` cookie; nothing is stored client-side.
 */

export interface JsonResponse<T> {
    ok: boolean;
    status: number;
    data: T | null;
    /** The server's message (validation or refusal), when it sent one. */
    message: string | null;
}

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

export async function requestJson<T>(method: 'GET' | 'POST', url: string, body?: unknown): Promise<JsonResponse<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = readCookie('XSRF-TOKEN');

    if (token) headers['X-XSRF-TOKEN'] = token;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers,
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        let payload: unknown = null;

        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        const record = typeof payload === 'object' && payload !== null ? (payload as Record<string, unknown>) : {};
        const errors = typeof record.errors === 'object' && record.errors !== null ? Object.values(record.errors as Record<string, string[]>)[0]?.[0] : undefined;

        return {
            ok: response.ok,
            status: response.status,
            data: payload as T,
            message: errors ?? (typeof record.message === 'string' ? record.message : null),
        };
    } catch {
        return { ok: false, status: 0, data: null, message: null };
    }
}
