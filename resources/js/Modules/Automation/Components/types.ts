import type { Issue, WireGraph } from '../lib/graph';
import type { JsonSchema } from '../lib/schema';

export type { Issue, WireGraph };

export interface Paginated<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    from?: number | null;
    to?: number | null;
    total?: number;
}

export type WorkflowStatus = 'draft' | 'active' | 'paused' | 'archived';
export type RunStatus = 'queued' | 'running' | 'waiting' | 'awaiting_approval' | 'succeeded' | 'failed' | 'cancelled';
export type RunSource = 'event' | 'webhook' | 'schedule' | 'manual' | 'test';
export type RunMode = 'live' | 'dry_run';
export type StepStatus = 'pending' | 'running' | 'waiting' | 'succeeded' | 'failed' | 'skipped' | 'cancelled';
export type VersionStatus = 'draft' | 'published' | 'archived';
export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'expired' | 'cancelled';

export interface WorkflowRow {
    id: string;
    name: string;
    description: string | null;
    status: WorkflowStatus;
    triggerKey: string | null;
    publishedVersionId: string | null;
    actor: { id: string; name: string | null } | null;
    hasWebhook: boolean;
    nextRunAt: string | null;
    lastRunAt: string | null;
    lastRunStatus: string | null;
    publishedAt: string | null;
    updatedAt: string | null;
}

export interface VersionRow {
    id: string;
    number: number;
    status: VersionStatus;
    hash: string | null;
    triggerKey: string | null;
    note: string | null;
    publishedBy: string | null;
    publishedAt: string | null;
    archivedAt: string | null;
    updatedAt: string | null;
    graph?: WireGraph;
}

export interface RunRow {
    id: string;
    workflowId: string;
    workflowName: string | null;
    versionNumber: number | null;
    mode: RunMode;
    source: RunSource;
    status: RunStatus;
    triggerKey: string | null;
    depth: number;
    parentRunId: string | null;
    startedBy: string | null;
    stepsExecuted: number;
    retryCount: number;
    errorCode: string | null;
    errorMessage: string | null;
    cancelRequested: boolean;
    resumeAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    createdAt: string | null;
}

export interface StepLog {
    [key: string]: unknown;
}

export interface StepRow {
    id: string;
    nodeId: string;
    nodeType: string;
    label: string | null;
    status: StepStatus;
    attempt: number;
    sequence: number;
    iteration: number | null;
    input: unknown;
    output: unknown;
    logs: StepLog[];
    errorCode: string | null;
    errorMessage: string | null;
    availableAt: string | null;
    resumeAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
}

export interface ApprovalRow {
    id: string;
    runId: string;
    workflowId: string;
    workflowName: string | null;
    title: string;
    message: string | null;
    status: ApprovalStatus;
    approverType: string;
    approverPermission: string | null;
    approverName: string | null;
    requestedBy: string | null;
    preventSelfApproval: boolean;
    onTimeout: string;
    decidedBy: string | null;
    decisionComment: string | null;
    decidedAt: string | null;
    expiresAt: string | null;
    createdAt: string | null;
}

export interface EndpointRow {
    id: string;
    name: string;
    url: string;
    hasSecret: boolean;
    active: boolean;
    lastCalledAt: string | null;
    lastStatusCode: number | null;
}

export interface TemplateRow {
    key: string;
    name: string;
    description: string;
}

export interface Revealed {
    token: string;
    secret: string;
    url: string;
}

export interface CatalogueDefinition {
    key: string;
    label: string;
    module: string;
    kind: string;
    requiredPermission: string | null;
    inputSchema: JsonSchema;
    outputSchema: JsonSchema;
    allowed: boolean;
}

export interface OperatorDefinition {
    key: string;
    label: string;
    types: string[];
    arity: 'none' | 'one' | 'two' | 'list';
}

export interface MappingOperation {
    key: string;
    fields: string[];
}

export interface EditorCatalogue {
    triggers: CatalogueDefinition[];
    actions: CatalogueDefinition[];
    nodeTypes: { type: string; label: string; handles: string[]; hasDefaultHandle: boolean; canBeTarget: boolean }[];
    operators: OperatorDefinition[];
    mappingOperations: MappingOperation[];
    variableRoots: string[];
    limits: {
        maxNodes: number;
        maxSteps: number;
        maxLoopIterations: number;
        maxParallelBranches: number;
        maxAttempts: number;
        maxDelayDays: number;
        maxDepth: number;
        approvalMaxHours: number;
        approvalDefaultHours: number;
    };
    webhookEndpoints: { id: string; name: string; active: boolean }[];
    members: { id: string; name: string }[];
    approverPermissions: { name: string; module: string }[];
    templates: TemplateRow[];
}

export interface WorkflowAbilities {
    manage: boolean;
    publish: boolean;
    start: boolean;
    viewRuns: boolean;
    manageWebhooks: boolean;
}

export interface UsageFigure {
    used: number;
    limit: number | null;
}
