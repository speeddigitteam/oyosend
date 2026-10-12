import type { FlowNode } from './graph';

/**
 * Turns the JSON Schema a trigger or action publishes into plain, declarative
 * form fields, and turns what the person typed back into the typed value the
 * server validates. Nothing here evaluates anything.
 */

export interface JsonSchema {
    type?: string | string[];
    required?: string[];
    properties?: Record<string, JsonSchema>;
    enum?: (string | number)[];
    format?: string;
    maxLength?: number;
    minLength?: number;
    minimum?: number;
    maximum?: number;
    description?: string;
    items?: JsonSchema;
    additionalProperties?: boolean | JsonSchema;
}

export type FieldKind = 'enum' | 'boolean' | 'integer' | 'number' | 'array' | 'object' | 'datetime' | 'text';

export interface SchemaField {
    key: string;
    kind: FieldKind;
    required: boolean;
    nullable: boolean;
    options: (string | number)[];
    maxLength: number | null;
    minimum: number | null;
    maximum: number | null;
}

function typesOf(schema: JsonSchema): string[] {
    if (Array.isArray(schema.type)) {
        return schema.type;
    }

    return typeof schema.type === 'string' ? [schema.type] : [];
}

function kindOf(schema: JsonSchema): FieldKind {
    if (Array.isArray(schema.enum) && schema.enum.length > 0) {
        return 'enum';
    }

    const types = typesOf(schema).filter((type) => type !== 'null');
    const first = types[0] ?? 'string';

    if (first === 'boolean') {
        return 'boolean';
    }

    if (first === 'integer') {
        return 'integer';
    }

    if (first === 'number') {
        return 'number';
    }

    if (first === 'array') {
        return 'array';
    }

    if (first === 'object') {
        return 'object';
    }

    return schema.format === 'date-time' || schema.format === 'date' ? 'datetime' : 'text';
}

export function schemaFields(schema: unknown): SchemaField[] {
    if (typeof schema !== 'object' || schema === null) {
        return [];
    }

    const root = schema as JsonSchema;
    const required = new Set(Array.isArray(root.required) ? root.required : []);

    return Object.entries(root.properties ?? {}).map(([key, property]) => {
        const definition = (typeof property === 'object' && property !== null ? property : {}) as JsonSchema;

        return {
            key,
            kind: kindOf(definition),
            required: required.has(key),
            nullable: typesOf(definition).includes('null'),
            options: Array.isArray(definition.enum) ? definition.enum : [],
            maxLength: typeof definition.maxLength === 'number' ? definition.maxLength : null,
            minimum: typeof definition.minimum === 'number' ? definition.minimum : null,
            maximum: typeof definition.maximum === 'number' ? definition.maximum : null,
        };
    });
}

/** A value that is a `{{ path }}` template: resolved at run time, never typed in advance. */
export function isTemplate(value: unknown): boolean {
    return typeof value === 'string' && /\{\{.*\}\}/.test(value);
}

/**
 * What the person typed, as the typed value for the field. Whole numbers
 * become integers; anything with a template stays text so the engine can
 * resolve it. An empty string means "not set" and returns undefined.
 */
export function parseFieldValue(kind: FieldKind, raw: string): unknown {
    const text = raw.trim();

    if (text === '') {
        return undefined;
    }

    if (isTemplate(text)) {
        return text;
    }

    switch (kind) {
        case 'integer':
            return /^-?\d+$/.test(text) ? Number.parseInt(text, 10) : text;
        case 'number':
            return /^-?\d+(\.\d+)?$/.test(text) ? Number(text) : text;
        case 'boolean':
            return text === 'true' ? true : text === 'false' ? false : text;
        case 'array':
            return text.split(',').map((item) => item.trim()).filter((item) => item !== '');
        default:
            return raw;
    }
}

export function displayFieldValue(value: unknown): string {
    if (value === undefined || value === null) {
        return '';
    }

    if (Array.isArray(value)) {
        return value.map((item) => String(item)).join(', ');
    }

    if (typeof value === 'object') {
        return '';
    }

    return String(value);
}

export interface KeyValueRow {
    key: string;
    value: string;
}

/** Objects are edited as rows of text; whole numbers and booleans round-trip through `parseLooseValue`. */
export function rowsFromObject(value: unknown): KeyValueRow[] {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return [];
    }

    return Object.entries(value as Record<string, unknown>).map(([key, item]) => ({ key, value: displayFieldValue(item) }));
}

/** Loose typing for key/value rows: `true`/`false` and whole numbers keep their type, templates stay text. */
export function parseLooseValue(raw: string): unknown {
    const text = raw.trim();

    if (text === 'true') {
        return true;
    }

    if (text === 'false') {
        return false;
    }

    if (/^-?\d{1,15}$/.test(text)) {
        return Number.parseInt(text, 10);
    }

    return raw;
}

export function objectFromRows(rows: readonly KeyValueRow[]): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    for (const row of rows) {
        const key = row.key.trim();

        if (key !== '') {
            result[key] = parseLooseValue(row.value);
        }
    }

    return result;
}

export interface VariableCatalogue {
    triggers: { key: string; outputSchema: unknown }[];
    actions: { key: string; outputSchema: unknown }[];
    variableRoots: string[];
}

/**
 * The paths a person can reference from a node: the trigger's fields, the
 * outputs of other steps, variables written by transform nodes, and the
 * fixed context (run, workflow, loop, error). `forNode` excludes itself.
 */
export function variableSuggestions(nodes: readonly FlowNode[], catalogue: VariableCatalogue, forNode: string | null): string[] {
    const paths = new Set<string>();
    const roots = new Set(catalogue.variableRoots);
    const propertyNames = (schema: unknown): string[] => schemaFields(schema).map((field) => field.key);

    for (const node of nodes) {
        const config = node.data.config;

        if (node.data.nodeType === 'trigger') {
            const key = typeof config.trigger_key === 'string' ? config.trigger_key : '';
            const trigger = catalogue.triggers.find((item) => item.key === key);

            for (const name of trigger ? propertyNames(trigger.outputSchema) : []) {
                paths.add(`trigger.${name}`);
            }
        }

        if (node.id === forNode) {
            continue;
        }

        if (node.data.nodeType === 'action') {
            const key = typeof config.action_key === 'string' ? config.action_key : '';
            const action = catalogue.actions.find((item) => item.key === key);
            const names = action ? propertyNames(action.outputSchema) : [];

            if (names.length === 0) {
                paths.add(`steps.${node.id}`);
            }

            for (const name of names) {
                paths.add(`steps.${node.id}.${name}`);
            }
        }

        if (node.data.nodeType === 'webhook') {
            paths.add(`steps.${node.id}`);
        }

        if (node.data.nodeType === 'transform' && Array.isArray(config.mappings)) {
            for (const mapping of config.mappings) {
                const target = typeof mapping === 'object' && mapping !== null ? (mapping as Record<string, unknown>).target : null;

                if (typeof target === 'string' && target !== '') {
                    paths.add(`vars.${target}`);
                }
            }
        }

        if (node.data.nodeType === 'loop') {
            paths.add('loop.item');
            paths.add('loop.index');
            paths.add('loop.count');
        }

        if (node.data.nodeType === 'error_handler') {
            paths.add('error.node_id');
            paths.add('error.code');
            paths.add('error.message');
        }
    }

    for (const path of ['run.id', 'run.mode', 'run.source', 'run.started_at', 'workflow.id', 'workflow.name']) {
        if (roots.size === 0 || roots.has(path.split('.')[0])) {
            paths.add(path);
        }
    }

    if (roots.has('trigger')) {
        paths.add('trigger');
    }

    if (roots.has('approval')) {
        paths.add('approval');
    }

    return [...paths].sort();
}

export function asTemplate(path: string): string {
    return `{{ ${path} }}`;
}
