export const INSIGHT_FIGURES = [
    'followers', 'new_followers', 'unfollows', 'impressions', 'reach', 'engaged_users',
    'post_engagements', 'page_views', 'reactions', 'video_views', 'fans',
] as const;
export type InsightFigure = (typeof INSIGHT_FIGURES)[number];

export type InsightsState = 'no_accounts' | 'restricted' | 'not_configured' | 'permission_missing' | 'waiting' | 'ready';

export interface InsightKpi {
    figure: InsightFigure;
    level: boolean;
    value: number | null;
    previous: number | null;
    delta: number | null;
    deltaPct: number | null;
    days: number;
    previousDays: number;
    expectedDays: number;
    asOf: string | null;
}

export interface InsightTrend {
    total: (number | null)[];
    accounts: { id: string; name: string; values: (number | null)[] }[];
}

export interface InsightComparisonRow {
    id: string;
    name: string;
    platform: string;
    platformLabel: string;
    status: string;
    figures: Record<string, number | null>;
    daysReported: number;
    insightsStatus: string | null;
    syncedAt: string | null;
}

export interface PostFigures {
    impressions: number | null;
    reach: number | null;
    likes: number | null;
    comments: number | null;
    shares: number | null;
    clicks: number | null;
}

export interface InsightPostRow {
    targetId: string;
    postId: string;
    title: string | null;
    accountName: string;
    platform: string;
    url: string | null;
    publishedAt: string | null;
    engagement: number;
    figures: PostFigures;
}

export interface InsightContentType {
    type: 'text' | 'image' | 'video' | 'link';
    posts: number;
    reported: number;
    engagement: number | null;
    average: number | null;
    totals: PostFigures;
}

export interface InsightCampaign {
    id: string | null;
    name: string | null;
    posts: number;
    reported: number;
    engagement: number | null;
    totals: PostFigures;
    timeline: { date: string; posts: number; engagement: number | null }[];
}

export interface BestTimes {
    windowDays: number;
    timezone: string;
    posts: number;
    minimum: number;
    sufficient: boolean;
    byHour: { hour: number; posts: number; engagement: number | null; average: number | null }[];
    byWeekday: { weekday: number; posts: number; engagement: number | null; average: number | null }[];
    slots: { weekday: number; hour: number; posts: number; engagement: number; average: number }[];
}

export interface InsightsDashboard {
    state: InsightsState;
    period: {
        days: number;
        from: string;
        to: string;
        previousFrom: string;
        previousTo: string;
        timezone: string;
        dates: string[];
        options: number[];
    };
    pages: { id: string; name: string; platform: string; selected: boolean }[];
    selected: string[];
    maxPages: number;
    kpis: InsightKpi[];
    trend: Record<InsightFigure, InsightTrend>;
    comparison: InsightComparisonRow[];
    posts: {
        published: number;
        reported: number;
        top: InsightPostRow[];
        bottom: InsightPostRow[];
        contentTypes: InsightContentType[];
        campaigns: InsightCampaign[];
        bestTimes: BestTimes;
    };
    coverage: {
        lastUpdatedAt: string | null;
        daysReported: number;
        expectedDays: number;
        notes: string[];
    };
}

export interface AccessEntry {
    subjectType: 'membership' | 'role';
    subjectId: string;
    canPublish: boolean;
    canApprove: boolean;
    canAnalytics: boolean;
}

export interface AccessAccount {
    tasks: string[] | null;
    tasksStatus: string | null;
    tasksCheckedAt: string | null;
    scopes: string[] | null;
    insightsStatus: string | null;
    insightsSyncedAt: string | null;
    restricted: boolean;
    entries: AccessEntry[];
    entryCount: number;
}

export interface AccessPanelProps {
    accounts: Record<string, AccessAccount>;
    features: { feature: string; task: string; permissions: string[] }[];
    canManage: boolean;
    members: { id: string; name: string }[];
    roles: { id: string; name: string }[];
}
