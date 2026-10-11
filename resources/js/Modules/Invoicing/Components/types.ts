export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'overdue' | 'void';
export type PaymentMethod = 'cash' | 'bank_transfer' | 'mobile_banking' | 'cheque' | 'card' | 'other';
export type RecurringFrequency = 'weekly' | 'monthly' | 'quarterly' | 'yearly';
export type RecurringStatus = 'active' | 'paused' | 'ended';

export interface Option {
    id: string;
    name: string;
    email: string | null;
}

export interface TaxRate {
    id: string;
    name: string;
    rateBps: number;
    isDefault: boolean;
    isActive: boolean;
}

/** What every invoice, recurring and billable-time form receives from the server. */
export interface InvoiceFormOptions {
    contacts: Option[];
    companies: Option[];
    taxRates: TaxRate[];
    currencies: string[];
    defaults: {
        currency: string | null;
        dueDays: number | null;
        terms: string | null;
        notes: string | null;
    };
}

export interface InvoiceSummary {
    id: string;
    number: string | null;
    status: InvoiceStatus;
    title: string | null;
    reference: string | null;
    currency: string;
    issuedOn: string | null;
    dueOn: string | null;
    totalAmount: number;
    paidAmount: number;
    creditedAmount: number;
    balanceAmount: number;
    customer: { name: string | null; contactId: string | null; companyId: string | null };
    owner: { id: string; name: string } | null;
    sentAt: string | null;
    source: { type: string; id: string } | null;
    createdAt: string;
}

export interface InvoiceLine {
    id: string;
    position: number;
    name: string;
    description: string | null;
    unit: string | null;
    quantityMilli: number;
    unitPriceAmount: number;
    grossAmount: number;
    discountBps: number | null;
    discountAmount: number;
    netAmount: number;
    taxRateId: string | null;
    taxName: string | null;
    taxBps: number;
    taxAmount: number;
    lineTotalAmount: number;
}

export interface InvoicePayment {
    id: string;
    amount: number;
    currency: string;
    method: PaymentMethod;
    reference: string | null;
    paidOn: string;
    notes: string | null;
    recordedBy: string | null;
    reversedAt: string | null;
    reversalReason: string | null;
    createdAt: string;
}

export interface InvoiceCreditNote {
    id: string;
    number: string;
    issuedOn: string;
    reason: string | null;
    subtotalAmount: number;
    taxAmount: number;
    totalAmount: number;
    currency: string;
}

export interface InvoiceDetail extends InvoiceSummary {
    contactId: string | null;
    companyId: string | null;
    subtotalAmount: number;
    discountAmount: number;
    taxAmount: number;
    snapshot: {
        name: string | null;
        contactName: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
        taxId: string | null;
    } | null;
    customerEmail: string | null;
    seller: Record<string, string | null> | null;
    terms: string | null;
    notes: string | null;
    issuedAt: string | null;
    paidAt: string | null;
    overdueAt: string | null;
    voidedAt: string | null;
    voidReason: string | null;
    recurringTemplateId: string | null;
    lines: InvoiceLine[];
    payments: InvoicePayment[];
    creditNotes: InvoiceCreditNote[];
}

export interface InvoiceAbilities {
    edit: boolean;
    delete: boolean;
    issue: boolean;
    send: boolean;
    void: boolean;
    recordPayment: boolean;
    reversePayment: boolean;
    issueCreditNote: boolean;
}

/** A line as the editor holds it: text the person typed, never floats. */
export interface EditableLine {
    key: string;
    name: string;
    description: string;
    unit: string;
    quantity: string;
    unitPrice: string;
    discountMode: 'none' | 'amount' | 'percent';
    discount: string;
    /** '' = no tax, 'custom' = a rate typed by hand, otherwise a workspace tax rate id. */
    taxRateId: string;
    taxPercent: string;
    taxName: string;
}

/** The server's snake_case line shape, as sent on save and as stored on recurring templates. */
export interface LinePayload {
    name: string;
    description: string | null;
    unit: string | null;
    quantity_milli: number;
    unit_price_amount: number;
    discount_amount: number | null;
    discount_bps: number | null;
    tax_rate_id: string | null;
    tax_bps: number | null;
    tax_name: string | null;
}

export interface StoredLine {
    name: string;
    description: string | null;
    unit: string | null;
    quantity_milli: number;
    unit_price_amount: number;
    discount_bps: number | null;
    discount_amount: number | null;
    tax_name: string | null;
    tax_bps: number | null;
}

export interface RecurringTemplate {
    id: string;
    name: string;
    status: RecurringStatus;
    frequency: RecurringFrequency;
    every: number;
    currency: string;
    startsOn: string;
    nextRunOn: string | null;
    endsOn: string | null;
    lastRunOn: string | null;
    runsCount: number;
    lastError: string | null;
    dueDays: number;
    autoIssue: boolean;
    autoSend: boolean;
    title: string | null;
    reference: string | null;
    terms: string | null;
    notes: string | null;
    contactId: string | null;
    companyId: string | null;
    customer: { name: string | null };
    totalAmount: number;
    lines: StoredLine[];
    owner: { id: string; name: string } | null;
    editable: boolean;
}

export interface BillableProject {
    projectId: string;
    currency: string;
    minutes: number;
    entries: number;
    estimatedAmount: number;
}

export interface ReceivablesReport {
    asOf: string;
    ageing: { currency: string; invoices: number; outstanding: number; buckets: Record<string, number> }[];
    byCustomer: {
        contactId: string | null;
        companyId: string | null;
        name: string;
        currency: string;
        invoices: number;
        outstanding: number;
        oldestDueOn: string | null;
    }[];
    collected: { currency: string; payments: number; amount: number }[];
    collectedByMethod: { currency: string; method: string; payments: number; amount: number }[];
    invoiced: { currency: string; invoices: number; total: number; tax: number; credited: number }[];
}

export interface InvoicingSettings {
    invoicePrefix: string;
    creditNotePrefix: string;
    numberPadding: number;
    fiscalYearNumbering: boolean;
    fiscalYearStartMonth: number;
    defaultDueDays: number;
    defaultTerms: string | null;
    defaultNotes: string | null;
    remindersEnabled: boolean;
    reminderBeforeDays: number;
    reminderOverdueEveryDays: number;
    invoiceAcceptedQuotes: boolean;
}
