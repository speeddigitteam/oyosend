import type { Paginated } from '@/Core/types';

export type { Paginated };

export type OpeningStatus = 'draft' | 'open' | 'on_hold' | 'closed';
export type ApplicationStatus = 'open' | 'hired' | 'rejected' | 'withdrawn';
export type StageType = 'active' | 'hired' | 'rejected';
export type ApplicationEvent = 'applied' | 'moved' | 'rejected' | 'hired' | 'withdrawn' | 'reopened';
export type InterviewStatus = 'scheduled' | 'completed' | 'cancelled' | 'no_show';
export type InterviewMode = 'in_person' | 'video' | 'phone';
export type InterviewOutcome = 'advance' | 'hold' | 'reject';
export type Recommendation = 'strong_no' | 'no' | 'yes' | 'strong_yes';
export type OfferStatus = 'draft' | 'sent' | 'accepted' | 'declined' | 'withdrawn';
export type ChecklistKind = 'onboarding' | 'offboarding';
export type ChecklistStatus = 'open' | 'completed' | 'cancelled';
export type TaskStatus = 'pending' | 'done' | 'skipped';
export type AssigneeRole = 'employee' | 'manager' | 'hr' | 'person';
export type ProbationStatus = 'in_progress' | 'confirmed' | 'ended';
export type ProbationDecision = 'confirm' | 'extend' | 'end';
export type CycleStatus = 'draft' | 'active' | 'calibration' | 'closed';
export type ReviewStatus = 'pending_self' | 'pending_manager' | 'pending_calibration' | 'calibrated' | 'acknowledged';
export type RatingParty = 'self' | 'manager';
export type GoalKind = 'goal' | 'kpi';
export type GoalStatus = 'active' | 'achieved' | 'missed' | 'cancelled';
export type EnrolmentStatus = 'enrolled' | 'completed' | 'failed' | 'cancelled';
export type SessionStatus = 'scheduled' | 'completed' | 'cancelled';
export type ConsentBasis = 'candidate_provided' | 'applied_directly' | 'consent_form' | 'referral';
export type DocumentType = 'resume' | 'cover_letter' | 'portfolio' | 'other';
export type EmploymentType = 'full_time' | 'part_time' | 'contract' | 'intern' | 'consultant';

export interface NamedRef { id: string; name: string }
export interface PersonRef { id: string; number: string; name: string }
export interface PersonOption { id: string; number: string; name: string; department: string | null }
export interface Stage { id: string; name: string; type: StageType; pipelineId?: string; position?: number }
export interface PipelineDetail { id: string; name: string; isDefault: boolean; isActive: boolean; stages: Stage[] }

export interface OpeningRow {
    id: string;
    title: string;
    status: OpeningStatus;
    employmentType: EmploymentType | null;
    location: string | null;
    headcount: number;
    hired: number;
    applications: number;
    department: NamedRef | null;
    branch: NamedRef | null;
    hiringManager: PersonRef | null;
    pipelineId: string;
    description: string | null;
    requirements: string | null;
    opensOn: string | null;
    closesOn: string | null;
    openedAt: string | null;
    closedAt: string | null;
    createdAt: string | null;
}

export interface OpeningForm {
    departments: NamedRef[];
    branches: NamedRef[];
    hiringManagers: PersonOption[];
    pipelines: NamedRef[];
    employmentTypes: EmploymentType[];
    defaultPipelineId: string;
    canChangePipeline: boolean;
}

export interface CandidateRow {
    id: string;
    name: string | null;
    firstName: string | null;
    lastName: string | null;
    email: string | null;
    phone: string | null;
    headline: string | null;
    currentCompany: string | null;
    location: string | null;
    source: string | null;
    isAnonymized: boolean;
    retainUntil: string | null;
    createdAt: string | null;
    applications?: number;
    openApplications?: number;
}

export interface CandidateDetail extends CandidateRow {
    summary: string | null;
    expectedAmount: number | null;
    currency: string | null;
    consentBasis: ConsentBasis;
    consentGivenAt: string | null;
    anonymizedAt: string | null;
}

export interface CandidateDocument {
    id: string;
    type: DocumentType;
    title: string;
    originalName: string;
    mimeType: string;
    size: number;
    applicationId: string | null;
    uploadedAt: string | null;
}

export interface TalentNote { id: string; body: string; applicationId: string | null; authorId: string | null; createdAt: string | null }

export interface ApplicationRow {
    id: string;
    status: ApplicationStatus;
    appliedOn: string;
    source: string | null;
    stage: { id: string; name: string; type: StageType };
    opening: { id: string; title: string; status: OpeningStatus };
    candidate: {
        id: string;
        name: string | null;
        headline: string | null;
        isAnonymized: boolean;
        email: string | null;
        phone: string | null;
    };
    rejectionReasonId: string | null;
    rejectionNote: string | null;
    startOn: string | null;
    hiredAt: string | null;
    statusChangedAt: string | null;
}

export interface HistoryRow { id: string; event: ApplicationEvent; from: string | null; to: string | null; note: string | null; at: string | null }

export interface Offer {
    id: string;
    applicationId: string;
    status: OfferStatus;
    amount: number | null;
    currency: string | null;
    proposedStartOn: string | null;
    expiresOn: string | null;
    sentAt: string | null;
    decidedAt: string | null;
    notes: string | null;
}

export interface Scorecard {
    employee: PersonRef | null;
    rating: number;
    recommendation: Recommendation;
    strengths: string | null;
    concerns: string | null;
    submittedAt: string;
}

export interface InterviewRow {
    id: string;
    applicationId: string;
    roundName: string;
    mode: InterviewMode;
    status: InterviewStatus;
    outcome: InterviewOutcome | null;
    startsAt: string;
    endsAt: string;
    timezone: string;
    date: string;
    time: string;
    endTime: string;
    durationMinutes: number;
    location: string | null;
    notes: string | null;
    panel: PersonRef[];
    scorecards: Scorecard[];
    scorecardCount: number;
    abilities: { edit: boolean; cancel: boolean; complete: boolean; feedback: boolean };
    candidate?: { id: string; name: string | null };
    opening?: { id: string; title: string };
}

export interface ChecklistRow {
    id: string;
    name: string;
    kind: ChecklistKind;
    status: ChecklistStatus;
    employee: PersonRef | null;
    anchorDate: string;
    completedAt: string | null;
    notes: string | null;
    taskCount: number;
    doneCount: number;
}

export interface ChecklistTask {
    id: string;
    checklistId: string;
    title: string;
    description: string | null;
    assigneeRole: AssigneeRole;
    assignee: PersonRef | null;
    dueOn: string | null;
    status: TaskStatus;
    completedAt: string | null;
    note: string | null;
    canComplete?: boolean;
    canSkip?: boolean;
    checklistName?: string;
    checklistKind?: ChecklistKind;
    subject?: PersonRef | null;
}

export interface TemplateTask {
    id: string;
    title: string;
    description: string | null;
    assigneeRole: AssigneeRole;
    assignee: PersonRef | null;
    assigneeEmployeeId: string | null;
    dueOffsetDays: number | null;
}

export interface Template { id: string; name: string; kind: ChecklistKind; isDefault: boolean; isActive: boolean; tasks: TemplateTask[] }

export interface ProbationReviewRow { id: string; decision: ProbationDecision; extendUntil: string | null; note: string | null; decidedOn: string }

export interface ProbationRow {
    id: string;
    employee: PersonRef | null;
    status: ProbationStatus;
    startedOn: string;
    endsOn: string;
    closedOn: string | null;
    reviews: ProbationReviewRow[];
    daysLeft: number | null;
    isOverdue?: boolean;
}

export interface FormItem { id: string; name: string; description: string | null; weightBp: number; position: number }
export interface ReviewFormDetail { id: string; name: string; description: string | null; scaleMax: number; isActive: boolean; items: FormItem[] }

export interface CycleRow {
    id: string;
    name: string;
    status: CycleStatus;
    formId: string;
    startsOn: string;
    endsOn: string;
    selfDueOn: string | null;
    managerDueOn: string | null;
    requiresSelfReview: boolean;
    departmentId: string | null;
    launchedAt: string | null;
    closedAt: string | null;
    reviews: number;
}

export interface CycleCompletion {
    id: string;
    name: string;
    status: CycleStatus;
    total: number;
    byStatus: Record<string, number>;
    completionBp: number;
    isOpen: boolean;
}

export interface ReviewRow {
    id: string;
    cycleId: string;
    employee: PersonRef | null;
    reviewer: PersonRef | null;
    status: ReviewStatus;
    selfScore: number | null;
    managerScore: number | null;
    finalScore: number | null;
    finalOverridden: boolean;
    selfSubmittedAt: string | null;
    managerSubmittedAt: string | null;
    calibratedAt: string | null;
    acknowledgedAt: string | null;
    scoreText: { self: string | null; manager: string | null; final: string | null };
    cycle?: { id: string; name: string; status: CycleStatus };
    role?: 'subject' | 'reviewer';
    awaitingMe?: boolean;
}

export interface ReviewDetail extends ReviewRow {
    selfComment: string | null;
    managerComment: string | null;
    calibrationNote: string | null;
    employeeComment: string | null;
}

export interface RatingRow { itemId: string; party: RatingParty; rating: number; comment: string | null }

export interface Goal {
    id: string;
    employee: PersonRef | null;
    cycleId: string | null;
    title: string;
    description: string | null;
    kind: GoalKind;
    unit: string | null;
    startValue: number;
    targetValue: number;
    currentValue: number;
    progressBp: number;
    status: GoalStatus;
    dueOn: string | null;
    achievedAt: string | null;
}

export interface GoalUpdateRow { id: string; value: number; note: string | null; recordedOn: string; recordedAt: string | null }

export interface Course {
    id: string;
    title: string;
    code: string | null;
    provider: string | null;
    description: string | null;
    durationMinutes: number | null;
    validityMonths: number | null;
    isMandatory: boolean;
    isActive: boolean;
    upcomingSessions?: number;
}

export interface TrainingSession {
    id: string;
    courseId: string;
    status: SessionStatus;
    startsAt: string;
    endsAt: string;
    timezone: string;
    date: string;
    time: string;
    location: string | null;
    trainer: string | null;
    capacity: number | null;
    enrolled: number | null;
}

export interface Enrolment {
    id: string;
    course: { id: string; title?: string; validityMonths?: number | null };
    sessionId: string | null;
    employee: PersonRef | null;
    status: EnrolmentStatus;
    enrolledOn: string;
    completedOn: string | null;
    score: number | null;
    expiresOn: string | null;
    notes: string | null;
}

export interface TalentSettings {
    defaultProbationDays: number;
    probationReminderDays: number;
    certificationReminderDays: number;
    candidateRetentionMonths: number;
}

export interface RejectionReason { id: string; name: string; isActive?: boolean }

export interface Recruitment {
    applications: { total: number; open: number; hired: number; rejected: number; withdrawn: number; hireRateBp: number };
    funnel: { stage: string; type: StageType; position: number; reached: number }[];
    openings: { open: number; onHold: number; closed: number; draft: number };
    timeToFillDays: number | null;
    timeToHireDays: number | null;
}

export interface Workforce {
    months: { month: string; headcount: number; joiners: number; leavers: number; attritionBp: number }[];
    leavers: number;
    attritionBp: number;
    headcount: number;
}

export interface TrainingStats {
    enrolments: { enrolled: number; completed: number; failed: number; cancelled: number };
    completionRateBp: number;
    courses: number;
    certificatesExpiring: number;
    certificatesExpired: number;
}
