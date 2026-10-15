export type TicketStatus = 'new' | 'open' | 'waiting_customer' | 'on_hold' | 'resolved' | 'closed' | 'merged';
export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';
export type TicketChannel = 'web' | 'email' | 'phone' | 'chat' | 'api' | 'internal';
export type MessageKind = 'public_reply' | 'customer_reply' | 'internal_note';
export type SurveyStatus = 'pending' | 'answered' | 'expired';
export type ArticleStatus = 'draft' | 'published' | 'archived';
export type AssignmentMode = 'manual' | 'round_robin';

export interface Option {
    id: string;
    name: string;
}

export interface QueueOption extends Option {
    assignmentMode: AssignmentMode;
}

export interface AgentOption extends Option {
    agentId: string;
    isAvailable: boolean;
}

export interface CustomerOption {
    id: string;
    name: string;
    email: string | null;
}

/** The list row of a ticket (`SupportControllerSupport::summary`). */
export interface TicketSummary {
    id: string;
    number: string;
    subject: string;
    status: TicketStatus;
    priority: TicketPriority;
    channel: TicketChannel;
    requester: { name: string | null; email: string | null; contactId: string | null; companyId: string | null; companyName?: string | null };
    queue: Option | null;
    category: Option | null;
    assignee: Option | null;
    tags: string[];
    firstRespondedAt: string | null;
    firstResponseDueAt: string | null;
    resolutionDueAt: string | null;
    slaPaused: boolean;
    firstResponseBreached: boolean;
    resolutionBreached: boolean;
    lastActivityAt: string | null;
    createdAt: string;
}

export interface TicketFormOptions {
    contacts: CustomerOption[];
    companies: CustomerOption[];
    queues: QueueOption[];
    categories: Option[];
    agents: AgentOption[];
    priorities: TicketPriority[];
    channels: TicketChannel[];
    statuses: TicketStatus[];
}

export interface AttachmentRow {
    id: string;
    name: string;
    size: number;
    mimeType: string | null;
}

export interface MessageRow {
    id: string;
    kind: MessageKind;
    isInternal: boolean;
    isCustomer: boolean;
    author: { name: string | null; email: string | null; membershipId: string | null };
    body: string;
    createdAt: string;
    attachments: AttachmentRow[];
}

export interface TicketEventRow {
    id: string;
    kind: string;
    actor: string | null;
    data: Record<string, unknown>;
    createdAt: string;
}

export interface SurveyRow {
    id: string;
    status: SurveyStatus;
    rating: number | null;
    comment: string | null;
    requestedAt: string;
    answeredAt: string | null;
    expiresAt: string;
}

export interface TicketReference {
    id: string;
    number: string;
    subject?: string;
}

export interface TicketDetail extends TicketSummary {
    department: Option | null;
    branch: Option | null;
    slaPolicy: Option | null;
    requesterName: string | null;
    requesterEmail: string | null;
    resolvedAt: string | null;
    closedAt: string | null;
    lastCustomerMessageAt: string | null;
    lastAgentMessageAt: string | null;
    reopenedCount: number;
    createdBy: string | null;
    mergedInto: TicketReference | null;
    splitFrom: TicketReference | null;
    /** The website chat the ticket was made from, when the member reaches it. */
    fromChat: string | null;
    mergedTickets: TicketReference[];
    splitTickets: TicketReference[];
}

export interface TicketAbilities {
    edit: boolean;
    reply: boolean;
    publicReply: boolean;
    assign: boolean;
    assignOthers: boolean;
    resolve: boolean;
    reopen: boolean;
    merge: boolean;
    split: boolean;
    survey: boolean;
    useCanned: boolean;
}

export interface SurveyInvitation {
    ticketId: string;
    url: string;
}

export interface QueueRow {
    id: string;
    name: string;
    description: string | null;
    departmentId: string | null;
    slaPolicyId: string | null;
    assignmentMode: AssignmentMode;
    isActive: boolean;
    agentIds: string[];
}

export interface AgentRow {
    id: string;
    membershipId: string;
    name: string;
    email: string;
    isActive: boolean;
    isAvailable: boolean;
    maxOpenTickets: number | null;
    openTickets: number;
}

export interface CandidateRow {
    id: string;
    name: string;
    email: string;
}

export interface CategoryRow {
    id: string;
    name: string;
    queueId: string | null;
    isActive: boolean;
}

export interface RuleRow {
    id: string;
    name: string;
    position: number;
    isActive: boolean;
    matchChannel: TicketChannel | null;
    matchPriority: TicketPriority | null;
    matchCategoryId: string | null;
    matchCompanyId: string | null;
    matchKeyword: string | null;
    queueId: string;
    assignAgentId: string | null;
    setPriority: TicketPriority | null;
}
