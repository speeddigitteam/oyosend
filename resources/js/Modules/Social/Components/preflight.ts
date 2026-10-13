import type { PostPreview, PostType } from './types';

export interface PreflightInput {
    post_type: PostType;
    body: string;
    link_url: string;
    hashtag_set_id: string;
    targets: { account_id: string; body_override: string | null }[];
    media_ids: string[];
}

function cookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

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

/**
 * Asks the server whether the post as composed would be accepted (nothing is saved). Returns null when
 * the answer is unavailable (offline, or the member may not use the check); the form then stays usable.
 */
export async function requestPreflight(url: string, input: PreflightInput, signal?: AbortSignal): Promise<PostPreview[] | null> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = cookie('XSRF-TOKEN');

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    try {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(input), signal });

        if (!response.ok) {
            return null;
        }

        const body = (await response.json()) as { previews?: PostPreview[] };

        return Array.isArray(body.previews) ? body.previews : null;
    } catch {
        return null;
    }
}

/** The post types an account list can all publish; `null` reason means selectable. */
export function typeAvailability(
    accounts: { name: string; postTypes?: PostType[] }[],
): Record<PostType, string[]> {
    const result: Record<PostType, string[]> = { post: [], photos: [], video: [], reel: [] };

    for (const account of accounts) {
        for (const type of Object.keys(result) as PostType[]) {
            if (!(account.postTypes ?? ['post']).includes(type)) {
                result[type].push(account.name);
            }
        }
    }

    return result;
}

/** Whole minutes and seconds of a length in milliseconds (the caller formats the digits). */
export function clockParts(ms: number): { minutes: number; seconds: number } {
    const total = Math.max(0, Math.round(ms / 1000));

    return { minutes: Math.floor(total / 60), seconds: total % 60 };
}