import type { BadgeTone } from '@/Core/Components/Badge.vue';

export type LeadStatus = 'received' | 'captured' | 'duplicate' | 'failed' | 'waiting_for_crm' | 'waiting_for_account' | 'skipped';
export type MappingTarget = 'name' | 'email' | 'phone' | 'company' | 'consent' | 'answer' | 'ignore';

export interface LeadCounts {
    received: number;
    processed: number;
    failed: number;
    waiting: number;
    skipped: number;
}

export interface LeadQuestion {
    key: string;
    label: string;
    type: string;
}

export interface MappingChoice {
    target: MappingTarget;
    answerKey: string | null;
}

export interface MappingPreview {
    name: string | null;
    email: string | null;
    phone: string | null;
    company: string | null;
    answers: Record<string, string>;
    consentEvidence: boolean;
    problem: 'missing_contact' | 'invalid_contact' | null;
    ignored: string[];
}

export interface LeadFormRow {
    id: string;
    externalId: string;
    name: string;
    status: string | null;
    isEnabled: boolean;
    fetchedAt: string | null;
    lastLeadAt: string | null;
    questions: LeadQuestion[];
    mapping: Record<string, MappingChoice>;
    preview: MappingPreview;
    warnings: string[];
    counts: LeadCounts;
}

export interface LeadPageRow {
    account: { id: string; name: string; avatarUrl: string | null; status: 'connected' | 'needs_reauthorization' | 'disconnected'; statusReason: string | null };
    state: 'off' | 'subscribed' | 'failed';
    pageId: string;
    subscribedAt: string | null;
    missingScopes: string[];
    needsReconnect: boolean;
    permissionsCheckedAt: string | null;
    lastError: string | null;
    lastErrorCode: string | null;
    formsSyncedAt: string | null;
    formsSyncError: string | null;
    lastBackfillAt: string | null;
    counts: LeadCounts;
    history: { date: string; received: number; processed: number; failed: number }[];
    forms: LeadFormRow[];
}

export interface LeadAdsAvailability {
    problem: 'not_enabled' | 'not_configured' | 'webhook_not_configured' | null;
    crmAvailable: boolean;
    requiredScopes: string[];
    webhookUrl: string;
    backfillDays: number;
    backfillMaxDays: number;
}

export interface LeadRow {
    id: string;
    status: LeadStatus;
    reasonCode: string | null;
    reason: string | null;
    accountId: string;
    accountName: string | null;
    formId: string | null;
    formName: string | null;
    externalFormId: string | null;
    adId: string | null;
    campaignRef: string | null;
    origin: 'webhook' | 'backfill';
    attempts: number;
    receivedAt: string;
    processedAt: string | null;
    nextAttemptAt: string | null;
    crmContactId: string | null;
    crmLeadId: string | null;
    canRetry: boolean;
}

export interface LeadLogRow {
    id: string;
    step: string;
    code: string | null;
    message: string | null;
    at: string;
}

export const LEAD_TONES: Record<LeadStatus, BadgeTone> = {
    received: 'info',
    captured: 'ok',
    duplicate: 'neutral',
    failed: 'danger',
    waiting_for_crm: 'warn',
    waiting_for_account: 'warn',
    skipped: 'neutral',
};

export const KNOWN_REASONS = [
    'account_disconnected', 'form_unknown', 'form_not_enabled', 'reauthorization_required', 'permission_missing', 'lead_not_found',
    'rate_limited', 'transient', 'gave_up', 'rejected', 'missing_contact', 'invalid_contact', 'crm_unavailable', 'already_captured',
    'expired', 'email_ignored', 'phone_ignored', 'manual',
];

function cookie(name: string): string | null {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

/** Asks the server what an unsaved mapping would do with an example lead. Null when unavailable. */
export async function requestMappingPreview(
    url: string,
    mapping: Record<string, { target: MappingTarget; answer_key: string | null }>,
    signal?: AbortSignal,
): Promise<{ preview: MappingPreview; warnings: string[] } | null> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = cookie('XSRF-TOKEN');

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    try {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify({ mapping }), signal });

        if (!response.ok) {
            return null;
        }

        const body = (await response.json()) as { preview?: MappingPreview; warnings?: string[] };

        return body.preview ? { preview: body.preview, warnings: body.warnings ?? [] } : null;
    } catch {
        return null;
    }
}
