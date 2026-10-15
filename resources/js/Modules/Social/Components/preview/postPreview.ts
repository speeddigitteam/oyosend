import { requirementProblems, type Requirement } from '../requirements';
import { safeUrl } from '../socialFormat';
import type { AccountOption, Capabilities, PostPreview } from '../types';

/**
 * Pure helpers of the composer's live preview. The preview is an approximation drawn with our own tokens and
 * icons; each platform decides the final look. The server check (`posts/preflight`) stays the judge of what can
 * be published.
 */

/** The platforms that have a layout of their own, in the order their tabs appear. */
export const PREVIEW_PLATFORMS = ['facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok', 'pinterest'] as const;
export type PreviewLayout = (typeof PREVIEW_PLATFORMS)[number] | 'feed';

/** Which layout draws a platform: its own, or the generic feed card for a platform without one. */
export function layoutFor(platform: string): PreviewLayout {
    return (PREVIEW_PLATFORMS as readonly string[]).includes(platform) ? (platform as PreviewLayout) : 'feed';
}

export interface PreviewTab {
    platform: string;
    label: string;
    accounts: AccountOption[];
}

/**
 * One tab per platform among the chosen accounts (never one for a platform nobody ticked), in a fixed order,
 * each carrying its accounts in the order they were ticked. Unknown platforms follow, by label.
 */
export function previewTabs(accounts: AccountOption[]): PreviewTab[] {
    const byPlatform = new Map<string, PreviewTab>();

    for (const account of accounts) {
        const tab = byPlatform.get(account.platform) ?? { platform: account.platform, label: account.platformLabel, accounts: [] };
        tab.accounts.push(account);
        byPlatform.set(account.platform, tab);
    }

    const rank = (platform: string): number => {
        const index = (PREVIEW_PLATFORMS as readonly string[]).indexOf(platform);

        return index === -1 ? PREVIEW_PLATFORMS.length : index;
    };

    return [...byPlatform.values()].sort((a, b) => rank(a.platform) - rank(b.platform) || a.label.localeCompare(b.label));
}

/**
 * The text that would be published to one account: its own wording when set, else the main text, then the
 * hashtags. Mirrors the server (`Post::composedBody()` with the target's `body_override`).
 */
export function composedText(body: string, override: string | null | undefined, tags: string[]): string {
    const own = (override ?? '').trim();
    const text = (own !== '' ? own : body).trim();

    return tags.length > 0 ? `${text}\n\n${tags.join(' ')}`.trim() : text;
}

/** Characters as people count them (code points, so an emoji counts once). */
export function charCount(text: string): number {
    return Array.from(text).length;
}

/** The first `max` characters of a text, cut on a character boundary. */
function take(text: string, max: number): string {
    return Array.from(text).slice(0, max).join('');
}

export interface Excerpt {
    text: string;
    /** Part of the text is hidden behind "See more". */
    truncated: boolean;
}

/**
 * The part of a caption a feed shows before "See more": at most `max` characters, ending on a word boundary
 * when one is close, without trailing spaces.
 */
export function excerpt(text: string, max: number): Excerpt {
    if (max <= 0 || charCount(text) <= max) {
        return { text, truncated: false };
    }

    const cut = take(text, max);
    const space = cut.search(/\s\S*$/u);
    const shortened = space > max * 0.6 ? cut.slice(0, space) : cut;

    return { text: shortened.trimEnd(), truncated: true };
}

/** How long a caption may run in each feed before it folds (approximate, as the platforms change it). */
export const FOLD_AT: Record<PreviewLayout, number> = {
    facebook: 480,
    instagram: 125,
    linkedin: 210,
    x: 0,
    youtube: 160,
    tiktok: 100,
    pinterest: 0,
    feed: 480,
};

export interface CountedText {
    /** The characters within the limit. */
    within: string;
    /** The characters past the limit (empty when it fits). */
    over: string;
    length: number;
    /** 0 when the platform declares no limit. */
    limit: number;
}

/** A short-text platform (X): the text split at its counted limit, so the part that would not fit stands out. */
export function countedText(text: string, limit: number): CountedText {
    const characters = Array.from(text);

    if (limit <= 0 || characters.length <= limit) {
        return { within: text, over: '', length: characters.length, limit: Math.max(0, limit) };
    }

    return { within: characters.slice(0, limit).join(''), over: characters.slice(limit).join(''), length: characters.length, limit };
}

export interface TitledText {
    title: string;
    description: string;
    /** The first line was longer than the title allows and was cut. */
    titleCut: boolean;
}

/**
 * A title and a description from one text: the first non-empty line becomes the title (cut to `titleMax`
 * characters), the rest the description. YouTube takes the video title this way (ADR 0033).
 */
export function titleFromText(text: string, titleMax = 100): TitledText {
    const lines = text.replace(/\r\n?/gu, '\n').split('\n');
    const first = lines.findIndex((line) => line.trim() !== '');

    if (first === -1) {
        return { title: '', description: '', titleCut: false };
    }

    const line = lines[first]!.trim();
    const title = titleMax > 0 ? take(line, titleMax) : line;

    return {
        title,
        description: lines.slice(first + 1).join('\n').trim(),
        titleCut: charCount(title) < charCount(line),
    };
}

/** The host of a link as feeds print it (no `www.`), or null for anything but an absolute http(s) URL. */
export function linkDomain(url: string | null | undefined): string | null {
    const safe = safeUrl((url ?? '').trim());

    if (safe === null) {
        return null;
    }

    return new URL(safe).hostname.replace(/^www\./u, '');
}

/** Two letters for an avatar tile: the first character of the first two words. */
export function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/u).filter((word) => word !== '');
    const letters = words.slice(0, 2).map((word) => Array.from(word)[0] ?? '');

    return letters.join('').toLocaleUpperCase() || '?';
}

export interface TargetState {
    /** Nothing honest can be drawn: the account cannot publish, or the media do not meet the platform's needs. */
    blocked: boolean;
    /** The platform requirements the chosen media do not meet (worded by `social.requirements.<key>`). */
    requirements: Requirement['key'][];
    /** Blocking problems the server check reported for this account. */
    issues: string[];
    /** Non-blocking notes from the server check. */
    warnings: string[];
}

/**
 * Whether one chosen account can be previewed. A missing photo or video (or an account that cannot publish)
 * replaces the preview with the reason; other server issues are listed above the preview.
 */
export function targetState(
    account: { usable: boolean; capabilities: Capabilities },
    media: { kind: string }[],
    server: PostPreview | null | undefined,
): TargetState {
    const requirements = requirementProblems(account.capabilities, media);

    return {
        blocked: !account.usable || requirements.length > 0,
        requirements,
        issues: server?.issues ?? [],
        warnings: server?.warnings ?? [],
    };
}
