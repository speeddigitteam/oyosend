export type ChannelKey = 'email' | 'sms' | 'whatsapp';
export type ChannelState = 'configured' | 'not_configured';
export type MessagePurpose = 'transactional' | 'marketing';
export type MessageStatus = 'queued' | 'sending' | 'sent' | 'delivered' | 'failed' | 'bounced' | 'suppressed' | 'cancelled';
export type InteractionType = 'note' | 'call' | 'meeting' | 'email' | 'sms' | 'whatsapp' | 'system';

export interface ChannelStatus {
    channel: ChannelKey;
    state: ChannelState;
}

export interface NotificationItem {
    id: string;
    category: string;
    severity: string;
    /** Already translated by the server in the viewer's language. */
    title: string;
    body: string | null;
    titleKey: string;
    bodyKey: string | null;
    params: Record<string, string | number | boolean | null>;
    url: string | null;
    read: boolean;
    archived: boolean;
    createdAt: string;
}

export interface NameRef {
    id: string;
    name: string;
}

export interface MessageRow {
    id: string;
    channel: ChannelKey;
    purpose: MessagePurpose;
    status: MessageStatus;
    recipient: string;
    subject: string | null;
    source: string | null;
    contact: NameRef | null;
    company: NameRef | null;
    attempts: number;
    failureCode: string | null;
    queuedAt: string | null;
    nextAttemptAt: string | null;
    sentAt: string | null;
    deliveredAt: string | null;
    failedAt: string | null;
    createdAt: string;
    retryable: boolean;
    cancellable: boolean;
}

export interface MessageDetail extends MessageRow {
    body: string | null;
    locale: string | null;
    provider: string | null;
    failureMessage: string | null;
    templateId: string | null;
    owner: NameRef | null;
}

export interface ContactOption {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
}

export interface TemplateRow {
    id: string;
    slug: string;
    name: string;
    channel: ChannelKey;
    locale: string;
    purpose: MessagePurpose;
    subject: string | null;
    body: string;
    isActive: boolean;
}

export interface TemplateOption {
    id: string;
    name: string;
    channel: ChannelKey;
    locale: string;
    purpose: MessagePurpose;
    subject: string | null;
}

export interface Variable {
    key: string;
    label: string;
}
