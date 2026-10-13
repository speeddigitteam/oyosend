import type { Paginated } from '@/Core/types';

export type { Paginated };

export type TemplateStatus = 'pending' | 'approved' | 'rejected' | 'paused' | 'disabled';
export type TemplateCategory = 'marketing' | 'utility' | 'authentication';
export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'completed' | 'cancelled' | 'failed';
export type RecipientState = 'pending' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'blocked' | 'skipped';
export type MessageStatus = 'received' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'blocked';
export type ConversationStatus = 'open' | 'closed';
export type SenderType = 'customer' | 'agent' | 'bot' | 'campaign' | 'automation';

/** What the communication platform says about the WhatsApp channel (`ChannelStatus::describe()`). */
export interface ChannelInfo {
    state: 'available' | 'not_configured';
    reason: string | null;
    testDouble: boolean;
}

/** An active agent as an assignable member: `id` is the membership id. */
export interface AgentOption {
    id: string;
    agentId: string;
    name: string;
    isAvailable: boolean;
}

export interface ConversationSummary {
    id: string;
    phone: string;
    displayName: string;
    profileName: string | null;
    contact: { id: string; name: string } | null;
    status: ConversationStatus;
    owner: { id: string; name: string } | null;
    tags: string[];
    unread: number;
    lastMessageAt: string | null;
    preview: string | null;
    lastDirection: string | null;
    windowOpen: boolean;
    windowExpiresAt: string | null;
    botPaused: boolean;
}

export interface ConversationDetail extends ConversationSummary {
    createdAt: string;
    firstRespondedAt: string | null;
    closedAt: string | null;
    department: { id: string; name: string } | null;
    branch: { id: string; name: string } | null;
    contactPhoneMatches: boolean;
}

export interface MessageAttachment {
    kind?: string;
    mime?: string;
    has_caption?: boolean;
    /** An inbound file the platform can download and serve (ADR 0022). */
    downloadable?: boolean;
    filename?: string | null;
    voice?: boolean;
}

/** One WhatsApp business number of the workspace (ADR 0022). */
export interface WhatsappNumber {
    id: string;
    label: string;
    displayNumber: string | null;
    accountId: string;
    isDefault: boolean;
    isActive: boolean;
}

/** The number picker's active numbers and the pre-selected default. */
export interface NumberPicker {
    options: WhatsappNumber[];
    defaultId: string | null;
}

/** Reply buttons or a list an outbound message offered. The customer's choice comes back as the label's text. */
export interface MessageChoices {
    kind: 'buttons' | 'list';
    button: string | null;
    options: string[];
}

export interface MessageRow {
    id: string;
    direction: 'inbound' | 'outbound';
    senderType: SenderType;
    author: string | null;
    type: string;
    body: string | null;
    templateName: string | null;
    status: MessageStatus;
    failureCode: string | null;
    attachments: MessageAttachment[];
    interactive?: MessageChoices | null;
    occurredAt: string;
    deliveredAt: string | null;
    readAt: string | null;
}

/** An approved template as offered when sending. */
export interface SendableTemplate {
    id: string;
    name: string;
    language: string;
    category: TemplateCategory;
    body: string;
    variableCount: number;
}

export interface QuickReplyRow {
    id: string;
    shortcut: string;
    title: string;
    body: string;
    isActive: boolean;
}

export interface ContactMatch {
    id: string;
    name: string;
    phone: string | null;
    validPhone?: boolean;
}

export interface TemplateButton {
    type: 'quick_reply' | 'url' | 'phone_number';
    text: string;
    value: string | null;
}

export interface TemplateRow {
    id: string;
    name: string;
    language: string;
    category: TemplateCategory;
    status: TemplateStatus;
    statusSource: string;
    statusReason: string | null;
    statusChangedAt: string | null;
    body: string;
    variableCount: number;
    header: { type: string; text: string } | null;
    footer: { type: string; text: string } | null;
    buttons: TemplateButton[];
}

export interface ListRow {
    id: string;
    name: string;
    description: string | null;
    memberCount: number;
}

export type VariableMapping = { type: 'field'; field: string } | { type: 'text'; value: string };

export interface CampaignRow {
    id: string;
    name: string;
    status: CampaignStatus;
    templateId: string | null;
    templateName: string;
    templateLanguage: string;
    templateCategory: string;
    templateBody: string;
    listId: string | null;
    listName: string;
    variables: Record<string, VariableMapping> | null;
    headerMediaType: string | null;
    headerMediaId: string | null;
    buttonParams: { sub_type: string; index: number; value: string }[];
    senderNumberId: string | null;
    scheduledAt: string | null;
    startedAt: string | null;
    completedAt: string | null;
    totalRecipients: number;
    stopReason: string | null;
    owner: { id: string; name: string } | null;
    createdAt: string;
}

export type FlowSimpleStep =
    | { type: 'reply'; text: string }
    | { type: 'tag'; tag: string }
    | { type: 'assign'; assignee_id: string | null }
    | { type: 'handoff' };

export type FlowStep = FlowSimpleStep | { type: 'menu'; prompt: string; options: { label: string; actions: FlowSimpleStep[] }[] };

export interface FlowRow {
    id: string;
    name: string;
    trigger: 'keyword' | 'welcome';
    matchType: 'exact' | 'contains' | null;
    keywords: string[];
    actions: FlowStep[];
    priority: number;
    isActive: boolean;
}

export interface FlowLimits {
    actions: number;
    options: number;
    optionActions: number;
    keywords: number;
}

export interface KanbanColumn {
    key: 'unassigned' | 'active' | 'closed';
    count: number;
    items: ConversationSummary[];
}

/** An internal note on a conversation: for the team only, never sent. */
export interface ConversationNoteRow {
    id: string;
    body: string;
    author: string | null;
    createdAt: string;
    removable: boolean;
}

export type FeedbackStatus = 'pending' | 'answered' | 'expired';

/** The latest rating request of a conversation. */
export interface ConversationFeedback {
    status: FeedbackStatus;
    rating: number | null;
    requestedAt: string;
    answeredAt: string | null;
}

/** One declarative rule that picks contacts for a list. */
export interface ContactRule {
    field: string;
    operator: string;
    value: string;
}
