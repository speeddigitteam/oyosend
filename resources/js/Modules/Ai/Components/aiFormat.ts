import type { Allowance, ProposalState } from './types';

/** States in which nothing can be generated, so the composer is not offered. */
const BLOCKED = new Set(['not_configured', 'disabled', 'limit_reached', 'unavailable']);

export function canCompose(state: string): boolean {
    return !BLOCKED.has(state);
}

/** Share of the allowance used, 0 to 100; null when the plan sets no limit. */
export function usedPercent(allowance: Allowance): number | null {
    if (allowance.limit === null || allowance.limit <= 0) {
        return null;
    }

    return Math.min(100, Math.round((allowance.used / allowance.limit) * 100));
}

/** Width of a bar relative to the largest value, as a whole percentage. */
export function barWidth(value: number, max: number): number {
    return max <= 0 ? 0 : Math.max(value > 0 ? 2 : 0, Math.round((value / max) * 100));
}

/** Locale key of a changed field's label; unknown fields fall back to the raw field name in the page. */
export function proposalFieldKey(field: string): string {
    return `ai.proposal.field.${field}`;
}

export function proposalTone(status: ProposalState): 'ok' | 'warn' | 'danger' | 'neutral' {
    switch (status) {
        case 'confirmed':
            return 'ok';
        case 'pending':
            return 'warn';
        case 'failed':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function stateTone(state: string): 'ok' | 'warn' | 'danger' | 'info' {
    switch (state) {
        case 'ready':
            return 'ok';
        case 'not_configured':
        case 'disabled':
            return 'info';
        case 'limit_reached':
        case 'unavailable':
            return 'danger';
        default:
            return 'warn';
    }
}
