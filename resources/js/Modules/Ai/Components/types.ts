export type AiStateKey = 'ready' | 'not_configured' | 'unavailable' | 'disabled' | 'limit_reached' | 'rate_limited' | 'too_long';

export interface FeatureState {
    state: AiStateKey;
    message: string;
}

/** What the server says about AI right now (never a secret). */
export interface Availability {
    state: AiStateKey;
    message: string;
    configured: boolean;
    provider: string;
    model: string;
    features: Record<string, FeatureState>;
    /** Speech to text for voice input (ADR 0038); may be a different service from the chat provider. */
    transcription?: { configured: boolean; model: string };
}

export interface ConversationSummary {
    id: string;
    title: string;
    lastMessageAt: string | null;
}

export interface ToolInfo {
    name: string;
    description: string;
    proposes: boolean;
}

export interface ToolTrace {
    tool?: string;
    status?: string;
    record_ids?: string[];
    proposal_id?: string;
}

export interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    aiGenerated: boolean;
    toolCalls: ToolTrace[];
    inputTokens: number | null;
    outputTokens: number | null;
    createdAt: string | null;
}

export type ProposalState = 'pending' | 'confirmed' | 'rejected' | 'failed' | 'expired';

/** One field of a proposed change: the stored value and the value that would be written (plain text). */
export interface ProposalChange {
    field: string;
    old: string | null;
    new: string | null;
}

export interface ProposalRow {
    id: string;
    tool: string;
    summary: string;
    changes: ProposalChange[];
    status: ProposalState;
    pending: boolean;
    expiresAt: string;
    decidedAt: string | null;
}

export interface Allowance {
    limit: number | null;
    used: number;
    remaining: number | null;
}

export interface Breakdown {
    byFeature: { feature: string; tokens: number; requests: number }[];
    byDay: { date: string; tokens: number }[];
}
