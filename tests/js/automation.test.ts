import { mount } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import {
    NODE_TYPES,
    allowsMany,
    branchCases,
    connect,
    countIssues,
    createFlowNode,
    defaultConfig,
    fromFlow,
    graphLevelIssues,
    handlesFor,
    isLoopBack,
    issuesByNode,
    nextCaseId,
    nextEdgeId,
    nextId,
    outline,
    parseGraph,
    pruneEdges,
    removeNode,
    toFlow,
    toHandleId,
    toWireHandle,
    type FlowEdge,
    type FlowNode,
    type Issue,
    type NodeType,
    type WireGraph,
} from '@/Modules/Automation/lib/graph';
import { readCookie } from '@/Modules/Automation/lib/http';
import { definitionLabel, humanizeKey } from '@/Modules/Automation/lib/labels';
import {
    asTemplate,
    displayFieldValue,
    isTemplate,
    objectFromRows,
    parseFieldValue,
    parseLooseValue,
    rowsFromObject,
    schemaFields,
    variableSuggestions,
} from '@/Modules/Automation/lib/schema';
import KeyValueEditor from '@/Modules/Automation/Components/KeyValueEditor.vue';
import WorkflowBadge from '@/Modules/Automation/Components/WorkflowBadge.vue';

type Tree = { [key: string]: Tree | string };

function paths(tree: Tree, prefix = ''): string[] {
    return Object.entries(tree).flatMap(([key, value]) => {
        const path = prefix === '' ? key : `${prefix}|${key}`;

        return typeof value === 'string' ? [path] : paths(value, path);
    });
}

const english = en.automation as unknown as Tree;
const bengali = bn.automation as unknown as Tree;

const OPERATORS = [
    'equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'between', 'contains', 'not_contains', 'starts_with', 'ends_with',
    'in', 'not_in', 'is_empty', 'is_not_empty', 'exists', 'not_exists', 'is_true', 'is_false', 'before', 'after',
    'within_last_days', 'within_next_days',
];
const MAPPINGS = [
    'copy', 'literal', 'template', 'concat', 'lowercase', 'uppercase', 'trim', 'to_string', 'to_integer', 'to_boolean',
    'length', 'default', 'add', 'subtract', 'multiply', 'first',
];

describe('automation locale', () => {
    it('has the same keys in Bengali as in English, and none empty', () => {
        expect(paths(bengali).sort()).toEqual(paths(english).sort());
        expect(paths(bengali).filter((path) => (path.split('|').reduce<unknown>((node, key) => (node as Tree)[key], bengali) as string).trim() === '')).toEqual([]);
    });

    it('names every node type, handle, operator, mapping operation and status the server can send', () => {
        const flat = english as unknown as Record<string, Record<string, unknown>>;

        for (const type of NODE_TYPES) {
            expect(flat.nodes[type], `nodes.${type}`).toBeTypeOf('string');
            expect(flat.nodeHelp[type], `nodeHelp.${type}`).toBeTypeOf('string');
        }

        for (const handle of ['next', 'true', 'false', 'approved', 'rejected', 'error', 'body', 'done', 'default']) {
            expect(flat.handles[handle], `handles.${handle}`).toBeTypeOf('string');
        }

        for (const operator of OPERATORS) {
            expect(flat.operators[operator], `operators.${operator}`).toBeTypeOf('string');
        }

        for (const operation of MAPPINGS) {
            expect(flat.mapping[operation], `mapping.${operation}`).toBeTypeOf('string');
        }

        const status = flat.status as Record<string, Record<string, unknown>>;
        expect(Object.keys(status.workflow).sort()).toEqual(['active', 'archived', 'draft', 'paused']);
        expect(Object.keys(status.run).sort()).toEqual(['awaiting_approval', 'cancelled', 'failed', 'queued', 'running', 'succeeded', 'waiting']);
        expect(Object.keys(status.step).sort()).toEqual(['cancelled', 'failed', 'pending', 'running', 'skipped', 'succeeded', 'waiting']);
        expect(Object.keys(status.approval).sort()).toEqual(['approved', 'cancelled', 'expired', 'pending', 'rejected']);
    });

    it('translates every key the automation pages and components use', () => {
        const root = join(__dirname, '..', '..', 'resources', 'js', 'Modules', 'Automation');
        const files: string[] = [];
        const walk = (directory: string): void => {
            for (const name of readdirSync(directory)) {
                const path = join(directory, name);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (/\.(vue|ts)$/.test(name)) {
                    files.push(path);
                }
            }
        };
        walk(root);

        const known = new Set(paths(english).map((path) => `automation.${path.replace(/\|/g, '.')}`));
        const missing: string[] = [];

        for (const file of files) {
            const source = readFileSync(file, 'utf8');

            for (const match of source.matchAll(/\bt\(\s*'(automation\.[A-Za-z0-9_.]+)'/g)) {
                if (!known.has(match[1]!)) {
                    missing.push(`${file}: ${match[1]}`);
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('labels triggers and actions by their registered key', () => {
        expect(definitionLabel('automation.triggers.crm.lead.created', 'crm.lead.created')).toBe('A lead is created');
        expect(definitionLabel('automation.actions.crm.deal.move_stage', 'crm.deal.move_stage')).toBe('Move a deal to another stage');
        expect(definitionLabel('automation.triggers.vendor.thing.happened', 'vendor.thing.happened')).toBe('Thing happened');
        expect(definitionLabel(null, 'vendor.thing.happened')).toBe('Thing happened');
        expect(humanizeKey('a')).toBe('A');
    });
});

function node(id: string, type: NodeType, config: Record<string, unknown> = {}, x = 0, y = 0): FlowNode {
    return { id, type: 'workflow', position: { x, y }, data: { nodeType: type, label: '', config } };
}

function edge(id: string, source: string, target: string, handle: string | null = null, loopBack = false): FlowEdge {
    return { id, source, target, sourceHandle: toHandleId(handle), type: 'smoothstep', data: { loopBack } };
}

describe('graph handles', () => {
    it('lists the named outputs of every node type', () => {
        expect(handlesFor('trigger')).toEqual([null]);
        expect(handlesFor('condition')).toEqual(['true', 'false']);
        expect(handlesFor('approval')).toEqual(['approved', 'rejected', 'error']);
        expect(handlesFor('loop')).toEqual(['body', 'done']);
        expect(handlesFor('action')).toEqual([null, 'error']);
        expect(handlesFor('webhook')).toEqual([null, 'error']);
        expect(handlesFor('end')).toEqual([]);
        expect(handlesFor('delay')).toEqual([null]);
        expect(handlesFor('branch', { cases: [{ id: 'vip' }, { id: 'standard' }] })).toEqual(['vip', 'standard', 'default']);
    });

    it('maps the unnamed output between the wire format and Vue Flow', () => {
        expect(toHandleId(null)).toBe('__out');
        expect(toWireHandle('__out')).toBeNull();
        expect(toWireHandle('true')).toBe('true');
        expect(toWireHandle(undefined)).toBeNull();
        expect(toHandleId('error')).toBe('error');
    });

    it('lets only a parallel step fan out from its single output', () => {
        expect(allowsMany('parallel', null)).toBe(true);
        expect(allowsMany('condition', 'true')).toBe(false);
        expect(allowsMany('action', null)).toBe(false);
    });

    it('reads branch cases defensively', () => {
        expect(branchCases({ cases: [{ id: 'a', label: 'A', match: 'any', rules: [1] }, 'junk'] })).toEqual([
            { id: 'a', label: 'A', match: 'any', rules: [1] },
        ]);
        expect(branchCases({})).toEqual([]);
    });

    it('gives every node type a default configuration', () => {
        for (const type of NODE_TYPES) {
            expect(defaultConfig(type)).toBeTypeOf('object');
        }

        expect(defaultConfig('delay')).toEqual({ amount: 1, unit: 'hours' });
        expect(defaultConfig('loop', { maxLoopIterations: 25 })).toMatchObject({ max_iterations: 25 });
        expect(defaultConfig('approval', { approvalHours: 48 })).toMatchObject({ timeout_hours: 48, prevent_self_approval: true });
    });
});

describe('graph conversion', () => {
    const wire: WireGraph = {
        schema_version: 1,
        nodes: [
            { id: 'trigger', type: 'trigger', position: { x: 0, y: 0 }, data: { label: 'Start', config: { trigger_key: 'crm.lead.created', config: {} } } },
            { id: 'check', type: 'condition', position: { x: 10.4, y: 150.6 }, data: { label: '', config: { match: 'all', rules: [] } } },
            { id: 'end', type: 'end', position: { x: 0, y: 300 }, data: { label: '', config: { status: 'success' } } },
        ],
        edges: [
            { id: 'e1', source: 'trigger', target: 'check', sourceHandle: null, data: { loopBack: false } },
            { id: 'e2', source: 'check', target: 'end', sourceHandle: 'true', data: { loopBack: false } },
        ],
    };

    it('round-trips the wire format through Vue Flow', () => {
        const flow = toFlow(wire);

        expect(flow.nodes.map((item) => item.type)).toEqual(['workflow', 'workflow', 'workflow']);
        expect(flow.nodes[1]!.data.nodeType).toBe('condition');
        expect(flow.edges[0]!.sourceHandle).toBe('__out');
        expect(flow.edges[1]!.sourceHandle).toBe('true');

        const back = fromFlow(flow.nodes, flow.edges);

        expect(back.edges).toEqual(wire.edges);
        expect(back.nodes[1]!.position).toEqual({ x: 10, y: 151 });
        expect(back.nodes.map((item) => item.type)).toEqual(['trigger', 'condition', 'end']);
    });

    it('sends only known keys, never Vue Flow runtime fields', () => {
        const flow = toFlow(wire);
        const noisy = flow.nodes.map((item) => ({ ...item, selected: true, dimensions: { width: 1, height: 1 }, computedPosition: { x: 1, y: 1 } }));
        const back = fromFlow(noisy as FlowNode[], flow.edges);

        expect(Object.keys(back.nodes[0]!).sort()).toEqual(['data', 'id', 'position', 'type']);
        expect(Object.keys(back.edges[0]!).sort()).toEqual(['data', 'id', 'source', 'sourceHandle', 'target']);
        expect(back.schema_version).toBe(1);
    });

    it('parses whatever the server stored without throwing', () => {
        const parsed = parseGraph({
            nodes: [
                { id: 'a', type: 'trigger', position: { x: 'x' }, data: { label: 5 } },
                { id: 'a', type: 'end' },
                { id: 'b', type: 'nonsense' },
                'junk',
            ],
            edges: [{ id: 'e', source: 'a', target: 'a', sourceHandle: '' }, { source: 'a' }],
        });

        expect(parsed.nodes).toHaveLength(1);
        expect(parsed.nodes[0]!.position).toEqual({ x: 0, y: 0 });
        expect(parsed.nodes[0]!.data).toEqual({ label: '', config: {} });
        expect(parsed.edges).toEqual([{ id: 'e', source: 'a', target: 'a', sourceHandle: null, data: { loopBack: false } }]);
        expect(parseGraph(null)).toEqual({ schema_version: 1, nodes: [], edges: [] });
    });
});

describe('graph editing rules', () => {
    const nodes = [
        node('trigger', 'trigger'),
        node('check', 'condition'),
        node('act', 'action'),
        node('end', 'end'),
        node('each', 'loop', { items: 'trigger.lines' }),
        node('body', 'transform'),
        node('fan', 'parallel'),
    ];

    it('refuses structure the server would reject', () => {
        expect(connect(nodes, [], { source: 'act', target: 'act', sourceHandle: null }).refusal).toBe('self');
        expect(connect(nodes, [], { source: 'act', target: 'trigger', sourceHandle: null }).refusal).toBe('into_trigger');
        expect(connect(nodes, [], { source: 'end', target: 'act', sourceHandle: null }).refusal).toBe('from_end');
        expect(connect(nodes, [], { source: 'nope', target: 'act', sourceHandle: null }).refusal).toBe('unknown_node');
        expect(connect(nodes, [], { source: 'act', target: 'end', sourceHandle: 'true' }).refusal).toBe('bad_handle');
    });

    it('adds a connection with the right handle and a fresh id', () => {
        const result = connect(nodes, [edge('e1', 'trigger', 'check')], { source: 'check', target: 'act', sourceHandle: 'true' });

        expect(result.refusal).toBeNull();
        expect(result.edges).toHaveLength(2);
        expect(result.edges![1]).toMatchObject({ id: 'e2', source: 'check', target: 'act', sourceHandle: 'true', data: { loopBack: false } });
    });

    it('replaces the connection on a single output and rejects an exact duplicate', () => {
        const existing = [edge('e1', 'check', 'act', 'true')];

        expect(connect(nodes, existing, { source: 'check', target: 'act', sourceHandle: 'true' }).refusal).toBe('duplicate');

        const replaced = connect(nodes, existing, { source: 'check', target: 'end', sourceHandle: 'true' });
        expect(replaced.edges!.map((item) => item.target)).toEqual(['end']);

        const other = connect(nodes, existing, { source: 'check', target: 'end', sourceHandle: 'false' });
        expect(other.edges!.map((item) => `${item.sourceHandle}>${item.target}`)).toEqual(['true>act', 'false>end']);
    });

    it('keeps several connections from a parallel step', () => {
        const first = connect(nodes, [], { source: 'fan', target: 'act', sourceHandle: '__out' });
        const second = connect(nodes, first.edges!, { source: 'fan', target: 'end', sourceHandle: '__out' });

        expect(second.edges).toHaveLength(2);
    });

    it('flags a connection back into a loop from its body as the permitted cycle', () => {
        const edges = [edge('e1', 'each', 'body', 'body'), edge('e2', 'body', 'act')];

        expect(isLoopBack(nodes, edges, 'act', 'each')).toBe(true);
        expect(isLoopBack(nodes, edges, 'trigger', 'each')).toBe(false);
        expect(isLoopBack(nodes, edges, 'act', 'end')).toBe(false);

        const result = connect(nodes, edges, { source: 'act', target: 'each', sourceHandle: '__out' });
        expect(result.edges!.at(-1)!.data.loopBack).toBe(true);
    });

    it('drops connections whose output or end disappeared', () => {
        const branch = node('route', 'branch', { cases: [{ id: 'case_1', match: 'all', rules: [] }] });
        const all = [...nodes, branch];
        const edges = [edge('e1', 'route', 'act', 'case_1'), edge('e2', 'route', 'end', 'default'), edge('e3', 'ghost', 'act'), edge('e4', 'act', 'gone')];

        expect(pruneEdges(all, edges).map((item) => item.id)).toEqual(['e1', 'e2']);

        const fewer = all.map((item) => (item.id === 'route' ? node('route', 'branch', { cases: [] }) : item));
        expect(pruneEdges(fewer, edges).map((item) => item.id)).toEqual(['e2']);
    });

    it('removes a node with its connections', () => {
        const next = removeNode(nodes, [edge('e1', 'trigger', 'check'), edge('e2', 'check', 'end', 'true')], 'check');

        expect(next.nodes.map((item) => item.id)).not.toContain('check');
        expect(next.edges).toEqual([]);
    });

    it('creates unique ids', () => {
        expect(nextId('action', ['trigger'])).toBe('action');
        expect(nextId('action', ['action', 'action_2'])).toBe('action_3');
        expect(nextEdgeId(['e1', 'e2'])).toBe('e3');
        expect(nextCaseId({ cases: [{ id: 'case_1' }, { id: 'case_3' }] })).toBe('case_4');
        expect(createFlowNode('delay', ['delay'], { x: 4.6, y: 9.2 })).toMatchObject({ id: 'delay_2', position: { x: 5, y: 9 }, data: { nodeType: 'delay' } });
    });
});

describe('graph outline and issues', () => {
    it('lists the trigger first, follows connections and puts unconnected steps last', () => {
        const nodes = [node('end', 'end'), node('lonely', 'delay'), node('trigger', 'trigger'), node('act', 'action')];
        const edges = [edge('e1', 'trigger', 'act'), edge('e2', 'act', 'end', 'error')];
        const entries = outline(nodes, edges);

        expect(entries.map((entry) => entry.node.id)).toEqual(['trigger', 'act', 'end', 'lonely']);
        expect(entries.map((entry) => entry.depth)).toEqual([0, 1, 2, 0]);
        expect(entries[3]!.reachable).toBe(false);
        expect(entries[1]!.outgoing).toEqual([{ handle: 'error', target: 'end', loopBack: false }]);
    });

    it('groups validator issues by step', () => {
        const issues: Issue[] = [
            { severity: 'error', code: 'no_trigger', message: 'm', node_id: null, edge_id: null },
            { severity: 'error', code: 'bad_delay', message: 'm', node_id: 'wait', edge_id: null },
            { severity: 'warning', code: 'no_end', message: 'm', node_id: 'wait', edge_id: null },
        ];

        expect(issuesByNode(issues).get('wait')).toHaveLength(2);
        expect(graphLevelIssues(issues)).toHaveLength(1);
        expect(countIssues(issues)).toEqual({ errors: 2, warnings: 1 });
    });
});

describe('schema fields', () => {
    const schema = {
        type: 'object',
        required: ['subject'],
        properties: {
            subject: { type: 'string', maxLength: 200 },
            due_at: { type: 'string', format: 'date-time' },
            lead_id: { type: ['string', 'null'] },
            priority: { type: 'string', enum: ['low', 'high'] },
            count: { type: 'integer', minimum: 1 },
            notify: { type: 'boolean' },
            tags: { type: 'array', items: { type: 'string' } },
            extra: { type: 'object' },
        },
    };

    it('turns a JSON schema into field descriptions', () => {
        const fields = Object.fromEntries(schemaFields(schema).map((field) => [field.key, field]));

        expect(fields.subject).toMatchObject({ kind: 'text', required: true, maxLength: 200 });
        expect(fields.due_at!.kind).toBe('datetime');
        expect(fields.lead_id).toMatchObject({ kind: 'text', nullable: true, required: false });
        expect(fields.priority).toMatchObject({ kind: 'enum', options: ['low', 'high'] });
        expect(fields.count).toMatchObject({ kind: 'integer', minimum: 1 });
        expect(fields.notify!.kind).toBe('boolean');
        expect(fields.tags!.kind).toBe('array');
        expect(fields.extra!.kind).toBe('object');
        expect(schemaFields(null)).toEqual([]);
        expect(schemaFields({ type: 'object', properties: [] })).toEqual([]);
    });

    it('types what was entered and leaves templates as text', () => {
        expect(parseFieldValue('integer', ' 42 ')).toBe(42);
        expect(parseFieldValue('integer', '4.5')).toBe('4.5');
        expect(parseFieldValue('number', '4.5')).toBe(4.5);
        expect(parseFieldValue('boolean', 'true')).toBe(true);
        expect(parseFieldValue('array', 'a, b,, c')).toEqual(['a', 'b', 'c']);
        expect(parseFieldValue('text', '')).toBeUndefined();
        expect(parseFieldValue('integer', asTemplate('trigger.amount'))).toBe('{{ trigger.amount }}');
        expect(isTemplate('x {{ trigger.a }} y')).toBe(true);
        expect(isTemplate('plain')).toBe(false);
        expect(displayFieldValue(['a', 'b'])).toBe('a, b');
        expect(displayFieldValue(null)).toBe('');
    });

    it('edits an object as name and value rows', () => {
        expect(rowsFromObject({ a: 1, b: 'x' })).toEqual([{ key: 'a', value: '1' }, { key: 'b', value: 'x' }]);
        expect(objectFromRows([{ key: ' a ', value: '1' }, { key: '', value: 'dropped' }, { key: 'ok', value: 'true' }, { key: 's', value: 'x' }])).toEqual({ a: 1, ok: true, s: 'x' });
        expect(parseLooseValue('007')).toBe(7);
        expect(parseLooseValue('12345678901234567890')).toBe('12345678901234567890');
    });

    it('offers the values a step can read', () => {
        const nodes = [
            node('trigger', 'trigger', { trigger_key: 'crm.lead.created' }),
            node('make', 'action', { action_key: 'crm.activity.create_follow_up' }),
            node('prep', 'transform', { mappings: [{ target: 'greeting', operation: 'literal', value: 'hi' }] }),
            node('each', 'loop', { items: 'trigger.lines' }),
        ];
        const catalogue = {
            triggers: [{ key: 'crm.lead.created', outputSchema: { properties: { lead_id: { type: 'string' }, score: { type: 'integer' } } } }],
            actions: [{ key: 'crm.activity.create_follow_up', outputSchema: { properties: { activity_id: { type: 'string' } } } }],
            variableRoots: ['trigger', 'steps', 'vars', 'loop', 'run', 'workflow', 'error', 'approval'],
        };
        const suggestions = variableSuggestions(nodes, catalogue, 'prep');

        expect(suggestions).toContain('trigger.lead_id');
        expect(suggestions).toContain('trigger.score');
        expect(suggestions).toContain('steps.make.activity_id');
        expect(suggestions).toContain('loop.item');
        expect(suggestions).toContain('run.id');
        expect(suggestions).not.toContain('vars.greeting');
        expect(variableSuggestions(nodes, catalogue, null)).toContain('vars.greeting');
    });
});

describe('transport helpers', () => {
    it('reads a cookie by name and decodes it', () => {
        expect(readCookie('XSRF-TOKEN', 'a=1; XSRF-TOKEN=abc%3D%3D; b=2')).toBe('abc==');
        expect(readCookie('missing', 'a=1')).toBeNull();
        expect(readCookie('x', '')).toBeNull();
    });
});

describe('automation components', () => {
    it('shows run and workflow statuses with their translated text', () => {
        const run = mount(WorkflowBadge, { props: { kind: 'run', value: 'awaiting_approval' } });
        expect(run.text()).toBe('Waiting for approval');

        const unknown = mount(WorkflowBadge, { props: { kind: 'workflow', value: 'something_new' } });
        expect(unknown.text()).toBe('something_new');
    });

    it('edits name and value rows and emits a typed object', async () => {
        const Host = defineComponent({
            data: () => ({ value: { retries: 3 } as Record<string, unknown> }),
            render() {
                return h(KeyValueEditor, { modelValue: this.value, 'onUpdate:modelValue': (next: Record<string, unknown>) => { this.value = next; } });
            },
        });
        const wrapper = mount(Host);
        const inputs = wrapper.findAll('input');

        expect(inputs).toHaveLength(2);
        expect((inputs[0]!.element as HTMLInputElement).value).toBe('retries');
        expect((inputs[1]!.element as HTMLInputElement).value).toBe('3');

        await inputs[1]!.setValue('5');
        expect(wrapper.vm.value).toEqual({ retries: 5 });

        await wrapper.findAll('button').at(-1)!.trigger('click');
        expect(wrapper.findAll('input')).toHaveLength(4);
    });
});
