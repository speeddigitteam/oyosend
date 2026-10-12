/** Shapes of the props the Email Marketing controllers send, exactly as `EmailMarketingPresenter` builds them. */

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export type Align = 'left' | 'center';

export type Block =
    | { type: 'heading'; text: string; level: 1 | 2 | 3; align: Align }
    | { type: 'text'; text: string; align: Align }
    | { type: 'image'; url: string; alt: string; link_url: string | null; width: number }
    | { type: 'button'; label: string; url: string; align: Align; style: 'primary' | 'dark' }
    | { type: 'divider' }
    | { type: 'spacer'; height: number };

export type BlockType = Block['type'];

export type CampaignStatus = 'draft' | 'scheduled' | 'preparing' | 'sending' | 'awaiting_winner' | 'paused' | 'sent' | 'cancelled';
export type DeliveryStatus = 'pending' | 'held' | 'sending' | 'queued' | 'sent' | 'delivered' | 'failed' | 'bounced' | 'blocked' | 'skipped' | 'cancelled';
export type SubscriptionStatus = 'pending' | 'subscribed' | 'unsubscribed' | 'bounced' | 'complained';
export type SequenceStatus = 'draft' | 'active' | 'paused';
export type ListStatus = 'active' | 'archived';

export interface NamedRef {
    id: string;
    name: string;
}

export interface CampaignCounts {
    planned: number;
    sent: number;
    blocked: number;
    skipped: number;
    failed: number;
    bounced: number;
    pending: number;
}

export interface CampaignSummary {
    id: string;
    name: string;
    subject: string;
    status: CampaignStatus;
    listId: string;
    listName: string | null;
    segmentName: string | null;
    scheduledFor: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    pausedReason: string | null;
    abEnabled: boolean;
    audienceTotal: number;
    counts: CampaignCounts;
    createdAt: string;
    owner: { id: string; name: string } | null;
}

export interface Figures {
    planned: number;
    pending: number;
    held: number;
    sending: number;
    queued: number;
    sent: number;
    delivered: number;
    failed: number;
    bounced: number;
    blocked: number;
    skipped: number;
    cancelled: number;
    sentTotal: number;
    clickers: number;
    clicks: number;
    viewers: number;
    clickRate: number | null;
    viewRate: number | null;
    bounceRate: number | null;
}

export interface ReasonRow {
    reason: string;
    status?: string;
    count: number;
}

export interface CampaignDetail extends CampaignSummary {
    preheader: string | null;
    blocks: Block[];
    templateId: string | null;
    segmentId: string | null;
    trackClicks: boolean;
    scheduledForLocal: string | null;
    ab: {
        enabled: boolean;
        subjectB: string | null;
        percent: number;
        waitHours: number;
        metric: 'clicks' | 'views';
        winner: 'a' | 'b' | null;
        winnerReason: string | null;
        decideAt: string | null;
        decidedAt: string | null;
    };
    report: { figures: Figures; variants: Partial<Record<'a' | 'b', Figures>>; reasons: ReasonRow[] };
    preview: { subject: string; html: string; text: string };
    abilities: {
        edit: boolean;
        delete: boolean;
        schedule: boolean;
        unschedule: boolean;
        pause: boolean;
        resume: boolean;
        cancel: boolean;
        test: boolean;
        duplicate: boolean;
        export: boolean;
    };
}

export interface ListSegmentOption {
    id: string;
    name: string;
}

export interface ListOption {
    id: string;
    name: string;
    status: ListStatus;
    segments: ListSegmentOption[];
}

export interface TemplateOption {
    id: string;
    name: string;
    subject: string | null;
    preheader: string | null;
}

export interface EditorOptions {
    variables: string[];
    subjectVariables: string[];
    maxBlocks: number;
    maxText: number;
}

export interface CampaignFormOptions {
    lists: ListOption[];
    templates: TemplateOption[];
    editor: EditorOptions;
    timezone: string;
}

export interface TemplateRow {
    id: string;
    name: string;
    subject: string | null;
    preheader: string | null;
    blockCount: number;
    blocks: Block[];
    updatedAt: string | null;
}

export interface ListRow {
    id: string;
    name: string;
    description: string | null;
    status: ListStatus;
    counts: Record<SubscriptionStatus, number>;
    createdAt: string | null;
}

export interface SegmentRule {
    field: string;
    operator: string;
    value: string | null;
}

export interface SegmentRow {
    id: string;
    name: string;
    match: 'all' | 'any';
    rules: SegmentRule[];
    count: number | null;
}

export interface MemberRow {
    id: string;
    listId: string;
    listName: string | null;
    contact: { id: string; name: string; email: string | null };
    status: SubscriptionStatus;
    source: string;
    consentMethod: string | null;
    consentEvidence: string | null;
    consentedAt: string | null;
    subscribedAt: string | null;
    unsubscribedAt: string | null;
    statusReason: string | null;
    properties: Record<string, string>;
    platform: { allowed: boolean; reason: string } | null;
}

export interface SequenceStepRow {
    id: string | null;
    position: number;
    delayMinutes: number;
    subject: string;
    preheader: string | null;
    blocks: Block[];
}

export interface SequenceRow {
    id: string;
    name: string;
    description: string | null;
    status: SequenceStatus;
    listId: string;
    listName: string | null;
    activatedAt: string | null;
    stepCount: number;
    enrollments: { active: number; completed: number; stopped: number };
    steps: SequenceStepRow[];
}

export interface DeliveryRow {
    id: string;
    contact: { id: string; name: string; email: string | null };
    variant: 'a' | 'b';
    status: DeliveryStatus;
    reason: string | null;
    sentAt: string | null;
    clickedAt: string | null;
    clickCount: number;
    viewedAt: string | null;
}

export interface ImportRow {
    id: string;
    fileName: string;
    listName: string;
    totalRows: number;
    subscribedRows: number;
    pendingRows: number;
    skippedRows: number;
    failedRows: number;
    errors: { line: number; message: string }[];
    createdAt: string;
}

export interface OverviewReport {
    deliveries: Figures;
    campaigns: { finished: number; active: number; scheduled: number };
    subscribers: Record<SubscriptionStatus, number> & { joined: number; left: number };
    reasons: ReasonRow[];
}

export interface PreviewResult {
    valid: boolean;
    errors: Record<string, string[]>;
    subject?: string;
    html?: string;
    text?: string;
}

export interface TestResult {
    member: string;
    outcome: 'sent' | 'blocked' | 'no_contact' | 'unavailable';
    reason: string | null;
}

export interface MemberOption {
    id: string;
    name: string;
    email: string;
}

export interface WidgetData {
    scheduled: number;
    sending: number;
    paused: number;
    subscribers: number | null;
    last: { id: string; name: string; finishedAt: string | null; sent: number; blocked: number; clickRate: number | null } | null;
}
