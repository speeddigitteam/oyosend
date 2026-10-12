export type RunStatus = 'draft' | 'submitted' | 'approved';
export type RunKind = 'regular' | 'adjustment' | 'reversal';
export type PeriodStatus = 'open' | 'processing' | 'approved' | 'paid' | 'closed';
export type PeriodKind = 'monthly' | 'custom';
export type PaymentStatus = 'unpaid' | 'paid' | 'failed';
export type PaymentMethod = 'bank' | 'cash' | 'mobile_wallet' | 'cheque' | 'other';
export type LineKind = 'earning' | 'deduction' | 'reimbursement' | 'employer';
export type ItemType = 'allowance' | 'bonus' | 'festival_bonus' | 'deduction' | 'reimbursement';
export type ItemStatus = 'pending' | 'included' | 'cancelled';
export type LoanKind = 'loan' | 'advance';
export type LoanStatus = 'pending' | 'active' | 'completed' | 'cancelled' | 'rejected';
export type InstallmentStatus = 'pending' | 'deducted' | 'cancelled';

export interface EmployeeRef {
    id: string;
    number: string;
    name: string;
    department: string | null;
    designation: string | null;
    status: string;
}

export interface EmployeeOption {
    id: string;
    number: string;
    name: string;
    department: string | null;
}

export interface NamedOption {
    id: string;
    name: string;
}

export interface Period {
    id: string;
    name: string;
    kind: PeriodKind;
    startsOn: string;
    endsOn: string;
    payDate: string | null;
    status: PeriodStatus;
    closedAt: string | null;
}

/** One entry of a run's `errors` or `warnings`: a code plus whatever figures belong to it. */
export interface RunIssue {
    code: string;
    employee_id?: string;
    employee_name?: string;
    [key: string]: unknown;
}

export interface Run {
    id: string;
    number: string | null;
    kind: RunKind;
    status: RunStatus;
    periodId: string;
    periodName: string | null;
    reversesRunId: string | null;
    currency: string;
    employeeCount: number;
    grossAmount: number;
    deductionAmount: number;
    reimbursementAmount: number;
    netAmount: number;
    employerAmount: number;
    preparedById: string | null;
    submittedById: string | null;
    approvedById: string | null;
    submittedAt: string | null;
    approvedAt: string | null;
    isLocked: boolean;
    note: string | null;
    createdAt: string | null;
}

export interface RunDetail extends Run {
    preparedByName: string | null;
    approvedByName: string | null;
    errors: RunIssue[];
    warnings: RunIssue[];
    reversesRunNumber: string | null;
    isReversed: boolean;
}

export interface PayslipRow {
    id: string;
    number: string | null;
    runId: string;
    periodId: string;
    periodName: string | null;
    employeeId: string;
    employeeNumber: string;
    employeeName: string;
    departmentName: string | null;
    designationName: string | null;
    currency: string;
    basicAmount: number;
    grossAmount: number;
    deductionAmount: number;
    reimbursementAmount: number;
    netAmount: number;
    employerAmount: number;
    isReversal: boolean;
    isLocked: boolean;
    paymentStatus: PaymentStatus;
    paymentMethod: PaymentMethod | null;
    paymentReference: string | null;
    paidOn: string | null;
    warnings: RunIssue[];
}

export interface PayslipDetail extends PayslipRow {
    runNumber: string | null;
    runKind: RunKind;
    reversesPayslipId: string | null;
    payableDays: number;
    periodDays: number;
    taxableAmount: number;
    structureName: string | null;
    attendance: Record<string, unknown> | null;
    joinedOn: string;
    employmentType: string | null;
}

export interface PayslipLine {
    id: string;
    kind: LineKind;
    code: string;
    name: string;
    source: string;
    amount: number;
    isTaxable: boolean;
    detail: Record<string, unknown> | null;
}

export interface Payment {
    id: string;
    status: 'paid' | 'failed';
    method: PaymentMethod | null;
    reference: string | null;
    paidOn: string | null;
    amount: number;
    currency: string;
    note: string | null;
    createdAt: string | null;
}

export interface Structure {
    id: string;
    name: string;
    code: string | null;
    description: string | null;
    isActive: boolean;
    componentCount: number | null;
}

export interface Component {
    id: string;
    name: string;
    code: string;
    kind: 'earning' | 'deduction';
    calc: 'fixed' | 'percent_basic';
    amount: number | null;
    rateBps: number | null;
    isTaxable: boolean;
    sortOrder: number;
    effectiveFrom: string | null;
    effectiveTo: string | null;
    isActive: boolean;
}

export interface Assignment {
    id: string;
    revision: number;
    structureId: string;
    structureName: string | null;
    basicAmount: number;
    currency: string;
    effectiveFrom: string;
    reason: string | null;
    createdAt: string | null;
}

export interface Account {
    paymentMethod: PaymentMethod | null;
    accountHolder: string | null;
    bankName: string | null;
    bankBranch: string | null;
    routingNumber: string | null;
    accountNumberMasked: string | null;
    hasAccountNumber: boolean;
}

export interface Item {
    id: string;
    employeeId: string;
    periodId: string;
    periodName: string | null;
    type: ItemType;
    name: string;
    amount: number | null;
    basicBps: number | null;
    isTaxable: boolean;
    status: ItemStatus;
    payslipId: string | null;
    note: string | null;
    employee: EmployeeRef | null;
}

export interface Loan {
    id: string;
    employeeId: string;
    kind: LoanKind;
    title: string;
    principalAmount: number;
    installmentCount: number;
    firstDueOn: string;
    currency: string;
    status: LoanStatus;
    reason: string | null;
    decisionNote: string | null;
    requestedById: string | null;
    approvedById: string | null;
    decidedAt: string | null;
}

export interface LoanRow extends Loan {
    employee: EmployeeRef | null;
}

export interface Installment {
    id: string;
    sequence: number;
    dueOn: string;
    amount: number;
    status: InstallmentStatus;
    payslipId: string | null;
}

export interface Slab {
    position: number;
    from: number;
    to: number | null;
    rateBps: number;
    fixed: number;
}

export interface RuleSet {
    id: string;
    name: string;
    code: string;
    country: string | null;
    engine: string;
    category: 'income_tax' | 'social_contribution' | 'levy' | 'other';
    payer: 'employee' | 'employer';
    basis: 'taxable_gross' | 'gross' | 'basic';
    method: 'progressive' | 'banded';
    annualize: boolean;
    periodsPerYear: number;
    minAmount: number | null;
    maxAmount: number | null;
    employmentTypes: string[];
    effectiveFrom: string;
    effectiveTo: string | null;
    isActive: boolean;
    version: number;
    notes: string | null;
    slabs: Slab[];
}

export interface RuleOptions {
    engines: string[];
    categories: string[];
    payers: string[];
    bases: string[];
    methods: string[];
    employmentTypes: string[];
}

export interface ReportTotals {
    payslips: number;
    gross_amount: number;
    deduction_amount: number;
    reimbursement_amount: number;
    net_amount: number;
    employer_amount: number;
}

export interface DepartmentCostRow {
    department: string | null;
    employees: number;
    payslips: number;
    gross_amount: number;
    deduction_amount: number;
    reimbursement_amount: number;
    net_amount: number;
    employer_amount: number;
}

export interface DeductionRow {
    code: string;
    name: string;
    source: string;
    employees: number;
    amount: number;
}

export interface PayrollSettings {
    daysBasis: 'calendar_days' | 'fixed_days';
    fixedDays: number;
    dailyRateBase: 'basic' | 'gross';
    deductUnpaidLeave: boolean;
    deductAbsence: boolean;
    workDayMinutes: number;
    overtimeMultiplierBps: number;
    overtimeTaxable: boolean;
    maxOvertimeMinutes: number | null;
    requireSeparateApprover: boolean;
    payslipPrefix: string;
    runPrefix: string;
    numberPadding: number;
    payslipNote: string | null;
}
