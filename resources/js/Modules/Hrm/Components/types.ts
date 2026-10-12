import type { Paginated } from '@/Core/types';

export type { Paginated };

export type EmployeeStatus = 'probation' | 'active' | 'on_leave' | 'suspended' | 'terminated';
export type EmploymentType = 'full_time' | 'part_time' | 'contract' | 'intern' | 'consultant';
export type DayStatus = 'present' | 'half_day' | 'absent' | 'on_leave' | 'holiday' | 'weekly_off' | 'incomplete';
export type LeaveStatus = 'pending_manager' | 'pending_hr' | 'approved' | 'rejected' | 'cancelled';
export type CorrectionStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export type LeaveSession = 'full' | 'first_half' | 'second_half';
export type ApprovalFlow = 'manager_then_hr' | 'manager_only' | 'hr_only';
export type Accrual = 'annual' | 'monthly';
export type HolidayType = 'public' | 'optional';
export type DocumentType = 'contract' | 'identity' | 'certificate' | 'offer' | 'other';
export type TerminationType = 'resignation' | 'dismissal' | 'end_of_contract' | 'retirement' | 'redundancy' | 'other';
export type Gender = 'female' | 'male' | 'other';
export type HistoryEvent = 'joined' | 'status_changed' | 'updated' | 'terminated' | 'reinstated' | 'membership_linked';

export interface NamedRef { id: string; name: string }
export interface PersonRef { id: string; name: string; number: string }

export interface EmployeeRow {
    id: string;
    number: string;
    name: string;
    firstName: string;
    lastName: string | null;
    status: EmployeeStatus;
    employmentType: EmploymentType;
    designation: NamedRef | null;
    department: NamedRef | null;
    branch: NamedRef | null;
    managerId: string | null;
    managerName: string | null;
    joinedOn: string;
    terminatedOn: string | null;
    workEmail: string | null;
    phone: string | null;
    hasAccount: boolean;
}

export interface EmployeePersonal {
    personalEmail: string | null;
    dateOfBirth: string | null;
    gender: Gender | null;
    nationalId: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
}

export interface EmployeeDetail extends EmployeeRow {
    probationEndsOn: string | null;
    confirmedOn: string | null;
    statusChangedOn: string | null;
    statusReason: string | null;
    lastWorkingOn: string | null;
    terminationType: TerminationType | null;
    terminationReason: string | null;
    membership: { id: string; name: string; email: string } | null;
    notes: string | null;
    costRateAmount: number | null;
    costCurrency: string | null;
    personal: EmployeePersonal | null;
}

export interface PersonOption { id: string; number: string; name: string }
export interface MemberOption { id: string; name: string; email: string }

export interface EmployeeFormOptions {
    departments: NamedRef[];
    branches: NamedRef[];
    designations: NamedRef[];
    managers: PersonOption[];
    members: MemberOption[];
    employmentTypes: EmploymentType[];
    genders: Gender[];
}

export interface EmergencyContact {
    id: string;
    name: string;
    relationship: string;
    phone: string;
    email: string | null;
    address: string | null;
    isPrimary: boolean;
}

export interface EmployeeDocument {
    id: string;
    type: DocumentType;
    title: string;
    originalName: string;
    mimeType: string;
    size: number;
    expiresOn: string | null;
    uploadedAt: string | null;
}

export interface EmployeeAsset {
    id: string;
    name: string;
    assetTag: string | null;
    category: string | null;
    assignedOn: string;
    returnedOn: string | null;
    returnCondition: string | null;
    notes: string | null;
    isHeld: boolean;
}

export interface HistoryEntry {
    id: string;
    event: HistoryEvent;
    effectiveOn: string;
    changes: Record<string, unknown> | null;
    reason: string | null;
    recordedAt: string | null;
}

export interface Shift {
    id: string;
    name: string;
    startsAt: string;
    endsAt: string;
    crossesMidnight: boolean;
    breakMinutes: number;
    lateGraceMinutes: number;
    earlyGraceMinutes: number;
    overtimeThresholdMinutes: number;
    halfDayMinutes: number | null;
    scheduledMinutes: number;
    weeklyOffDays: number[] | null;
    isDefault: boolean;
    isActive: boolean;
}

export interface ShiftAssignment {
    id: string;
    employeeId: string;
    shiftId: string;
    shiftName: string | null;
    startsOn: string;
    endsOn: string | null;
}

export interface AttendanceDay {
    id: string;
    date: string;
    status: DayStatus;
    scheduledMinutes: number;
    workedMinutes: number;
    lateMinutes: number;
    earlyLeaveMinutes: number;
    overtimeMinutes: number;
    firstInAt: string | null;
    lastOutAt: string | null;
    entriesCount: number;
    leaveUnits: number;
    isClosed: boolean;
    revision: number;
}

export interface AttendanceEntry {
    id: string;
    date: string;
    clockInAt: string;
    clockOutAt: string | null;
    source: 'clock' | 'manual' | 'regularization' | 'device';
    note: string | null;
}

export interface Correction {
    id: string;
    employee: PersonRef;
    date: string;
    clockInAt: string;
    clockOutAt: string;
    reason: string;
    status: CorrectionStatus;
    decisionNote: string | null;
    decidedAt: string | null;
    requestedAt: string | null;
    abilities?: { decide: boolean; cancel: boolean };
}

export interface LeaveType {
    id: string;
    name: string;
    code: string;
    isPaid: boolean;
    tracksBalance: boolean;
    allowsHalfDay: boolean;
    requiresReason: boolean;
    maxConsecutiveDays: number | null;
    minNoticeDays: number;
    approvalFlow: ApprovalFlow;
    isActive: boolean;
    sortOrder: number;
}

export interface LeavePolicy {
    id: string;
    leaveTypeId: string;
    name: string;
    employmentTypes: EmploymentType[] | null;
    accrual: Accrual;
    annualUnits: number;
    carryForwardMaxUnits: number;
    maxBalanceUnits: number | null;
    maxUnitsPerRequest: number | null;
    sandwichRule: boolean;
    minServiceDays: number;
    isActive: boolean;
}

export interface Designation {
    id: string;
    name: string;
    code: string | null;
    description: string | null;
    isActive: boolean;
}

export interface LeaveApprovalRow {
    step: 'manager' | 'hr';
    decision: string;
    note: string | null;
    decidedAt: string;
}

export interface LeaveRequest {
    id: string;
    employee: PersonRef;
    leaveType: { id: string; name: string; code: string; isPaid: boolean };
    startsOn: string;
    endsOn: string;
    startSession: LeaveSession;
    endSession: LeaveSession;
    units: number;
    days: Record<string, number>;
    leaveYear: number;
    status: LeaveStatus;
    reason: string | null;
    requestedAt: string | null;
    decidedAt: string | null;
    cancelledAt: string | null;
    cancellationReason: string | null;
    approvals: LeaveApprovalRow[];
    abilities?: { decide?: boolean; step?: 'manager' | 'hr' | null; cancel: boolean };
}

export interface Holiday { id: string; name: string; date: string; type: HolidayType }

export interface HrmSettings {
    employeePrefix: string;
    employeePadding: number;
    weeklyOffDays: number[];
    leaveYearStartMonth: number;
    workDayMinutes: number;
}

export interface AttendanceTotals {
    present: number;
    halfDay: number;
    absent: number;
    onLeave: number;
    workedMinutes: number;
    lateMinutes: number;
    earlyLeaveMinutes: number;
    overtimeMinutes: number;
}

export interface AttendanceSummaryRow {
    employeeId: string;
    employeeNumber: string | null;
    name: string | null;
    present: number;
    halfDay: number;
    absent: number;
    onLeave: number;
    incomplete: number;
    lateDays: number;
    scheduledMinutes: number;
    workedMinutes: number;
    lateMinutes: number;
    earlyLeaveMinutes: number;
    overtimeMinutes: number;
}

export interface GroupCount { id: string | null; name: string | null; count: number }

export interface Headcount {
    total: number;
    by_status: Record<string, number>;
    by_employment_type: Record<string, number>;
    by_department: GroupCount[];
    by_branch: GroupCount[];
    by_designation: GroupCount[];
    joined: number;
    left: number;
    from: string;
    to: string;
}

export interface LeaveReport {
    year: number;
    by_type: { leave_type_id: string; name: string; code: string; granted: number; used: number; pending: number; balance: number }[];
    by_department: { department_id: string | null; name: string | null; units: number }[];
}

export interface ClockState {
    clockedIn: boolean;
    since: string | null;
    day: AttendanceDay | null;
    shift: Shift | null;
    isWorkingDay: boolean;
    holiday: string | null;
}
