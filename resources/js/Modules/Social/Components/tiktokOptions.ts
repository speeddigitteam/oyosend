import type { CreatorInfo, MediaRow, PostType, TikTokOptions, TikTokPrivacy } from './types';

/** TikTok's privacy levels in the order the composer offers them. */
export const TIKTOK_PRIVACY: TikTokPrivacy[] = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

/**
 * A fresh choice: nothing selected. TikTok requires the creator to pick the privacy level themselves and the
 * interaction switches start off, so nothing is chosen on their behalf.
 */
export function emptyTikTokOptions(): TikTokOptions {
    return {
        privacy_level: null,
        allow_comment: false,
        allow_duet: false,
        allow_stitch: false,
        disclose_commercial: false,
        brand_organic: false,
        brand_content: false,
    };
}

/** Stored options (or none) as a complete, typed choice. */
export function tikTokOptionsFrom(value: Partial<TikTokOptions> | null | undefined): TikTokOptions {
    const base = emptyTikTokOptions();

    if (!value) {
        return base;
    }

    const privacy = TIKTOK_PRIVACY.find((level) => level === value.privacy_level) ?? null;
    const disclose = value.disclose_commercial === true;

    return {
        privacy_level: privacy,
        allow_comment: value.allow_comment === true,
        allow_duet: value.allow_duet === true,
        allow_stitch: value.allow_stitch === true,
        disclose_commercial: disclose,
        brand_organic: disclose && value.brand_organic === true,
        brand_content: disclose && value.brand_content === true,
    };
}

/** Duet and Stitch do not exist for photo posts. */
export function isPhotoPost(postType: PostType, media: Pick<MediaRow, 'kind'>[]): boolean {
    return postType === 'photos' || (media.length > 0 && media.every((item) => item.kind === 'image'));
}

/**
 * The choice adjusted to what the account allows now: a privacy level no longer offered is dropped (it must be
 * chosen again) and a switch the creator turned off in TikTok cannot stay on.
 */
export function fitToCreator(options: TikTokOptions, info: CreatorInfo): TikTokOptions {
    return {
        ...options,
        privacy_level: options.privacy_level !== null && info.privacyLevels.includes(options.privacy_level) ? options.privacy_level : null,
        allow_comment: options.allow_comment && !info.commentDisabled,
        allow_duet: options.allow_duet && !info.duetDisabled,
        allow_stitch: options.allow_stitch && !info.stitchDisabled,
    };
}

export type TikTokProblem = 'privacy' | 'brand' | 'brandedPrivate' | 'tooLong';

/** What still blocks posting, in the order the card shows it. Mirrors the server's rules. */
export function tikTokProblems(options: TikTokOptions, info: CreatorInfo | null, media: Pick<MediaRow, 'kind' | 'durationMs'>[]): TikTokProblem[] {
    const problems: TikTokProblem[] = [];

    if (options.privacy_level === null) {
        problems.push('privacy');
    }

    if (options.disclose_commercial && !options.brand_organic && !options.brand_content) {
        problems.push('brand');
    }

    if (options.brand_content && options.privacy_level === 'SELF_ONLY') {
        problems.push('brandedPrivate');
    }

    const max = info?.maxVideoSeconds ?? null;

    if (max !== null && media.some((item) => item.kind === 'video' && (item.durationMs ?? 0) > max * 1000)) {
        problems.push('tooLong');
    }

    return problems;
}

export type CreatorInfoResult = { state: 'ok'; options: CreatorInfo } | { state: 'unavailable'; message: string | null };

/** Asks the server for the account's TikTok creator info. Never throws; a failure is `unavailable`. */
export async function fetchCreatorInfo(url: string, signal?: AbortSignal): Promise<CreatorInfoResult> {
    try {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal,
        });

        if (!response.ok) {
            return { state: 'unavailable', message: null };
        }

        const body = (await response.json()) as { state?: string; message?: unknown; options?: Partial<CreatorInfo> };

        if (body.state === 'ok' && body.options) {
            const levels = Array.isArray(body.options.privacyLevels) ? body.options.privacyLevels : [];

            return {
                state: 'ok',
                options: {
                    privacyLevels: TIKTOK_PRIVACY.filter((level) => levels.includes(level)),
                    commentDisabled: body.options.commentDisabled === true,
                    duetDisabled: body.options.duetDisabled === true,
                    stitchDisabled: body.options.stitchDisabled === true,
                    maxVideoSeconds: typeof body.options.maxVideoSeconds === 'number' && body.options.maxVideoSeconds > 0 ? body.options.maxVideoSeconds : null,
                    nickname: typeof body.options.nickname === 'string' && body.options.nickname !== '' ? body.options.nickname : null,
                },
            };
        }

        return { state: 'unavailable', message: typeof body.message === 'string' ? body.message : null };
    } catch {
        return { state: 'unavailable', message: null };
    }
}
