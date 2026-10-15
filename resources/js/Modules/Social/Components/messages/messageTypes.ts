/** Props of the Social "Messages" page (Messenger and Instagram direct messages, ADR 0031). */

export type MessagePlatform = 'messenger' | 'instagram';
export type ConversationStatus = 'open' | 'closed';
export type CaptureState = 'off' | 'on' | 'failed';
export type InstagramState = 'off' | 'on' | 'not_linked' | 'missing_permissions' | 'taken' | 'failed';
export type MessageStatus = 'received' | 'sending' | 'sent' | 'delivered' | 'read' | 'deleted';
export type AvailabilityProblem = 'not_enabled' | 'not_configured' | 'webhook_not_configured';

export interface MessagingAvailability {
    problem: AvailabilityProblem | null;
    platforms: MessagePlatform[];
    instagram: boolean;
    windowHours: number;
    messengerScopes: string[];
    instagramScopes: string[];
    webhookUrl: string;
}

export interface CapturePage {
    id: string;
    name: string;
    status: 'connected' | 'needs_reauthorization' | 'disconnected';
    enabled: boolean;
    state: CaptureState;
    instagramState: InstagramState;
    missingScopes: string[];
    lastError: string | null;
    lastEventAt: string | null;
}

export interface ConversationRow {
    id: string;
    platform: MessagePlatform;
    title: string;
    accountName: string;
    preview: string | null;
    lastMessageAt: string | null;
    unread: number;
    status: ConversationStatus;
    ownerName: string | null;
}

export interface DirectMessageItem {
    id: string;
    direction: 'in' | 'out';
    text: string | null;
    at: string;
    authorName: string | null;
    status: MessageStatus;
    attachment: string | null;
}

export interface ConversationThread extends ConversationRow {
    owner: { id: string; name: string } | null;
    messages: DirectMessageItem[];
    hasOlder: boolean;
    canReply: boolean;
    replyable: boolean;
    blockedReason: string | null;
    windowEndsAt: string | null;
    textMax: number;
}

export interface MessagesCan {
    reply: boolean;
    manage: boolean;
}

export interface MessageFilters {
    platform: MessagePlatform | null;
    status: ConversationStatus | 'all';
    q: string | null;
}
