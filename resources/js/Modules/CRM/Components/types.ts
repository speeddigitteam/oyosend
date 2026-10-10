import type { Paginated } from '@/Core/types';

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'disqualified' | 'converted';
export type DealStatus = 'open' | 'won' | 'lost';
export type ActivityType = 'task' | 'meeting' | 'call' | 'follow_up';
export type ActivityStatus = 'planned' | 'completed' | 'canceled';
export type ActivityPriority = 'low' | 'normal' | 'high' | 'urgent';
export type CatalogItemType = 'product' | 'service';
export type QuoteKind = 'quotation' | 'proposal';
export type QuoteStatus = 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired' | 'canceled';
export type SalesOrderStatus = 'confirmed' | 'fulfilled' | 'canceled';
export type ContractStatus = 'draft' | 'active' | 'expired' | 'terminated';
export type CustomerLifecycleStage = 'lead' | 'prospect' | 'customer' | 'inactive' | 'churned';

export interface Option {
    id: string;
    name: string;
}

export interface ContactOption extends Option {
    email?: string | null;
    phone?: string | null;
}

export interface OwnerOption extends Option {}

export interface TimelineEntry {
    id: string;
    event: string;
    summary: string;
    actor: string | null;
    metadata: Record<string, unknown> | null;
    occurredAt: string;
}

export interface RecordContent {
    notes: Array<{ id: string; body: string; pinned: boolean; author: string | null; createdAt: string }>;
    tags: Array<{ id: string; name: string; colour: string | null }>;
    tagOptions: Array<{ id: string; name: string; colour: string | null }>;
    customFields: Array<{ id: string; key: string; name: string; type: 'text' | 'number' | 'boolean' | 'date' | 'select'; required: boolean; options: string[] | null; value: unknown }>;
    attachments: Array<{ id: string; name: string; mimeType: string; size: number; createdAt: string }>;
}

export interface Lead {
    id: string;
    title: string;
    status: LeadStatus;
    score: number;
    qualificationNotes: string | null;
    qualifiedAt: string | null;
    convertedAt: string | null;
    contact: ContactOption | null;
    company: Option | null;
    source: Option | null;
    owner: OwnerOption | null;
    editable: boolean;
    qualifiable: boolean;
    assignable: boolean;
    createdAt: string;
}

export interface LeadListProps {
    leads: Paginated<Lead>;
    filters: { search: string; status: LeadStatus | null; source: string | null; segment: string | null };
    segments: Option[];
    sources: Option[];
    contacts: Option[];
    companies: Option[];
    owners: OwnerOption[];
    canCreate: boolean;
}

export interface PipelineStage {
    id: string;
    name: string;
    probability: number;
    position: number;
    won: boolean;
    lost: boolean;
}

export interface Pipeline extends Option {
    stages: PipelineStage[];
}

export interface Deal {
    id: string;
    title: string;
    status: DealStatus;
    amount: number;
    currency: string;
    expectedCloseDate: string | null;
    wonAt: string | null;
    lostAt: string | null;
    lostReason: string | null;
    description: string | null;
    pipeline: Option;
    stage: Option;
    lead: { id: string; title: string } | null;
    contact: Option | null;
    company: Option | null;
    owner: OwnerOption | null;
    editable: boolean;
    assignable: boolean;
    createdAt: string;
}

export interface DealListProps {
    deals: Paginated<Deal>;
    filters: { search: string; status: DealStatus | null; pipeline: string | null; stage: string | null; segment: string | null };
    segments: Option[];
    pipelines: Pipeline[];
    leads: Option[];
    contacts: Option[];
    companies: Option[];
    owners: OwnerOption[];
    canCreate: boolean;
}

export interface LeadDealSummary {
    id: string;
    title: string;
    status: DealStatus;
    amount: number;
    currency: string;
    stage: string | null;
}

export interface DealStageHistory {
    id: string;
    from: string | null;
    to: string;
    note: string | null;
    actor: string | null;
    changedAt: string;
}

export interface Activity {
    id: string;
    type: ActivityType;
    status: ActivityStatus;
    priority: ActivityPriority;
    subject: string;
    description: string | null;
    startsAt: string | null;
    dueAt: string | null;
    remindAt: string | null;
    completedAt: string | null;
    lead: { id: string; title: string } | null;
    deal: { id: string; title: string } | null;
    contact: Option | null;
    company: Option | null;
    owner: OwnerOption | null;
    editable: boolean;
    createdAt: string;
}

export interface ActivityListProps {
    activities: Paginated<Activity>;
    filters: { search: string; status: ActivityStatus | null; type: ActivityType | null; overdue: boolean; segment: string | null };
    segments: Option[];
    leads: Option[];
    deals: Option[];
    contacts: Option[];
    companies: Option[];
    owners: OwnerOption[];
    canCreate: boolean;
}

export interface SettingsPipeline extends Pipeline {
    default: boolean;
    active: boolean;
}

export interface LeadSourceSetting extends Option {
    description: string | null;
    active: boolean;
    position: number;
}

export interface CrmTag extends Option {
    colour: string | null;
}

export interface CustomFieldSetting {
    id: string;
    entityType: 'lead' | 'deal' | 'activity' | 'contact' | 'company';
    key: string;
    name: string;
    type: 'text' | 'number' | 'boolean' | 'date' | 'select';
    required: boolean;
    options: string[] | null;
    active: boolean;
    position: number;
}

export interface SegmentSetting {
    id: string;
    name: string;
    entityType: 'lead' | 'deal' | 'activity' | 'contact' | 'company';
    filters: Record<string, string | number | boolean | null>;
    active: boolean;
}

export interface Product {
    id: string; type: CatalogItemType; sku: string | null; name: string; description: string | null; unit: string;
    currency: string; unitPriceAmount: number; taxBps: number; active: boolean; createdAt: string;
}

export interface PriceListItem {
    id: string; product: { id: string; name: string; sku: string | null; unit: string };
    minimumQuantityMilli: number; unitPriceAmount: number;
}

export interface PriceList extends Option {
    currency: string; startsOn: string | null; endsOn: string | null; active: boolean; items: PriceListItem[];
}

export interface QuoteLine {
    id: string; productId: string | null; position: number; itemType: CatalogItemType; sku: string | null; name: string;
    unit: string; description: string | null; quantityMilli: number; currency: string; unitPriceAmount: number;
    discountAmount: number; taxBps: number; taxAmount: number; lineTotalAmount: number;
}

export interface QuoteSummary {
    id: string; number: string; kind: QuoteKind; title: string; status: QuoteStatus; currency: string;
    subtotalAmount: number; discountAmount: number; taxAmount: number; totalAmount: number; validUntil: string | null;
    deal: Option | null; contact: Option | null; company: Option | null; owner: Option | null; editable: boolean; acceptable: boolean; rejectable: boolean; cancelable: boolean; createdAt: string;
}

export interface Quote extends QuoteSummary {
    priceListId: string | null; probabilityPercent: number; terms: string | null; notes: string | null;
    sentAt: string | null; acceptedAt: string | null; rejectedAt: string | null;
    salesOrder: { id: string; number: string; status: SalesOrderStatus } | null; lines: QuoteLine[];
}

export interface QuoteProduct extends Omit<Product, 'active' | 'createdAt'> {}
export interface QuoteDealOption extends Option { contactId: string | null; companyId: string | null; currency: string }
export interface QuoteFormOptions { canSelectDeals: boolean; deals: QuoteDealOption[]; products: QuoteProduct[]; priceLists: Array<Option & { currency: string }>; contacts: Option[]; companies: Option[] }

export interface SalesOrderSummary {
    id: string; number: string; status: SalesOrderStatus; currency: string; subtotalAmount: number; discountAmount: number;
    taxAmount: number; totalAmount: number; quote: { id: string; number: string } | null; contact: Option | null;
    company: Option | null; owner: Option | null; orderedAt: string; editable: boolean;
}

export interface SalesOrder extends SalesOrderSummary {
    terms: string | null; notes: string | null; fulfilledAt: string | null; canceledAt: string | null; lines: QuoteLine[];
}

export interface CustomerProfile {
    id: string; stage: CustomerLifecycleStage; contact: (Option & { email: string | null }) | null;
    company: (Option & { email: string | null }) | null; lifetimeValues: Record<string, number>;
    firstWonAt: string | null; lastWonAt: string | null; lastActivityAt: string | null; nextFollowUpAt: string | null;
    owner: Option | null; editable: boolean;
}

export interface Contract {
    id: string; number: string; title: string; status: ContractStatus; currency: string; valueAmount: number;
    startsOn: string; endsOn: string | null; renewsOn: string | null; signedAt: string | null; terminatedAt: string | null;
    terms: string | null; salesOrder: { id: string; number: string } | null; contact: Option | null; company: Option | null;
    owner: Option | null; editable: boolean; statusable: boolean;
}
export interface ContractFormOptions {
    orders: Array<{ id: string; number: string; contactId: string | null; companyId: string | null; currency: string; totalAmount: number }>;
    contacts: Option[]; companies: Option[];
}
export interface SalesTarget {
    id: string; name: string; currency: string; targetAmount: number; actualAmount: number; attainmentBps: number;
    periodStart: string; periodEnd: string; owner: Option | null; editable: boolean;
}
