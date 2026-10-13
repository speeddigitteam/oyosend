/** What the AI assist endpoints answer. `invalid`, `forbidden` and `error` are added here for answers that are not AI states. */
export type AiState =
    | 'ok' | 'not_configured' | 'disabled' | 'limit_reached' | 'refused' | 'failed'
    | 'invalid' | 'forbidden' | 'error';

export type AiOperation = 'caption' | 'hashtags' | 'rewrite' | 'shorten' | 'translate';

export interface AiSuggestion {
    operation: AiOperation;
    state: AiState;
    message: string;
    retryable: boolean;
    aiGenerated: boolean;
    text: string;
    hashtags: string[];
    maxLength: number;
    replayed: boolean;
    settingsUrl: string | null;
    /** First validation message for `invalid`. */
    detail?: string;
}

export interface AiRequestInput {
    title: string;
    notes: string;
    body: string;
    link_url: string;
    tone: string;
    language: string;
    count: number;
    attempt: number;
    account_ids: string[];
}

export const MAX_HASHTAGS = 30;

export function cookie(name: string): string | null {
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

function failure(operation: AiOperation, state: AiState, detail?: string): AiSuggestion {
    return {
        operation, state, message: '', retryable: state === 'error' || state === 'refused', aiGenerated: false,
        text: '', hashtags: [], maxLength: 0, replayed: false, settingsUrl: null, detail,
    };
}

/** Asks the server for one suggestion. It never throws: every outcome is a state the panel can explain. */
export async function requestSuggestion(url: string, operation: AiOperation, input: AiRequestInput, signal?: AbortSignal): Promise<AiSuggestion> {
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

        if (response.status === 422) {
            const body = (await response.json().catch(() => ({}))) as { errors?: Record<string, string[]> };
            const first = Object.values(body.errors ?? {})[0]?.[0];

            return failure(operation, 'invalid', first);
        }

        if (response.status === 403 || response.status === 404) {
            return failure(operation, 'forbidden');
        }

        if (response.status === 429) {
            return failure(operation, 'refused');
        }

        if (!response.ok) {
            return failure(operation, 'error');
        }

        const body = (await response.json()) as Partial<AiSuggestion>;

        return {
            ...failure(operation, 'error'),
            ...body,
            operation,
            hashtags: Array.isArray(body.hashtags) ? body.hashtags.filter((tag): tag is string => typeof tag === 'string') : [],
            text: typeof body.text === 'string' ? body.text : '',
        };
    } catch {
        return failure(operation, 'error');
    }
}

/** Case-insensitive union of tag lists, `#`-prefixed, at most MAX_HASHTAGS. */
export function mergeTags(current: string[], added: string[]): string[] {
    const seen = new Set<string>();
    const result: string[] = [];

    for (const raw of [...current, ...added]) {
        const tag = `#${raw.trim().replace(/^#+/, '')}`;
        const key = tag.toLowerCase();

        if (tag === '#' || seen.has(key)) {
            continue;
        }

        seen.add(key);
        result.push(tag);
    }

    return result.slice(0, MAX_HASHTAGS);
}

/** Sentiment bar share, in whole percent, of a count within a total (0 when there is no total). */
export function share(count: number, total: number): number {
    return total > 0 ? Math.round((count / total) * 100) : 0;
}

/** Basis points (-10000..10000) to a -100..100 score. */
export function scoreOf(basisPoints: number): number {
    return Math.round(basisPoints / 100);
}
