import type { TenantStatus } from '@/Platform/Components/TenantStatusBadge.vue';

/** The workspace as `TenantController::show` describes it. */
export interface WorkspaceDetail {
    id: string;
    name: string;
    legalName: string | null;
    slug: string;
    /** Absolute URL of the workspace. */
    url: string;
    status: TenantStatus;
    locale: string;
    timezone: string;
    currency: string;
    email: string | null;
    phone: string | null;
    members: number;
    owner: { name: string; email: string } | null;
    /** Whether the first-run setup has been finished. */
    onboarded: boolean;
    suspendedAt: string | null;
    createdAt: string;
}

/** An entry of the workspace's own audit trail. */
export interface WorkspaceActivity {
    id: string;
    event: string;
    actor: string | null;
    subject: string | null;
    createdAt: string;
}

/** One time an operator opened the workspace as its owner. */
export interface SupportSessionEntry {
    id: string;
    /** Email of the operator. */
    by: string;
    reason: string;
    /** Null while the link that carries the session has not been used. */
    startedAt: string | null;
    endedAt: string | null;
    /** When the session ends by itself. */
    expiresAt: string;
    createdAt: string;
}

/** What this operator may do to the workspace. The server checks each action again. */
export interface WorkspaceAbilities {
    /** Approve, suspend, reactivate. */
    manage: boolean;
    /** Open the workspace as its owner. */
    impersonate: boolean;
    /** Set its plan, extend its trial. */
    manageBilling: boolean;
}
