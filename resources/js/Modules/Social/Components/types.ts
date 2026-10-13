import type { Paginated } from '@/Core/types';

export type { Paginated };

export type PostStatus =
    | 'draft' | 'pending_approval' | 'approved' | 'scheduled' | 'publishing' | 'published' | 'partially_failed' | 'failed';
export type TargetStatus = 'pending' | 'publishing' | 'processing' | 'published' | 'failed';
export type PostType = 'post' | 'photos' | 'video' | 'reel';

/** One platform's verdict on a library file (`code` is worded by the `socialPublishing.compat` messages). */
export interface CompatBadge {
    platform: string;
    platformLabel: string;
    code: string;
    tone: 'ok' | 'warn' | 'danger' | 'muted';
}
export type AccountStatus = 'connected' | 'needs_reauthorization' | 'disconnected';
export type AttemptOutcome = 'success' | 'retry' | 'failed' | 'deferred';
export type CampaignStatus = 'active' | 'archived';
export type MediaKind = 'image' | 'video';

export interface NamedRef {
    id: string;
    name: string;
}

export interface Capabilities {
    publish?: boolean;
    schedule?: boolean;
    analytics?: boolean;
    comments?: boolean;
    images?: boolean;
    video?: boolean;
    maxText?: number;
    maxMedia?: number;
}

export interface PlatformInfo {
    key: string;
    label: string;
    available: boolean;
    unavailableCode: string | null;
    reason: string | null;
    capabilities: Capabilities;
}

export interface AccountRow {
    id: string;
    platform: string;
    platformLabel: string;
    name: string;
    handle: string | null;
    avatarUrl: string | null;
    type: string | null;
    status: AccountStatus;
    statusReason: string | null;
    needsReauthorization: boolean;
    platformAvailable: boolean;
    capabilities: Capabilities;
    connectedAt: string | null;
    lastUsedAt: string | null;
    tokenExpiresAt: string | null;
    canRefresh: boolean;
}

export interface AccountOption {
    id: string;
    platform: string;
    platformLabel: string;
    name: string;
    handle: string | null;
    status: AccountStatus;
    usable: boolean;
    capabilities: Capabilities;
    postTypes?: PostType[];
}

export interface MediaRow {
    id: string;
    name: string;
    kind: MediaKind;
    mime: string;
    sizeBytes: number;
    width: number | null;
    height: number | null;
    altText: string | null;
    url: string;
    createdAt: string | null;
    inUse?: boolean;
    durationMs?: number | null;
    probe?: 'read' | 'unknown' | null;
    compat?: CompatBadge[];
}

export interface PostTargetSummary {
    id: string;
    accountId: string;
    platform: string;
    accountName: string;
    status: TargetStatus;
}

export interface PostSummary {
    id: string;
    title: string | null;
    excerpt: string;
    status: PostStatus;
    postType?: PostType;
    /** AI suggestions were used on this draft (informational marker for approvers). */
    aiAssisted?: boolean;
    scheduledFor: string | null;
    publishedAt: string | null;
    at: string | null;
    campaign: NamedRef | null;
    category: NamedRef | null;
    targets: PostTargetSummary[];
    createdAt: string | null;
}

export interface AttemptLogEntry {
    attempt: number;
    outcome: AttemptOutcome;
    errorCode: string | null;
    message: string | null;
    at: string | null;
}

export interface PostTargetDetail {
    id: string;
    accountId: string;
    platform: string;
    platformLabel: string;
    accountName: string;
    accountStatus: AccountStatus;
    bodyOverride: string | null;
    status: TargetStatus;
    attempts: number;
    nextAttemptAt: string | null;
    remoteState?: string | null;
    processingSince?: string | null;
    publishedAt: string | null;
    externalUrl: string | null;
    lastError: string | null;
    lastErrorCode: string | null;
    needsManualCheck: boolean;
    canRetry: boolean;
    attemptLog: AttemptLogEntry[];
}

export interface PostComment {
    id: string;
    body: string;
    author: { id: string; name: string } | null;
    at: string | null;
    mine: boolean;
}

export interface PostPreview {
    targetId: string;
    accountId: string;
    platform: string;
    platformLabel: string;
    accountName: string;
    accountStatus: AccountStatus;
    text: string;
    length: number;
    maxLength: number;
    mediaCount: number;
    capabilities: Capabilities;
    issues: string[];
    warnings?: string[];
    postType?: PostType;
}

export interface PostAbilities {
    edit: boolean;
    delete: boolean;
    submit: boolean;
    approve: boolean;
    reject: boolean;
    schedule: boolean;
    reschedule: boolean;
    unschedule: boolean;
    retry: boolean;
    comment: boolean;
}

export interface PostDetail extends Omit<PostSummary, 'targets'> {
    body: string | null;
    linkUrl: string | null;
    hashtags: string[];
    hashtagSetId: string | null;
    scheduledForLocal: string | null;
    owner: { id: string; name: string } | null;
    submittedAt: string | null;
    approvedAt: string | null;
    rejectedAt: string | null;
    rejectionReason: string | null;
    media: MediaRow[];
    targets: PostTargetDetail[];
    comments: PostComment[];
    previews: PostPreview[];
    approval: { required: boolean; submittedBy: string | null; approvedBy: string | null; mineToApprove: boolean };
    abilities: PostAbilities;
    now: string;
}

export interface HashtagSetRow {
    id: string;
    name: string;
    tags: string[];
}

export interface FormOptions {
    accounts: AccountOption[];
    campaigns: NamedRef[];
    categories: NamedRef[];
    hashtagSets: HashtagSetRow[];
    media: MediaRow[];
    approvalRequired: boolean;
    timezone: string;
    platforms: PlatformInfo[];
    limits: { maxMedia: number; maxBody: number };
}

export interface CampaignRow {
    id: string;
    name: string;
    description: string | null;
    status: CampaignStatus;
    startsOn: string | null;
    endsOn: string | null;
    posts: number;
}

export interface CategoryRow {
    id: string;
    name: string;
    posts: number;
}

export interface ImportRow {
    id: string;
    fileName: string;
    mode: 'draft' | 'schedule';
    totalRows: number;
    createdRows: number;
    failedRows: number;
    errors: { line: number; message: string }[];
    createdAt: string | null;
}

export interface EngagementFigures {
    impressions: number | null;
    reach: number | null;
    likes: number | null;
    comments: number | null;
    shares: number | null;
    clicks: number | null;
}

export interface AnalyticsReport {
    from: string;
    to: string;
    publishing: {
        published: number;
        failed: number;
        pending: number;
        byPlatform: { platform: string; label: string; published: number; failed: number }[];
    };
    engagement: {
        reportedPosts: number;
        capturedAt: string | null;
        totals: EngagementFigures;
        byPlatform: { platform: string; label: string; posts: number; totals: EngagementFigures }[];
        topPosts: {
            targetId: string;
            postId: string;
            title: string | null;
            platform: string;
            platformLabel: string;
            accountName: string;
            url: string | null;
            capturedAt: string;
            engagement: number;
            figures: EngagementFigures;
        }[];
    };
    platformsWithAnalytics: string[];
}
