import type { CanonicalIntegerString } from '@/Core/lib/format';

export type ProjectStatus = 'planned' | 'active' | 'on_hold' | 'completed' | 'cancelled';
export type TaskStatus = 'backlog' | 'todo' | 'in_progress' | 'review' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';
export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export type EntryStatus = 'draft' | 'submitted' | 'approved' | 'rejected';
export type MilestoneStatus = 'open' | 'completed' | 'cancelled';
export type ProjectView = 'board' | 'list' | 'calendar' | 'gantt';

export interface Option {
    id: string;
    name: string;
    parentId?: string | null;
}

export interface ProjectTaskOption extends Option {
    projectId: string;
}

export interface TaskOptionsPagination {
    currentPage: number;
    perPage: number;
    total: number;
    lastPage: number;
    hasMorePages: boolean;
    previousPageUrl: string | null;
    nextPageUrl: string | null;
}

export interface TaskOptionsResponse {
    tasks: ProjectTaskOption[];
    pagination: TaskOptionsPagination;
    filters: { projectId: string; search: string };
}

export interface Member extends Option {
    avatarUrl?: string | null;
    capacityMinutes?: number;
    allocatedMinutes?: number;
    allocationPercent?: number;
}

export interface ChecklistItem {
    id: string;
    label: string;
    completed: boolean;
}

export interface TaskComment {
    id: string;
    body: string;
    author: string;
    internal: boolean;
    client: boolean;
    createdAt: string;
}

export interface TaskRecurrence {
    frequency: 'daily' | 'weekly' | 'monthly';
    interval?: number | null;
    until?: string | null;
}

export interface ProjectAttachment {
    id: string;
    name: string;
    mimeType: string;
    size: number;
    uploader?: string | null;
    createdAt: string;
    deletable: boolean;
}

export interface Task {
    id: string;
    title: string;
    project?: Option | null;
    description?: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    startsAt?: string | null;
    dueAt?: string | null;
    remindAt?: string | null;
    startsLocal?: string | null;
    dueLocal?: string | null;
    remindLocal?: string | null;
    startDate?: string | null;
    dueDate?: string | null;
    estimateMinutes?: number | null;
    trackedMinutes?: number | null;
    assignees: Member[];
    parentId?: string | null;
    sprint?: Option | null;
    milestone?: Option | null;
    checklist?: ChecklistItem[];
    subtasks?: Task[];
    dependencies?: Option[];
    comments?: TaskComment[];
    attachments?: ProjectAttachment[];
    recurrenceRule?: TaskRecurrence | null;
    watching?: boolean;
    editable?: boolean;
    assignable?: boolean;
    commentable?: boolean;
    approvable?: boolean;
    approvalPending?: boolean;
    approvalRequestable?: boolean;
}

export interface Milestone {
    id: string;
    name: string;
    dueDate: string | null;
    completed: boolean;
    taskCount: number;
    completedTaskCount: number;
    status?: MilestoneStatus;
    approvalPending?: boolean;
    approvalRequestable?: boolean;
}

export interface Project {
    id: string;
    name: string;
    description?: string | null;
    status: ProjectStatus;
    priority: TaskPriority;
    category?: Option | null;
    owner?: Member | null;
    contact?: Option | null;
    company?: Option | null;
    startsOn?: string | null;
    dueOn?: string | null;
    progress: number;
    currency: string;
    budgetAmount?: number | null;
    spentAmount?: CanonicalIntegerString;
    billableAmount?: CanonicalIntegerString;
    members?: Member[];
    openTasks?: number;
    editable?: boolean;
    taskCreatable?: boolean;
}

export interface TimeEntry {
    id: string;
    project: Option;
    task?: Option | null;
    member: Member;
    date: string;
    minutes: number;
    billable: boolean;
    note?: string | null;
    status?: EntryStatus;
    approvable?: boolean;
    submittable?: boolean;
    approvalPending?: boolean;
    approvalRequestable?: boolean;
}

export interface Expense {
    id: string;
    project: Option;
    date: string;
    amount: number;
    currency: string;
    description: string;
    status: EntryStatus;
    submittable?: boolean;
    approvable?: boolean;
    approvalPending?: boolean;
    approvalRequestable?: boolean;
}

export interface Approval {
    id: string;
    type: 'task' | 'time_entry' | 'expense' | 'milestone';
    title: string;
    project: Option;
    requester: Member;
    approver: Member;
    requestNote?: string | null;
    decisionNote?: string | null;
    requestedAt: string;
    status: ApprovalStatus;
    actionable: boolean;
}

export interface ProjectFinancials {
    currency: string;
    budgetAmount: CanonicalIntegerString;
    billableAmount: CanonicalIntegerString;
    labourCostAmount: CanonicalIntegerString;
    expenseAmount: CanonicalIntegerString;
    spentAmount: CanonicalIntegerString;
    budgetRemainingAmount: CanonicalIntegerString;
    projectedProfitAmount: CanonicalIntegerString;
}

export interface ExpenseSummary {
    currency: string;
    totalAmount: CanonicalIntegerString;
    pendingAmount: CanonicalIntegerString;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

export interface ProjectFormOptions {
    categories: Option[];
    templates: Array<Option & { categoryId?: string | null }>;
    owners: Member[];
    contacts: Option[];
    companies: Option[];
    currency: string;
}

export interface TaskFormOptions {
    members: Member[];
    milestones: Option[];
    sprints: Option[];
    parentTasks: Option[];
    canAssign?: boolean;
}

