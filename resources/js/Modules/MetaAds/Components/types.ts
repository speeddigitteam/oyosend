import type { Paginated } from '@/Core/types';

export type { Paginated };

export type ReadinessState = 'done' | 'todo' | 'attention' | 'blocked' | 'optional' | 'unavailable';

export interface ReadinessItem {
    key: 'meta_app' | 'authorize' | 'accounts' | 'first_sync' | 'social_page' | 'management';
    state: ReadinessState;
}

export interface ConnectionRow {
    id: string;
    userName: string | null;
    status: 'connected' | 'needs_reauthorization' | 'disconnected';
    statusReason: string | null;
    tokenExpiresAt: string | null;
    expiresSoon: boolean;
    expired: boolean;
    canManage: boolean;
    grantedScopes: string[];
    connectedAt: string | null;
    lastError: string | null;
}

export type SyncState = 'ok' | 'partial' | 'throttled' | 'error';

export interface AdAccountRow {
    id: string;
    externalId: string;
    name: string;
    businessName: string | null;
    currency: string;
    timezone: string | null;
    metaStatus: string | null;
    metaActive: boolean;
    status: 'available' | 'tracked' | 'removed';
    connectionId: string;
    syncState: SyncState | null;
    syncError: string | null;
    syncErrorCode: string | null;
    lastSyncedAt: string | null;
    insightsThrough: string | null;
    throttledUntil: string | null;
    usagePercent: number | null;
    dailyCeiling: number | null;
    lifetimeCeiling: number | null;
}

export interface Totals {
    spend: number | null;
    impressions: number | null;
    reach: number | null;
    clicks: number | null;
    leads: number | null;
    ctr: number | null;
    cpc: number | null;
    cpm: number | null;
    costPerLead: number | null;
}

export interface Summary {
    current: Totals;
    previous: Totals;
    deltas: Record<keyof Totals, number | null>;
}

export interface SeriesPoint {
    date: string;
    spend: number | null;
    impressions: number | null;
    clicks: number | null;
    leads: number | null;
}

export interface CampaignPerformance {
    accountId: string;
    accountName: string | null;
    campaignId: string;
    objectId: string | null;
    name: string | null;
    status: string | null;
    currency: string;
    spend: number | null;
    impressions: number | null;
    clicks: number | null;
    leads: number | null;
    ctr: number | null;
    cpc: number | null;
    costPerLead: number | null;
}

export interface Range {
    from: string;
    to: string;
    days: number;
}

export type Level = 'campaign' | 'adset' | 'ad';

export interface ObjectRow {
    id: string;
    externalId: string;
    level: Level;
    name: string;
    status: string | null;
    effectiveStatus: string | null;
    objective: string | null;
    dailyBudget: number | null;
    lifetimeBudget: number | null;
    budgetRemaining: number | null;
    currency: string;
    startAt: string | null;
    endAt: string | null;
    accountId: string;
    accountName: string;
    parentName: string | null;
    insights: {
        spend: number | null;
        impressions: number | null;
        clicks: number | null;
        leads: number | null;
        ctr: number | null;
        costPerLead: number | null;
    } | null;
    canPause: boolean;
    canResume: boolean;
    canEditBudget: boolean;
    budgetField: 'daily_budget' | 'lifetime_budget' | null;
    ceiling: number | null;
    blockedReason: string | null;
}

export interface CeilingRow {
    accountId: string;
    name: string;
    externalId: string;
    currency: string;
    daily: string;
    lifetime: string;
}
