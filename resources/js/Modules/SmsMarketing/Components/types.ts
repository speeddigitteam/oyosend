import type { Paginated } from '@/Core/types';
import type { SegmentCount } from './smsFormat';

export type { Paginated, SegmentCount };

export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'completed' | 'cancelled';
export type RecipientStatus = 'pending' | 'queued' | 'sent' | 'delivered' | 'failed' | 'blocked' | 'skipped';
export type TemplatePurpose = 'marketing' | 'transactional';
export type SenderType = 'masking' | 'non_masking';
export type RegistrationStatus = 'not_registered' | 'pending' | 'registered' | 'rejected';

export interface CampaignStats {
    recipients: number;
    pending: number;
    queued: number;
    sent: number;
    delivered: number;
    failed: number;
    blocked: number;
    skipped: number;
    accepted: number;
    segments: number;
}

export interface CampaignRow {
    id: string;
    name: string;
    status: CampaignStatus;
    holdReason: string | null;
    scheduledFor: string | null;
    startedAt: string | null;
    completedAt: string | null;
    estimatedRecipients: number;
    estimatedSegments: number;
    stats: CampaignStats;
    createdAt: string | null;
    owner: { id: string; name: string } | null;
}

export interface CampaignDetail extends CampaignRow {
    body: string;
    templateName: string | null;
    groups: { id: string; name: string }[];
    groupIds: string[];
    templateId: string | null;
    senderIdentityId: string | null;
    sender: { id: string; label: string; value: string } | null;
    maxAttempts: number;
    retryable: number;
    abilities: { edit: boolean; delete: boolean; launch: boolean; cancel: boolean; retry: boolean };
}

export interface ChannelState {
    state: 'available' | 'not_configured';
    reason: string | null;
    testDouble: boolean;
}

export interface Standing {
    channel: ChannelState;
    plan: { limit: number | null; used: number; remaining: number | null };
    contacts: { limit: number | null; used: number };
    credit: { reported: boolean; balance: number | null };
    dailyCap: { cap: number; used: number };
}

export interface GroupRow {
    id: string;
    name: string;
    description: string | null;
    membersCount: number;
}

export interface TemplateRow {
    id: string;
    name: string;
    purpose: TemplatePurpose;
    body: string;
    isActive: boolean;
    segments: SegmentCount;
}

export interface SenderRow {
    id: string;
    label: string;
    value: string;
    type: SenderType;
    registrationStatus: RegistrationStatus;
    registrationReference: string | null;
    registrationNote: string | null;
}

export interface RecipientRow {
    id: string;
    contactId: string | null;
    name: string;
    phone: string | null;
    status: RecipientStatus;
    reason: string | null;
    segments: number;
    encoding: string | null;
    attempts: number;
    sentAt: string | null;
    deliveredAt: string | null;
    nextAttemptAt: string | null;
    retryable: boolean;
}

export interface MemberRow {
    id: string;
    contactId: string;
    name: string;
    phone: string | null;
    email: string | null;
    validNumber: boolean;
}

export interface Candidate {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    validNumber: boolean;
}

export interface ImportRow {
    id: string;
    fileName: string;
    totalRows: number;
    addedRows: number;
    failedRows: number;
    errors: { line: number; message: string }[];
    createdAt: string | null;
}

export interface AudienceEstimate {
    contacts: number;
    validNumbers: number;
    invalidNumbers: number;
    checked: boolean;
    sendable: number | null;
    blocked: Record<string, number>;
    segmentsPerMessage: number;
    encoding: string;
    segments: number;
    exact: boolean;
    costAmount: number | null;
    currency: string | null;
    tooLarge: boolean;
    channelAvailable: boolean;
}

export interface FormOptions {
    groups: GroupRow[];
    templates: { id: string; name: string; body: string }[];
    senders: { id: string; label: string; value: string }[];
    defaultSenderId: string | null;
    optOutWording: string;
    timezone: string;
    limits: { maxBody: number; maxSegments: number; maxAudience: number };
    channel: ChannelState;
}

export interface SettingsValues {
    dailyCap: number;
    throttlePerMinute: number;
    optOutWording: string | null;
    optOutDefault: string;
    segmentCostAmount: number | null;
    currency: string;
    defaultSenderId: string | null;
    bounds: { dailyCap: number[]; throttlePerMinute: number[] };
}

export interface AnalyticsReport {
    totals: { handed: number; sent: number; delivered: number; failed: number; blocked: number; skipped: number; pending: number; segments: number };
    deliveryRate: number | null;
    daily: { date: string; sent: number; delivered: number; failed: number; blocked: number; segments: number }[];
}
