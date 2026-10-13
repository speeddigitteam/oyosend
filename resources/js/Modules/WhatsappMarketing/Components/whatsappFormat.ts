import type {
    CampaignStatus,
    FlowLimits,
    FlowRow,
    FlowSimpleStep,
    FlowStep,
    MessageStatus,
    RecipientState,
    TemplateButton,
    TemplateStatus,
    VariableMapping,
} from './types';

export type Tone = 'neutral' | 'brand' | 'accent' | 'ok' | 'warn' | 'danger' | 'info';

/** Tone of a template's status badge. */
export function templateStatusTone(status: TemplateStatus | string): Tone {
    switch (status) {
        case 'approved':
            return 'ok';
        case 'pending':
            return 'info';
        case 'paused':
            return 'warn';
        case 'rejected':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function campaignStatusTone(status: CampaignStatus | string): Tone {
    switch (status) {
        case 'sending':
            return 'info';
        case 'scheduled':
            return 'brand';
        case 'completed':
            return 'ok';
        case 'failed':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function recipientStateTone(state: RecipientState | string): Tone {
    switch (state) {
        case 'delivered':
        case 'read':
            return 'ok';
        case 'sent':
        case 'queued':
            return 'info';
        case 'failed':
            return 'danger';
        case 'blocked':
        case 'skipped':
            return 'warn';
        default:
            return 'neutral';
    }
}

export function messageStatusTone(status: MessageStatus | string): Tone {
    switch (status) {
        case 'delivered':
        case 'read':
            return 'ok';
        case 'failed':
            return 'danger';
        case 'blocked':
            return 'warn';
        default:
            return 'neutral';
    }
}

export interface WindowRemaining {
    open: boolean;
    hours: number;
    minutes: number;
    totalMinutes: number;
}

/** Time left in the 24-hour customer-service window. A missing or past expiry is a closed window. */
export function windowRemaining(expiresAt: string | null, now: number = Date.now()): WindowRemaining {
    if (expiresAt === null) {
        return { open: false, hours: 0, minutes: 0, totalMinutes: 0 };
    }

    const expiry = Date.parse(expiresAt);

    if (Number.isNaN(expiry) || expiry <= now) {
        return { open: false, hours: 0, minutes: 0, totalMinutes: 0 };
    }

    const totalMinutes = Math.max(1, Math.ceil((expiry - now) / 60_000));

    return { open: true, hours: Math.floor(totalMinutes / 60), minutes: totalMinutes % 60, totalMinutes };
}

/** Basis points to a percentage number; null stays null. */
export function basisPointsToPercent(basisPoints: number | null | undefined): number | null {
    return basisPoints === null || basisPoints === undefined ? null : basisPoints / 100;
}

/** The positions 1..count a template has. */
export function variablePositions(count: number): number[] {
    return Array.from({ length: Math.max(0, count) }, (_, index) => index + 1);
}

export interface VariableRow {
    position: number;
    kind: 'field' | 'text';
    field: string;
    value: string;
}

/** One editable row per template variable, seeded from a saved map. */
export function variableRows(count: number, map: Record<string, VariableMapping> | null | undefined, defaultField = ''): VariableRow[] {
    return variablePositions(count).map((position): VariableRow => {
        const saved = map?.[String(position)];

        if (saved?.type === 'text') {
            return { position, kind: 'text', field: defaultField, value: saved.value };
        }

        return { position, kind: 'field', field: saved?.type === 'field' ? saved.field : defaultField, value: '' };
    });
}

/** The `variables` payload: an object keyed by position, one mapping each. Incomplete rows are left out. */
export function buildVariableMap(rows: VariableRow[]): Record<string, VariableMapping> {
    const map: Record<string, VariableMapping> = {};

    for (const row of rows) {
        if (row.kind === 'field' && row.field !== '') {
            map[String(row.position)] = { type: 'field', field: row.field };
        } else if (row.kind === 'text' && row.value.trim() !== '') {
            map[String(row.position)] = { type: 'text', value: row.value.trim() };
        }
    }

    return map;
}

/** Whether every position of the template has a usable mapping. */
export function variablesComplete(rows: VariableRow[]): boolean {
    return rows.every((row) => (row.kind === 'field' ? row.field !== '' : row.value.trim() !== ''));
}

/** Fill numbered positions of a body with values, to show what will be sent. Unfilled ones stay as written. */
export function renderTemplateBody(body: string, values: string[]): string {
    return body.replace(/\{\{\s*(\d+)\s*\}\}/g, (whole, position: string) => {
        const value = values[Number(position) - 1];

        return value !== undefined && value.trim() !== '' ? value : whole;
    });
}

/** Highest numbered position a body uses. */
export function countTemplateVariables(body: string): number {
    let highest = 0;

    for (const match of body.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) {
        highest = Math.max(highest, Number(match[1]));
    }

    return highest;
}

export function normalizeKeyword(value: string): string {
    return value.trim().replace(/\s+/g, ' ').toLowerCase();
}

/** Add a keyword to a list: normalised, no duplicates, no more than `max`. Returns the new list. */
export function addKeyword(list: string[], raw: string, max: number): string[] {
    const keyword = normalizeKeyword(raw);

    if (keyword === '' || keyword.length > 40 || list.includes(keyword) || list.length >= max) {
        return list;
    }

    return [...list, keyword];
}

export interface FlowForm {
    name: string;
    trigger: 'keyword' | 'welcome';
    match_type: 'exact' | 'contains';
    keywords: string[];
    steps: FlowSimpleStep[];
    menu: { prompt: string; options: { label: string; actions: FlowSimpleStep[] }[] } | null;
    priority: number;
    is_active: boolean;
}

export const FLOW_STEP_TYPES = ['reply', 'tag', 'assign', 'handoff'] as const;

export function newStep(type: (typeof FLOW_STEP_TYPES)[number]): FlowSimpleStep {
    switch (type) {
        case 'reply':
            return { type: 'reply', text: '' };
        case 'tag':
            return { type: 'tag', tag: '' };
        case 'assign':
            return { type: 'assign', assignee_id: null };
        default:
            return { type: 'handoff' };
    }
}

export function emptyFlowForm(): FlowForm {
    return { name: '', trigger: 'keyword', match_type: 'contains', keywords: [], steps: [newStep('reply')], menu: null, priority: 100, is_active: true };
}

/** Split the server's step list into plain steps and the (single, last) menu. */
export function flowToForm(flow: FlowRow): FlowForm {
    const steps: FlowSimpleStep[] = [];
    let menu: FlowForm['menu'] = null;

    for (const step of flow.actions) {
        if (step.type === 'menu') {
            menu = { prompt: step.prompt, options: step.options.map((option) => ({ label: option.label, actions: option.actions.map((action) => ({ ...action })) })) };
        } else {
            steps.push({ ...step });
        }
    }

    return {
        name: flow.name,
        trigger: flow.trigger,
        match_type: flow.matchType ?? 'contains',
        keywords: [...flow.keywords],
        steps,
        menu,
        priority: flow.priority,
        is_active: flow.isActive,
    };
}

function cleanStep(step: FlowSimpleStep): FlowSimpleStep | null {
    switch (step.type) {
        case 'reply': {
            const text = step.text.trim();

            return text === '' ? null : { type: 'reply', text };
        }
        case 'tag': {
            const tag = step.tag.trim().toLowerCase();

            return tag === '' ? null : { type: 'tag', tag };
        }
        case 'assign':
            return { type: 'assign', assignee_id: step.assignee_id === '' ? null : step.assignee_id };
        default:
            return { type: 'handoff' };
    }
}

function present(step: FlowSimpleStep | null): step is FlowSimpleStep {
    return step !== null;
}

/**
 * The request body for FlowDefinition: a closed list of steps, with the menu (when there is one) always last.
 * Empty steps are dropped; the server validates again. Nothing here is an expression: the steps are plain data.
 */
export function buildFlowPayload(form: FlowForm, limits: FlowLimits): Record<string, unknown> {
    const plain: FlowStep[] = form.steps.map(cleanStep).filter(present);
    const keyword = form.trigger === 'keyword';
    let actions: FlowStep[] = plain.slice(0, limits.actions);

    if (form.menu !== null) {
        const options = form.menu.options
            .map((option) => ({
                label: option.label.trim(),
                actions: option.actions.map(cleanStep).filter(present).slice(0, limits.optionActions),
            }))
            .filter((option) => option.label !== '')
            .slice(0, limits.options);

        // The menu takes the last place; the plain steps leave one slot for it.
        actions = [...plain.slice(0, Math.max(0, limits.actions - 1)), { type: 'menu', prompt: form.menu.prompt.trim(), options }];
    }

    return {
        name: form.name.trim(),
        trigger: form.trigger,
        match_type: keyword ? form.match_type : null,
        keywords: keyword ? form.keywords.slice(0, limits.keywords) : [],
        actions,
        priority: form.priority,
        is_active: form.is_active,
    };
}

/** Whether the form could pass the server: enables the save button, never authorises. */
export function flowFormValid(form: FlowForm): boolean {
    const payload = buildFlowPayload(form, { actions: 99, options: 99, optionActions: 99, keywords: 99 });
    const actions = payload.actions as FlowStep[];
    const menu = actions.find((step) => step.type === 'menu');
    const menuOk =
        menu === undefined ||
        (menu.type === 'menu' && menu.prompt !== '' && menu.options.length > 0 && menu.options.every((option) => option.actions.length > 0));

    return form.name.trim() !== '' && actions.length > 0 && menuOk && (form.trigger === 'welcome' || form.keywords.length > 0);
}

export interface ButtonForm {
    type: TemplateButton['type'];
    text: string;
    value: string;
}

/** Buttons for the template request: empty ones dropped, a value only for the types that carry one. */
export function buildButtons(buttons: ButtonForm[]): { type: string; text: string; value: string | null }[] {
    return buttons
        .filter((button) => button.text.trim() !== '')
        .slice(0, 3)
        .map((button) => ({
            type: button.type,
            text: button.text.trim(),
            value: button.type === 'quick_reply' || button.value.trim() === '' ? null : button.value.trim(),
        }));
}

/** Add text after what is already written, on a new line. */
export function appendText(current: string, addition: string): string {
    return current.trim() === '' ? addition : `${current.replace(/\s+$/, '')}\n${addition}`;
}

/** Up to two initials of a name; a phone number gives none. */
export function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/).filter((word) => /\p{L}/u.test(word));

    return words
        .slice(0, 2)
        .map((word) => Array.from(word)[0] ?? '')
        .join('')
        .toUpperCase();
}

/**
 * A `datetime-local` value ("2026-10-20T14:30") read in the given IANA time zone, as an ISO instant in UTC.
 * The workspace's zone, not the browser's, decides what "2:30 pm" means; the server then receives an
 * unambiguous instant. Returns null for input that is not a date and time.
 */
export function zonedToIso(local: string, timeZone: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(local);

    if (match === null) {
        return null;
    }

    const [year, month, day, hour, minute] = match.slice(1).map(Number) as [number, number, number, number, number];
    const wall = Date.UTC(year, month - 1, day, hour, minute);

    const offsetAt = (instant: number): number => {
        try {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone,
                hourCycle: 'h23',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            }).formatToParts(new Date(instant));
            const get = (type: string): number => Number(parts.find((part) => part.type === type)?.value ?? '0');

            return Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second')) - instant;
        } catch {
            return 0;
        }
    };

    let instant = wall - offsetAt(wall);
    instant = wall - offsetAt(instant);

    return new Date(instant).toISOString();
}

/** Meta's limits for reply buttons and list messages (checked again by the server and the provider adapter). */
export const CHOICE_LIMITS = { buttons: 3, buttonLength: 20, rows: 10, rowLength: 24, listButton: 20, body: 1024 } as const;

export interface ChoicesForm {
    kind: '' | 'buttons' | 'list';
    button: string;
    options: string[];
}

export function emptyChoices(): ChoicesForm {
    return { kind: '', button: '', options: ['', ''] };
}

/** The `interactive` field of a reply, or null for a text-only reply. */
export function choicesPayload(form: ChoicesForm): { kind: 'buttons' | 'list'; button?: string; options: string[] } | null {
    if (form.kind === '') {
        return null;
    }

    const options = form.options.map((option) => option.trim()).filter((option) => option !== '');

    return form.kind === 'list' ? { kind: 'list', button: form.button.trim(), options } : { kind: 'buttons', options };
}

/** Whether the choices fit Meta's limits: distinct, non-empty labels, the right count and length, a list button. */
export function choicesValid(form: ChoicesForm): boolean {
    const payload = choicesPayload(form);

    if (payload === null) {
        return true;
    }

    const max = payload.kind === 'list' ? CHOICE_LIMITS.rows : CHOICE_LIMITS.buttons;
    const length = payload.kind === 'list' ? CHOICE_LIMITS.rowLength : CHOICE_LIMITS.buttonLength;
    const distinct = new Set(payload.options.map((option) => option.toLowerCase())).size === payload.options.length;
    const button = payload.button ?? '';

    return (
        payload.options.length > 0 &&
        payload.options.length <= max &&
        distinct &&
        payload.options.every((option) => [...option].length <= length) &&
        (payload.kind === 'buttons' || (button !== '' && [...button].length <= CHOICE_LIMITS.listButton))
    );
}

/** The fields and conditions a list rule may use (the server's `ContactRules::FIELDS`). */
export const RULE_FIELDS: Record<string, string[]> = {
    city: ['equals', 'not_equals', 'contains', 'exists', 'not_exists'],
    country: ['equals', 'not_equals', 'contains', 'exists', 'not_exists'],
    job_title: ['equals', 'not_equals', 'contains', 'exists', 'not_exists'],
    has_company: ['is_true', 'is_false'],
    created_after: ['on_or_after'],
    created_before: ['on_or_before'],
    conversation_tag: ['equals', 'not_equals'],
    wrote_within_days: ['within'],
    has_written: ['is_true', 'is_false'],
};

export function ruleNeedsValue(operator: string): boolean {
    return !['exists', 'not_exists', 'is_true', 'is_false'].includes(operator);
}

export function ruleValueType(operator: string): 'date' | 'number' | 'text' {
    if (operator === 'on_or_after' || operator === 'on_or_before') {
        return 'date';
    }

    return operator === 'within' ? 'number' : 'text';
}
