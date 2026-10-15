import type { Capabilities } from './types';

/** One thing a platform demands of every post, worded by `social.requirements.<key>`. */
export interface Requirement {
    key: 'needsVideo' | 'needsMedia' | 'videoMaxSeconds' | 'videoMinSeconds' | 'videoMaxMb';
    /** Raw numbers for the message; the caller formats them with the workspace numerals. */
    value?: number;
}

/**
 * What a platform's capabilities say a post must carry, in display order. Mirrors the server's own
 * checks (PostContentRules::requirements); the server stays the judge.
 */
export function platformRequirements(capabilities: Capabilities): Requirement[] {
    const list: Requirement[] = [];

    if (capabilities.requiresVideo) {
        list.push({ key: 'needsVideo' });
    } else if (capabilities.requiresMedia) {
        list.push({ key: 'needsMedia' });
    }

    if ((capabilities.maxVideoSeconds ?? 0) > 0) {
        list.push({ key: 'videoMaxSeconds', value: capabilities.maxVideoSeconds });
    }

    if ((capabilities.minVideoSeconds ?? 0) > 0) {
        list.push({ key: 'videoMinSeconds', value: capabilities.minVideoSeconds });
    }

    if ((capabilities.maxVideoBytes ?? 0) > 0) {
        list.push({ key: 'videoMaxMb', value: Math.floor((capabilities.maxVideoBytes ?? 0) / (1024 * 1024)) });
    }

    return list;
}

/**
 * Why the chosen media do not meet the platform's requirements, as message keys (the client-side fallback
 * when the server check is unavailable). Empty when they do.
 */
export function requirementProblems(capabilities: Capabilities, media: { kind: string }[]): Requirement['key'][] {
    const videos = media.filter((item) => item.kind === 'video').length;

    if (capabilities.requiresVideo && (videos === 0 || videos < media.length)) {
        return ['needsVideo'];
    }

    if (capabilities.requiresMedia && media.length === 0) {
        return ['needsMedia'];
    }

    return [];
}
