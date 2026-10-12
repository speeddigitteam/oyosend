import { mount, type VueWrapper } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Submission { method: string; url: string; data: Record<string, unknown> }
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['whatsapp_marketing.conversations.view'] as string[], membership: { id: 'm-1', isOwner: false } },
            tenant: { name: 'Acme' },
        },
    },
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        let defaults = structuredClone(initial);
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.defaults = (values: Record<string, unknown>) => { defaults = { ...defaults, ...structuredClone(values) }; return form; };
        form.reset = () => { Object.assign(form, structuredClone(defaults)); return form; };
        form.clearErrors = () => {};
        form.transform = (callback: (data: Record<string, unknown>) => Record<string, unknown>) => { transform = callback; return form; };
        const submit = (method: string, url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, JSON.parse(JSON.stringify(toRaw(form[key]) ?? null))]));
            inertia.submissions.push({ method, url, data: transform(data) });
            options?.onSuccess?.();
        };
        form.post = (url: string, options?: { onSuccess?: () => void }) => submit('post', url, options);
        form.put = (url: string, options?: { onSuccess?: () => void }) => submit('put', url, options);
        form.submit = (method: string, url: string, options?: { onSuccess?: () => void }) => submit(method, url, options);
        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

import { setLocale } from '@/Core/i18n';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import ConversationComposer from '@/Modules/WhatsappMarketing/Components/ConversationComposer.vue';
import MessageBubble from '@/Modules/WhatsappMarketing/Components/MessageBubble.vue';
import TemplateStatusBadge from '@/Modules/WhatsappMarketing/Components/TemplateStatusBadge.vue';
import type { ChannelInfo, FlowRow, MessageRow, QuickReplyRow, SendableTemplate } from '@/Modules/WhatsappMarketing/Components/types';
import WhatsappOverviewWidget from '@/Modules/WhatsappMarketing/Components/WhatsappOverviewWidget.vue';
import {
    addKeyword,
    appendText,
    basisPointsToPercent,
    buildButtons,
    buildFlowPayload,
    buildVariableMap,
    campaignStatusTone,
    countTemplateVariables,
    emptyFlowForm,
    flowFormValid,
    flowToForm,
    initialsOf,
    messageStatusTone,
    newStep,
    recipientStateTone,
    renderTemplateBody,
    templateStatusTone,
    variableRows,
    variablesComplete,
    windowRemaining,
    zonedToIso,
} from '@/Modules/WhatsappMarketing/Components/whatsappFormat';
import AnalyticsIndex from '@/Modules/WhatsappMarketing/Pages/Analytics/Index.vue';

type Tree = { [key: string]: Tree | string };

function flatten(tree: Tree, prefix = ''): Map<string, string> {
    const flat = new Map<string, string>();

    for (const [key, value] of Object.entries(tree)) {
        const path = prefix === '' ? key : `${prefix}|${key}`;

        if (typeof value === 'string') {
            flat.set(path, value);
        } else {
            for (const [childPath, childValue] of flatten(value, path)) {
                flat.set(childPath, childValue);
            }
        }
    }

    return flat;
}

const english = flatten(en.whatsapp as unknown as Tree);
const bengali = flatten(bn.whatsapp as unknown as Tree);

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

const available: ChannelInfo = { state: 'available', reason: null, testDouble: false };
const down: ChannelInfo = { state: 'not_configured', reason: 'provider_not_configured', testDouble: false };
const templates: SendableTemplate[] = [{ id: 't-1', name: 'order_update', language: 'en', category: 'utility', body: 'Hi {{1}}, order {{2}} shipped.', variableCount: 2 }];
const quickReplies: QuickReplyRow[] = [{ id: 'q-1', shortcut: 'hours', title: 'Opening hours', body: 'We open at nine.', isActive: true }];

function composer(overrides: Record<string, unknown> = {}) {
    return track(mount(ConversationComposer, {
        props: {
            conversationId: 'c-1',
            windowOpen: true,
            windowExpiresAt: new Date(Date.now() + 5 * 3_600_000).toISOString(),
            hasContact: true,
            canReply: true,
            templates,
            quickReplies,
            channel: available,
            ...overrides,
        },
    }));
}

function message(overrides: Partial<MessageRow> = {}): MessageRow {
    return {
        id: 'msg-1', direction: 'inbound', senderType: 'customer', author: null, type: 'text', body: 'Hello there', templateName: null,
        status: 'received', failureCode: null, attachments: [], occurredAt: '2026-10-11T04:00:00+00:00', deliveredAt: null, readAt: null, ...overrides,
    };
}

beforeEach(() => {
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['whatsapp_marketing.conversations.view'];
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('whatsapp helpers', () => {
    it('maps statuses to tones', () => {
        expect(templateStatusTone('approved')).toBe('ok');
        expect(templateStatusTone('pending')).toBe('info');
        expect(templateStatusTone('paused')).toBe('warn');
        expect(templateStatusTone('rejected')).toBe('danger');
        expect(templateStatusTone('disabled')).toBe('neutral');
        expect(campaignStatusTone('completed')).toBe('ok');
        expect(campaignStatusTone('failed')).toBe('danger');
        expect(recipientStateTone('blocked')).toBe('warn');
        expect(recipientStateTone('read')).toBe('ok');
        expect(messageStatusTone('failed')).toBe('danger');
        expect(messageStatusTone('queued')).toBe('neutral');
    });

    it('computes the time left in the 24-hour window', () => {
        const now = Date.parse('2026-10-11T00:00:00Z');

        expect(windowRemaining(null, now).open).toBe(false);
        expect(windowRemaining('2026-10-10T23:59:00Z', now).open).toBe(false);
        expect(windowRemaining('nonsense', now).open).toBe(false);

        const left = windowRemaining('2026-10-11T05:20:30Z', now);
        expect(left).toMatchObject({ open: true, hours: 5, minutes: 21 });
        expect(windowRemaining('2026-10-11T00:00:10Z', now)).toMatchObject({ open: true, hours: 0, minutes: 1 });
    });

    it('reads a local time in the workspace zone as an instant', () => {
        expect(zonedToIso('2026-10-20T14:30', 'Asia/Dhaka')).toBe('2026-10-20T08:30:00.000Z');
        expect(zonedToIso('2026-10-20T14:30', 'UTC')).toBe('2026-10-20T14:30:00.000Z');
        expect(zonedToIso('', 'UTC')).toBeNull();
    });

    it('builds the variable map the server validates', () => {
        const rows = variableRows(3, { '1': { type: 'field', field: 'contact.first_name' }, '2': { type: 'text', value: 'Eid' } });

        expect(rows.map((row) => row.kind)).toEqual(['field', 'text', 'field']);
        expect(variablesComplete(rows)).toBe(false);
        expect(buildVariableMap(rows)).toEqual({ '1': { type: 'field', field: 'contact.first_name' }, '2': { type: 'text', value: 'Eid' } });

        rows[2]!.field = 'workspace.name';
        expect(variablesComplete(rows)).toBe(true);
        expect(buildVariableMap(rows)['3']).toEqual({ type: 'field', field: 'workspace.name' });
        expect(buildVariableMap([{ position: 1, kind: 'text', field: '', value: '   ' }])).toEqual({});
    });

    it('renders and counts template positions', () => {
        expect(renderTemplateBody('Hi {{1}}, order {{2}}', ['Ana', ''])).toBe('Hi Ana, order {{2}}');
        expect(countTemplateVariables('Hi {{1}} and {{ 3 }}')).toBe(3);
        expect(countTemplateVariables('No values')).toBe(0);
    });

    it('keeps keywords normalised, unique and limited', () => {
        let list: string[] = [];
        list = addKeyword(list, '  HELLO   World ', 3);
        list = addKeyword(list, 'hello world', 3);
        list = addKeyword(list, '', 3);
        list = addKeyword(list, 'price', 3);
        list = addKeyword(list, 'hours', 3);
        list = addKeyword(list, 'one more', 3);

        expect(list).toEqual(['hello world', 'price', 'hours']);
        expect(addKeyword([], 'x'.repeat(41), 3)).toEqual([]);
    });

    it('builds a flow payload with the menu last and the limits applied', () => {
        const limits = { actions: 3, options: 2, optionActions: 2, keywords: 2 };
        const form = emptyFlowForm();
        form.name = '  Support  ';
        form.keywords = ['help', 'price', 'extra'];
        form.steps = [
            { type: 'reply', text: ' Hello ' },
            { type: 'reply', text: '   ' },
            { type: 'tag', tag: ' VIP ' },
            { type: 'handoff' },
            { type: 'assign', assignee_id: '' },
        ];
        form.menu = {
            prompt: ' Pick one ',
            options: [
                { label: ' Sales ', actions: [newStep('tag'), { type: 'reply', text: 'ok' }, { type: 'handoff' }] },
                { label: '', actions: [{ type: 'handoff' }] },
                { label: 'Support', actions: [{ type: 'handoff' }] },
                { label: 'Third', actions: [{ type: 'handoff' }] },
            ],
        };

        const payload = buildFlowPayload(form, limits) as { name: string; keywords: string[]; actions: Array<Record<string, unknown>>; match_type: string };
        const types = payload.actions.map((step) => step.type);

        expect(payload.name).toBe('Support');
        expect(payload.keywords).toEqual(['help', 'price']);
        expect(types).toEqual(['reply', 'tag', 'menu']);
        expect(payload.actions[0]).toEqual({ type: 'reply', text: 'Hello' });
        expect(payload.actions[1]).toEqual({ type: 'tag', tag: 'vip' });

        const menu = payload.actions[2] as { prompt: string; options: Array<{ label: string; actions: unknown[] }> };
        expect(menu.prompt).toBe('Pick one');
        expect(menu.options.map((option) => option.label)).toEqual(['Sales', 'Support']);
        expect(menu.options[0]!.actions).toHaveLength(2);
    });

    it('sends a welcome flow without a match type or keywords', () => {
        const form = emptyFlowForm();
        form.name = 'Welcome';
        form.trigger = 'welcome';
        form.keywords = ['stale'];
        form.steps = [{ type: 'reply', text: 'Welcome' }];

        const payload = buildFlowPayload(form, { actions: 10, options: 8, optionActions: 5, keywords: 10 });

        expect(payload.match_type).toBeNull();
        expect(payload.keywords).toEqual([]);
        expect(flowFormValid(form)).toBe(true);
    });

    it('rejects an incomplete flow form', () => {
        const form = emptyFlowForm();
        expect(flowFormValid(form)).toBe(false);

        form.name = 'Help';
        form.steps = [{ type: 'reply', text: 'Hi' }];
        expect(flowFormValid(form)).toBe(false);

        form.keywords = ['help'];
        expect(flowFormValid(form)).toBe(true);

        form.menu = { prompt: 'Choose', options: [{ label: 'A', actions: [] }] };
        expect(flowFormValid(form)).toBe(false);
    });

    it('round-trips a flow from the server shape', () => {
        const flow: FlowRow = {
            id: 'f-1', name: 'Hours', trigger: 'keyword', matchType: 'exact', keywords: ['hours'], priority: 5, isActive: false,
            actions: [{ type: 'reply', text: 'Nine to five' }, { type: 'menu', prompt: 'More?', options: [{ label: 'Yes', actions: [{ type: 'handoff' }] }] }],
        };
        const form = flowToForm(flow);

        expect(form.steps).toEqual([{ type: 'reply', text: 'Nine to five' }]);
        expect(form.menu?.options[0]?.label).toBe('Yes');
        expect(form.match_type).toBe('exact');
        expect(form.is_active).toBe(false);
    });

    it('cleans template buttons', () => {
        expect(
            buildButtons([
                { type: 'quick_reply', text: ' Yes ', value: 'ignored' },
                { type: 'url', text: 'Site', value: ' https://example.com ' },
                { type: 'phone_number', text: '', value: '+8801' },
                { type: 'url', text: 'More', value: '' },
            ]),
        ).toEqual([
            { type: 'quick_reply', text: 'Yes', value: null },
            { type: 'url', text: 'Site', value: 'https://example.com' },
            { type: 'url', text: 'More', value: null },
        ]);
    });

    it('formats small things', () => {
        expect(basisPointsToPercent(8250)).toBe(82.5);
        expect(basisPointsToPercent(null)).toBeNull();
        expect(appendText('', 'Hi')).toBe('Hi');
        expect(appendText('Hello  ', 'Hi')).toBe('Hello\nHi');
        expect(initialsOf('Nadia Karim Rahman')).toBe('NK');
        expect(initialsOf('+8801700000000')).toBe('');
    });
});

describe('whatsapp components', () => {
    it('shows the status badge in the right words', () => {
        const wrapper = track(mount(TemplateStatusBadge, { props: { status: 'approved' } }));
        expect(wrapper.text()).toBe('Approved');

        const rejected = track(mount(TemplateStatusBadge, { props: { status: 'rejected' } }));
        expect(rejected.text()).toBe('Rejected');
    });

    it('lets an agent write freely while the window is open', () => {
        const wrapper = composer();

        expect(wrapper.find('[data-testid="composer-free-text"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="composer-template"]').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(true);
    });

    it('asks for a template once the window has closed', () => {
        const wrapper = composer({ windowOpen: false, windowExpiresAt: null });

        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.get('[data-testid="window-closed"]').text()).toBe('The 24-hour window has closed. Send an approved template instead.');
        expect(wrapper.find('[data-testid="composer-template"]').exists()).toBe(true);
    });

    it('treats an expired timestamp as a closed window even when the server said open', () => {
        const wrapper = composer({ windowOpen: true, windowExpiresAt: new Date(Date.now() - 60_000).toISOString() });

        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.find('[data-testid="composer-template"]').exists()).toBe(true);
    });

    it('explains when the number is not a contact, when WhatsApp is down and when the person may not reply', () => {
        expect(composer({ hasContact: false }).find('[data-testid="composer-no-contact"]').exists()).toBe(true);
        expect(composer({ channel: down }).find('[data-testid="composer-not-connected"]').exists()).toBe(true);

        const readOnly = composer({ canReply: false });
        expect(readOnly.find('[data-testid="composer-readonly"]').exists()).toBe(true);
        expect(readOnly.find('textarea').exists()).toBe(false);
    });

    it('renders customer text as text and attachments as metadata', () => {
        const hostile = '<img src=x onerror=alert(1)> **bold**';
        const wrapper = track(mount(MessageBubble, {
            props: { message: message({ body: hostile, type: 'image', attachments: [{ kind: 'image', mime: 'image/jpeg', has_caption: true }] }) },
        }));

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain(hostile);
        expect(wrapper.get('[data-testid="attachment"]').text()).toBe('Image attachment (not downloaded)');
    });

    it('shows the delivery state and a translated failure reason', () => {
        const wrapper = track(mount(MessageBubble, {
            props: { message: message({ direction: 'outbound', senderType: 'agent', author: 'Nadia', status: 'failed', failureCode: 'no_address' }) },
        }));

        expect(wrapper.get('[data-testid="delivery-status"]').text()).toContain('Not delivered');
        expect(wrapper.get('[data-testid="failure-reason"]').text()).toBe('The contact has no valid WhatsApp number');

        const unknown = track(mount(MessageBubble, {
            props: { message: message({ direction: 'outbound', senderType: 'bot', status: 'failed', failureCode: '131026' }) },
        }));
        expect(unknown.get('[data-testid="failure-reason"]').text()).toBe('The provider reported the code 131026');
    });
});

describe('whatsapp widget', () => {
    it('shows the counts of a connected inbox', () => {
        const wrapper = track(mount(WhatsappOverviewWidget, { props: { data: { open: 12, unassigned: 4, mine: 3, unread: 5, campaigns: 2, connected: true } } }));

        expect(wrapper.text()).toContain('12');
        expect(wrapper.text()).toContain('Unassigned');
        expect(wrapper.find('[data-testid="widget-not-connected"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="widget-campaigns"]').text()).toBe('Campaigns running: 2');
    });

    it('is honest when WhatsApp is not connected and there is nothing open', () => {
        const wrapper = track(mount(WhatsappOverviewWidget, { props: { data: { open: 0, unassigned: 0, mine: 0, unread: 0, campaigns: 0, connected: false } } }));

        expect(wrapper.get('[data-testid="widget-not-connected"]').text()).toBe('WhatsApp is not connected yet.');
        expect(wrapper.text()).toContain('No open conversations');
    });

    it('speaks Bengali with Bengali digits', () => {
        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const wrapper = track(mount(WhatsappOverviewWidget, { props: { data: { open: 7, unassigned: 1, mine: 2, unread: 3, campaigns: 0, connected: true } } }));

        expect(wrapper.text()).toContain('৭');
        expect(wrapper.text()).toContain('হোয়াটসঅ্যাপ ইনবক্স');
    });
});

describe('whatsapp analytics page', () => {
    it('never shows a cost figure, only that none is reported', () => {
        inertia.page.props.auth.permissions = ['whatsapp_marketing.analytics.view'];
        const wrapper = track(mount(AnalyticsIndex, {
            props: {
                days: 30,
                overview: {
                    conversations: { new: 3, inbound: 10, outbound: 4, campaign: 20, open: 2, unassigned: 1, firstResponseAverage: 95 },
                    windows: { customer: 2, business: 1, used: 3, limit: 100 },
                    delivery: { sent: 24, delivered: 20, read: 10, failed: 1, blocked: 0, deliveredBps: 8333, readBps: 5000 },
                    providerReported: { billable: 5, notBillable: 2, notReported: 1, byCategory: { marketing: 5 } },
                    cost: null,
                    campaigns: [],
                },
                daily: [{ date: '2026-10-10', newConversations: 1, inbound: 3, outbound: 1, campaign: 0, windowsCustomer: 1, windowsBusiness: 0 }],
            },
        }));

        expect(wrapper.get('[data-testid="cost-not-reported"]').text()).toBe('Not reported: the provider webhooks carry no amount.');
        expect(wrapper.text()).toContain('83.3%');
        expect(wrapper.text()).toContain('No campaigns started in this period');
    });
});

describe('whatsapp messages', () => {
    it('has the same keys in Bengali as in English', () => {
        expect([...bengali.keys()].sort()).toEqual([...english.keys()].sort());
    });

    it('keeps placeholders and has no empty or unsafe messages', () => {
        const placeholders = (text: string): string => [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort().join();
        const mismatched = [...english].filter(([key, value]) => placeholders(value) !== placeholders(bengali.get(key) ?? '')).map(([key]) => key);
        expect(mismatched).toEqual([]);

        for (const messages of [english, bengali]) {
            expect([...messages].filter(([, value]) => value.trim() === '' || /[@|]/.test(value) || value.includes('{{')).map(([key]) => key)).toEqual([]);
        }
    });

    it('never types digits into the Bengali text', () => {
        const withDigits = [...bengali].filter(([, value]) => /[0-9০-৯]/.test(value)).map(([key]) => key);

        expect(withDigits).toEqual([]);
    });

    it('labels every enum the server sends', () => {
        const groups: Record<string, string[]> = {
            templateStatus: ['pending', 'approved', 'rejected', 'paused', 'disabled'],
            templateCategory: ['marketing', 'utility', 'authentication'],
            campaignStatus: ['draft', 'scheduled', 'sending', 'completed', 'cancelled', 'failed'],
            recipientState: ['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'blocked', 'skipped'],
            messageStatus: ['received', 'queued', 'sent', 'delivered', 'read', 'failed', 'blocked'],
            sender: ['customer', 'agent', 'bot', 'campaign', 'automation'],
            conversationStatus: ['open', 'closed'],
            buttonType: ['quick_reply', 'url', 'phone_number'],
            'flows|step': ['reply', 'tag', 'assign', 'handoff', 'menu'],
            reasons: [
                'ok', 'no_consent', 'opted_out', 'suppressed', 'unsubscribed', 'channel_unavailable', 'no_address', 'unknown_contact',
                'module_unavailable', 'channel_disabled', 'provider_not_configured', 'send_refused', 'invalid_phone', 'cancelled',
            ],
            'settings|reasons': ['module_unavailable', 'channel_disabled', 'provider_not_configured'],
            'fields|contact': ['first_name', 'last_name', 'full_name'],
        };
        const missing = Object.entries(groups).flatMap(([group, values]) => values.filter((value) => !english.has(`${group}|${value}`)).map((value) => `${group}.${value}`));

        expect(missing).toEqual([]);
        expect(english.has('fields|company|name')).toBe(true);
        expect(english.has('fields|workspace|name')).toBe(true);
        expect(english.has('fields|agent|name')).toBe(true);
    });

    it('translates every key used by the pages', () => {
        const used = new Set<string>();
        const dynamic = new Set<string>();
        const walk = (directory: string): void => {
            for (const name of readdirSync(directory)) {
                const path = join(directory, name);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (/\.(vue|ts)$/.test(name)) {
                    const source = readFileSync(path, 'utf8');
                    const stripped = source.replace(/route\(\s*['`][^'`]*['`]/g, '').replace(/\bname:\s*['`][^'`]*['`]/g, '');

                    for (const match of stripped.matchAll(/['`]whatsapp\.([A-Za-z0-9_.${}]+)['`]/g)) {
                        const key = match[1]!;

                        if (key.includes('${')) {
                            dynamic.add(key.split('.${')[0]!.replaceAll('.', '|'));
                        } else {
                            used.add(key.replaceAll('.', '|'));
                        }
                    }
                }
            }
        };
        walk(join(process.cwd(), 'resources/js/Modules/WhatsappMarketing'));

        const known = [...english.keys()];
        const missing = [...used].filter((key) => !known.some((candidate) => candidate === key || candidate.startsWith(`${key}|`)));
        const missingGroups = [...dynamic].filter((key) => !known.some((candidate) => candidate.startsWith(`${key}|`)));

        expect(missing).toEqual([]);
        expect(missingGroups).toEqual([]);
    });
});

describe('whatsapp catalogue', () => {
    const catalogue = en.catalogue as unknown as {
        modules: Record<string, string>;
        permissionGroups: Record<string, string>;
        permissions: Record<string, { label: string; description: string }>;
        limits: Record<string, unknown>;
        events: Record<string, string>;
        eventFamilies: Record<string, string>;
        automation: { triggers: Record<string, string>; actions: Record<string, string> };
    };
    const bnCatalogue = bn.catalogue as unknown as typeof catalogue;

    it('names the module, groups, permissions, limits, events and automation in both languages', () => {
        for (const source of [catalogue, bnCatalogue]) {
            expect(source.modules.whatsapp_marketing).toBeTruthy();
            expect(
                ['whatsapp_conversations', 'whatsapp_templates', 'whatsapp_campaigns', 'whatsapp_lists', 'whatsapp_quick_replies', 'whatsapp_configuration', 'whatsapp_analytics']
                    .filter((key) => !source.permissionGroups[key]),
            ).toEqual([]);
            expect(Object.keys(source.permissions).filter((key) => key.startsWith('whatsapp_marketing.'))).toHaveLength(16);
            expect(source.limits['whatsapp_marketing.conversations_per_month']).toBeTruthy();
            expect(source.limits['whatsapp_marketing.agents']).toBeTruthy();
            expect(source.events['whatsapp_marketing.conversation.assigned']).toBeTruthy();
            expect(source.events['whatsapp_marketing.flow.updated']).toBeTruthy();
            expect(source.eventFamilies.whatsapp_marketing).toBeTruthy();
            expect(Object.keys(source.automation.triggers).filter((key) => key.startsWith('whatsapp_marketing.'))).toHaveLength(4);
            expect(Object.keys(source.automation.actions).filter((key) => key.startsWith('whatsapp_marketing.'))).toHaveLength(3);
        }
    });

    it('names the triggers and actions in the automation messages', () => {
        const automation = en.automation as unknown as { triggers: Record<string, string>; actions: Record<string, string> };
        const bnAutomation = bn.automation as unknown as typeof automation;

        for (const source of [automation, bnAutomation]) {
            expect(source.triggers['whatsapp_marketing.message.received']).toBeTruthy();
            expect(source.actions['whatsapp_marketing.template.send']).toBeTruthy();
        }
    });
});
