/**
 * Pure helpers for the workflow graph: the wire format the server stores, the
 * shape Vue Flow renders, and the rules the visual editor applies so the
 * canvas never produces something the server would reject for structure.
 *
 * No Vue, no DOM and no translation in here, so it can be unit tested.
 */

export const NODE_TYPES = [
    'trigger',
    'condition',
    'branch',
    'action',
    'delay',
    'schedule',
    'parallel',
    'approval',
    'webhook',
    'transform',
    'loop',
    'error_handler',
    'end',
] as const;

export type NodeType = (typeof NODE_TYPES)[number];

/** Vue Flow handle id used for the unnamed (`null`) output, since a handle id cannot be null. */
export const DEFAULT_HANDLE_ID = '__out';

export interface Position {
    x: number;
    y: number;
}

export interface WireNode {
    id: string;
    type: NodeType;
    position: Position;
    data: { label: string; config: Record<string, unknown> };
}

export interface WireEdge {
    id: string;
    source: string;
    target: string;
    sourceHandle: string | null;
    data: { loopBack: boolean };
}

export interface WireGraph {
    schema_version: 1;
    nodes: WireNode[];
    edges: WireEdge[];
}

export interface FlowNodeData {
    nodeType: NodeType;
    label: string;
    config: Record<string, unknown>;
    [key: string]: unknown;
}

/** What the canvas holds. One custom Vue Flow node type renders every kind. */
export interface FlowNode {
    id: string;
    type: 'workflow';
    position: Position;
    data: FlowNodeData;
    [key: string]: unknown;
}

export interface FlowEdge {
    id: string;
    source: string;
    target: string;
    sourceHandle: string;
    type: string;
    data: { loopBack: boolean };
    [key: string]: unknown;
}

export interface Issue {
    severity: 'error' | 'warning';
    code: string;
    message: string;
    node_id: string | null;
    edge_id: string | null;
}

export interface BranchCase {
    id: string;
    label?: string;
    match: 'all' | 'any';
    rules: unknown[];
}

export interface ConfigDefaults {
    maxLoopIterations?: number;
    approvalHours?: number;
}

export const FLOW_NODE_TYPE = 'workflow';

const NODE_TYPE_SET = new Set<string>(NODE_TYPES);

export function isNodeType(value: unknown): value is NodeType {
    return typeof value === 'string' && NODE_TYPE_SET.has(value);
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function branchCases(config: Record<string, unknown>): BranchCase[] {
    const cases = Array.isArray(config.cases) ? config.cases : [];

    return cases.filter(isRecord).map((item) => ({
        id: typeof item.id === 'string' ? item.id : '',
        label: typeof item.label === 'string' ? item.label : undefined,
        match: item.match === 'any' ? 'any' : 'all',
        rules: Array.isArray(item.rules) ? item.rules : [],
    }));
}

/** The named outputs a node has, in display order. `null` is the unnamed one. */
export function handlesFor(type: NodeType, config: Record<string, unknown> = {}): (string | null)[] {
    switch (type) {
        case 'condition':
            return ['true', 'false'];
        case 'approval':
            return ['approved', 'rejected', 'error'];
        case 'loop':
            return ['body', 'done'];
        case 'action':
        case 'webhook':
            return [null, 'error'];
        case 'branch':
            return [...branchCases(config).map((item) => item.id).filter((id) => id !== ''), 'default'];
        case 'end':
            return [];
        default:
            return [null];
    }
}

export function toHandleId(handle: string | null | undefined): string {
    return handle === null || handle === undefined || handle === '' ? DEFAULT_HANDLE_ID : handle;
}

export function toWireHandle(handleId: string | null | undefined): string | null {
    return handleId === null || handleId === undefined || handleId === '' || handleId === DEFAULT_HANDLE_ID ? null : handleId;
}

/** Whether a node can have incoming connections. */
export function canBeTarget(type: NodeType): boolean {
    return type !== 'trigger';
}

/** Parallel steps fan out from their single output; every other output holds one connection. */
export function allowsMany(type: NodeType, handle: string | null): boolean {
    return type === 'parallel' && handle === null;
}

export function defaultConfig(type: NodeType, options: ConfigDefaults = {}): Record<string, unknown> {
    switch (type) {
        case 'trigger':
            return { trigger_key: '', config: {} };
        case 'condition':
            return { match: 'all', rules: [{ field: '', operator: 'equals', value: '' }] };
        case 'branch':
            return { cases: [{ id: 'case_1', label: '', match: 'all', rules: [{ field: '', operator: 'equals', value: '' }] }] };
        case 'action':
            return { action_key: '', input: {} };
        case 'delay':
            return { amount: 1, unit: 'hours' };
        case 'schedule':
            return { mode: 'cron', cron: '0 9 * * *' };
        case 'parallel':
            return {};
        case 'approval':
            return {
                title: '',
                message: '',
                approver: { type: 'permission', permission: '' },
                prevent_self_approval: true,
                timeout_hours: options.approvalHours ?? 72,
                on_timeout: 'reject',
            };
        case 'webhook':
            return { endpoint_id: '', payload: {} };
        case 'transform':
            return { mappings: [{ target: '', operation: 'copy', source: '' }] };
        case 'loop':
            return { items: '', max_iterations: options.maxLoopIterations ?? 100 };
        case 'error_handler':
            return { then: 'resume' };
        case 'end':
            return { status: 'success' };
    }
}

/** The first unused id of the form `<prefix>`, `<prefix>_2`, `<prefix>_3` ... */
export function nextId(prefix: string, taken: Iterable<string>): string {
    const used = new Set(taken);
    const base = prefix.replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 50) || 'node';

    if (!used.has(base)) {
        return base;
    }

    let n = 2;
    while (used.has(`${base}_${n}`)) {
        n += 1;
    }

    return `${base}_${n}`;
}

export function nextEdgeId(taken: Iterable<string>): string {
    const used = new Set(taken);
    let n = used.size + 1;
    while (used.has(`e${n}`)) {
        n += 1;
    }

    return `e${n}`;
}

/** A branch case id: `case_<n>`, lower case, unique among the node's cases and never `default`. */
export function nextCaseId(config: Record<string, unknown>): string {
    const used = new Set(branchCases(config).map((item) => item.id));
    let n = used.size + 1;
    while (used.has(`case_${n}`)) {
        n += 1;
    }

    return `case_${n}`;
}

export function createFlowNode(type: NodeType, existingIds: Iterable<string>, position: Position, options: ConfigDefaults = {}): FlowNode {
    return {
        id: nextId(type, existingIds),
        type: FLOW_NODE_TYPE,
        position: { x: Math.round(position.x), y: Math.round(position.y) },
        data: { nodeType: type, label: '', config: defaultConfig(type, options) },
    };
}

/** Accepts whatever the server stored and returns a graph that is safe to render. */
export function parseGraph(raw: unknown): WireGraph {
    const source = isRecord(raw) ? raw : {};
    const nodes: WireNode[] = [];
    const seen = new Set<string>();

    for (const item of Array.isArray(source.nodes) ? source.nodes : []) {
        if (!isRecord(item) || typeof item.id !== 'string' || !isNodeType(item.type) || seen.has(item.id)) {
            continue;
        }

        const data = isRecord(item.data) ? item.data : {};
        const position = isRecord(item.position) ? item.position : {};
        seen.add(item.id);
        nodes.push({
            id: item.id,
            type: item.type,
            position: {
                x: typeof position.x === 'number' && Number.isFinite(position.x) ? position.x : 0,
                y: typeof position.y === 'number' && Number.isFinite(position.y) ? position.y : 0,
            },
            data: {
                label: typeof data.label === 'string' ? data.label : '',
                config: isRecord(data.config) ? data.config : {},
            },
        });
    }

    const edges: WireEdge[] = [];
    const edgeIds = new Set<string>();

    for (const item of Array.isArray(source.edges) ? source.edges : []) {
        if (!isRecord(item) || typeof item.id !== 'string' || typeof item.source !== 'string' || typeof item.target !== 'string' || edgeIds.has(item.id)) {
            continue;
        }

        const data = isRecord(item.data) ? item.data : {};
        edgeIds.add(item.id);
        edges.push({
            id: item.id,
            source: item.source,
            target: item.target,
            sourceHandle: typeof item.sourceHandle === 'string' && item.sourceHandle !== '' ? item.sourceHandle : null,
            data: { loopBack: data.loopBack === true },
        });
    }

    return { schema_version: 1, nodes, edges };
}

export function toFlowNode(node: WireNode): FlowNode {
    return {
        id: node.id,
        type: FLOW_NODE_TYPE,
        position: { x: node.position.x, y: node.position.y },
        data: { nodeType: node.type, label: node.data.label, config: node.data.config },
    };
}

const ARROW = { type: 'arrowclosed', color: 'var(--ink-subtle)', width: 16, height: 16 } as const;

function loopBackExtras(loopBack: boolean): Record<string, unknown> {
    return { markerEnd: ARROW, ...(loopBack ? { animated: true, class: 'wf-edge-loop' } : {}) };
}

export function toFlowEdge(edge: WireEdge): FlowEdge {
    return {
        id: edge.id,
        source: edge.source,
        target: edge.target,
        sourceHandle: toHandleId(edge.sourceHandle),
        type: 'smoothstep',
        data: { loopBack: edge.data.loopBack },
        ...loopBackExtras(edge.data.loopBack),
    };
}

export function toFlow(graph: WireGraph): { nodes: FlowNode[]; edges: FlowEdge[] } {
    return { nodes: graph.nodes.map(toFlowNode), edges: graph.edges.map(toFlowEdge) };
}

/** Back to the wire format: only the known keys, so Vue Flow's runtime fields never reach the server. */
export function fromFlow(nodes: readonly FlowNode[], edges: readonly FlowEdge[]): WireGraph {
    return {
        schema_version: 1,
        nodes: nodes.map((node) => ({
            id: node.id,
            type: node.data.nodeType,
            position: { x: Math.round(node.position.x), y: Math.round(node.position.y) },
            data: { label: node.data.label, config: node.data.config },
        })),
        edges: edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            sourceHandle: toWireHandle(edge.sourceHandle),
            data: { loopBack: edge.data?.loopBack === true },
        })),
    };
}

/** Everything reachable by following edges from a node, optionally leaving through one handle only. */
export function reachableFrom(edges: readonly FlowEdge[], start: string, firstHandle?: string): Set<string> {
    const found = new Set<string>();
    const queue: string[] = [];

    for (const edge of edges) {
        if (edge.source === start && (firstHandle === undefined || edge.sourceHandle === firstHandle) && !found.has(edge.target)) {
            found.add(edge.target);
            queue.push(edge.target);
        }
    }

    while (queue.length > 0) {
        const current = queue.shift() as string;

        for (const edge of edges) {
            if (edge.source === current && !found.has(edge.target)) {
                found.add(edge.target);
                queue.push(edge.target);
            }
        }
    }

    return found;
}

/** A connection back into a loop from inside its body is the one permitted cycle. */
export function isLoopBack(nodes: readonly FlowNode[], edges: readonly FlowEdge[], source: string, target: string): boolean {
    const loop = nodes.find((node) => node.id === target);

    if (loop?.data.nodeType !== 'loop') {
        return false;
    }

    return source !== target && reachableFrom(edges, target, 'body').has(source);
}

export interface ConnectRequest {
    source: string;
    target: string;
    sourceHandle: string | null | undefined;
}

export type ConnectRefusal = 'self' | 'into_trigger' | 'from_end' | 'unknown_node' | 'bad_handle' | 'duplicate';

export interface ConnectResult {
    edges: FlowEdge[] | null;
    refusal: ConnectRefusal | null;
}

/**
 * Add a connection, applying the structural rules the server enforces: no
 * self edge, nothing into the trigger, nothing out of an end node, one edge per
 * output (parallel excepted), no duplicate. Returns the new edge list.
 */
export function connect(nodes: readonly FlowNode[], edges: readonly FlowEdge[], request: ConnectRequest): ConnectResult {
    const source = nodes.find((node) => node.id === request.source);
    const target = nodes.find((node) => node.id === request.target);

    if (!source || !target) {
        return { edges: null, refusal: 'unknown_node' };
    }

    if (source.id === target.id) {
        return { edges: null, refusal: 'self' };
    }

    if (!canBeTarget(target.data.nodeType)) {
        return { edges: null, refusal: 'into_trigger' };
    }

    if (source.data.nodeType === 'end') {
        return { edges: null, refusal: 'from_end' };
    }

    const handle = toWireHandle(request.sourceHandle);

    if (!handlesFor(source.data.nodeType, source.data.config).includes(handle)) {
        return { edges: null, refusal: 'bad_handle' };
    }

    const handleId = toHandleId(handle);

    if (edges.some((edge) => edge.source === source.id && edge.target === target.id && edge.sourceHandle === handleId)) {
        return { edges: null, refusal: 'duplicate' };
    }

    const kept = allowsMany(source.data.nodeType, handle)
        ? [...edges]
        : edges.filter((edge) => !(edge.source === source.id && edge.sourceHandle === handleId));
    const loopBack = isLoopBack(nodes, kept, source.id, target.id);

    const edge: FlowEdge = {
        id: nextEdgeId(edges.map((item) => item.id)),
        source: source.id,
        target: target.id,
        sourceHandle: handleId,
        type: 'smoothstep',
        data: { loopBack },
        ...loopBackExtras(loopBack),
    };

    return { edges: [...kept, edge], refusal: null };
}

/** Drops connections whose ends or output no longer exist (a node or branch case was removed). */
export function pruneEdges(nodes: readonly FlowNode[], edges: readonly FlowEdge[]): FlowEdge[] {
    const byId = new Map(nodes.map((node) => [node.id, node]));

    return edges.filter((edge) => {
        const source = byId.get(edge.source);

        if (!source || !byId.has(edge.target)) {
            return false;
        }

        return handlesFor(source.data.nodeType, source.data.config).includes(toWireHandle(edge.sourceHandle));
    });
}

export function removeNode(nodes: readonly FlowNode[], edges: readonly FlowEdge[], id: string): { nodes: FlowNode[]; edges: FlowEdge[] } {
    return {
        nodes: nodes.filter((node) => node.id !== id),
        edges: edges.filter((edge) => edge.source !== id && edge.target !== id),
    };
}

export function removeEdge(edges: readonly FlowEdge[], id: string): FlowEdge[] {
    return edges.filter((edge) => edge.id !== id);
}

export interface OutlineEntry {
    node: FlowNode;
    depth: number;
    reachable: boolean;
    outgoing: { handle: string | null; target: string; loopBack: boolean }[];
}

/** The graph as a flat list: trigger first, then breadth first; nodes nothing leads to come last. */
export function outline(nodes: readonly FlowNode[], edges: readonly FlowEdge[]): OutlineEntry[] {
    const byId = new Map(nodes.map((node) => [node.id, node]));
    const entries: OutlineEntry[] = [];
    const visited = new Set<string>();
    const queue: { id: string; depth: number }[] = nodes
        .filter((node) => node.data.nodeType === 'trigger')
        .map((node) => ({ id: node.id, depth: 0 }));

    const describe = (node: FlowNode, depth: number, reachable: boolean): OutlineEntry => ({
        node,
        depth,
        reachable,
        outgoing: edges
            .filter((edge) => edge.source === node.id)
            .map((edge) => ({ handle: toWireHandle(edge.sourceHandle), target: edge.target, loopBack: edge.data?.loopBack === true })),
    });

    while (queue.length > 0) {
        const { id, depth } = queue.shift() as { id: string; depth: number };
        const node = byId.get(id);

        if (!node || visited.has(id)) {
            continue;
        }

        visited.add(id);
        entries.push(describe(node, depth, true));

        for (const edge of edges) {
            if (edge.source === id && edge.data?.loopBack !== true && !visited.has(edge.target)) {
                queue.push({ id: edge.target, depth: depth + 1 });
            }
        }
    }

    for (const node of nodes) {
        if (!visited.has(node.id)) {
            entries.push(describe(node, 0, false));
        }
    }

    return entries;
}

export function issuesByNode(issues: readonly Issue[]): Map<string, Issue[]> {
    const map = new Map<string, Issue[]>();

    for (const issue of issues) {
        if (issue.node_id === null) {
            continue;
        }

        const list = map.get(issue.node_id) ?? [];
        list.push(issue);
        map.set(issue.node_id, list);
    }

    return map;
}

export function graphLevelIssues(issues: readonly Issue[]): Issue[] {
    return issues.filter((issue) => issue.node_id === null);
}

export function countIssues(issues: readonly Issue[]): { errors: number; warnings: number } {
    return {
        errors: issues.filter((issue) => issue.severity === 'error').length,
        warnings: issues.filter((issue) => issue.severity === 'warning').length,
    };
}

/** A stable string for "has the graph changed since the last save". */
export function fingerprint(graph: WireGraph): string {
    return JSON.stringify(graph);
}
