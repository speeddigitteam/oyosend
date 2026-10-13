/** Shapes the audience-comments pages receive from the server (see `CommentPresenter`). */

export interface AudienceComment {
    id: string;
    accountId: string;
    accountName: string;
    platform: string;
    platformLabel: string;
    authorName: string | null;
    isFromPage: boolean;
    message: string | null;
    commentedAt: string | null;
    unread: boolean;
    replied: boolean;
    repliedAt: string | null;
    replyCount: number;
    hidden: boolean;
    deleted: boolean;
    needsAttention: boolean;
    attentionReason: string | null;
    handled: boolean;
    tags: string[];
    assignee: { id: string; name: string } | null;
    post: { id: string; title: string } | null;
    externalPostId: string | null;
    permalink: string | null;
    likeCount: number;
    likedByPage: boolean;
    hasAttachment: boolean;
    sentiment: string | null;
    isReply: boolean;
    parentId: string | null;
    lastError: string | null;
    source: string;
}

export interface CommentNote {
    id: string;
    body: string;
    at: string | null;
    author: { id: string; name: string } | null;
}

export interface CommentThreadData {
    focus: string;
    root: string;
    comments: AudienceComment[];
    notes: CommentNote[];
}

export type InboxStatus = 'open' | 'unread' | 'needs_attention' | 'replied' | 'hidden' | 'mine' | 'handled';

export interface Member {
    id: string;
    name: string;
}

export interface CommentCan {
    reply: boolean;
    moderate: boolean;
    rules: boolean;
}

export type RuleActionType = 'reply' | 'hide' | 'assign' | 'tag' | 'review' | 'private_reply';

export interface RuleAction {
    type: RuleActionType;
    message?: string;
    membership_id?: string;
    tag?: string;
}

export interface CommentRuleData {
    id: string;
    name: string;
    enabled: boolean;
    matchMode: 'any' | 'all';
    matchType: 'word' | 'contains';
    keywords: string[];
    accountId: string | null;
    postId: string | null;
    campaignId: string | null;
    actions: RuleAction[];
    replyTemplates: string[];
    hourlyCap: number;
    cooldownMinutes: number;
    quietStart: string | null;
    quietEnd: string | null;
    priority: number;
    matchedCount: number;
    lastMatchedAt: string | null;
    actor: Member | null;
    standing: string | null;
}

export interface CommentPage {
    accountId: string;
    name: string;
    platform: string;
    platformLabel: string;
    avatarUrl: string | null;
    supported: boolean;
    connected: boolean;
    enabled: boolean;
    owned: boolean;
    webhookState: 'none' | 'subscribed' | 'failed';
    lastEventAt: string | null;
    lastPolledAt: string | null;
    errorCode: string | null;
    privateReplies: boolean;
}

export interface RulePreview {
    matched: boolean;
    hits: string[];
    actions: { type: string; detail: string | null; note: string | null }[];
    quiet: boolean;
}

/** Posts a JSON request the way the composer's preflight does (session cookie + XSRF header). */
export async function postJson<T>(url: string, input: unknown, signal?: AbortSignal): Promise<T | null> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    if (match) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1]);
    }

    try {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(input), signal });

        return response.ok ? ((await response.json()) as T) : null;
    } catch {
        return null;
    }
}
