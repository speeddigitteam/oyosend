import { mount } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['ai.assistant.use', 'ai.usage.view', 'ai.settings.manage'] as string[] },
            errors: {} as Record<string, string>,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...structuredClone(initial),
            errors: {},
            processing: false,
            clearErrors: () => {},
            reset: () => {},
            transform: () => ({ put: () => {}, post: () => {} }),
            post: () => {},
            put: () => {},
        }),
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, unknown> = {}) => `/${name}?${new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)])).toString()}`,
}));

import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import { barWidth, canCompose, proposalTone, usedPercent } from '@/Modules/Ai/Components/aiFormat';
import AiUsageWidget from '@/Modules/Ai/Components/AiUsageWidget.vue';
import type { Availability } from '@/Modules/Ai/Components/types';
import AssistantIndex from '@/Modules/Ai/Pages/Assistant/Index.vue';
import AssistantShow from '@/Modules/Ai/Pages/Assistant/Show.vue';
import SettingsIndex from '@/Modules/Ai/Pages/Settings/Index.vue';
import UsageIndex from '@/Modules/Ai/Pages/Usage/Index.vue';

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

function placeholders(message: string): string[] {
    return [...message.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();
}

const english = flatten(en.ai as unknown as Tree);
const bengali = flatten(bn.ai as unknown as Tree);

const stubs = {
    stubs: { Teleport: true, Transition: false },
};

const ready: Availability = {
    state: 'ready',
    message: 'AI is ready.',
    configured: true,
    provider: 'anthropic',
    model: 'claude-sonnet-5-5',
    features: { chat: { state: 'ready', message: '' }, drafting: { state: 'ready', message: '' }, automation: { state: 'disabled', message: '' } },
};

const notConfigured: Availability = {
    ...ready,
    state: 'not_configured',
    message: 'AI is not configured on this platform yet.',
    configured: false,
    provider: 'anthropic',
    model: '',
    features: { chat: { state: 'not_configured', message: '' }, drafting: { state: 'not_configured', message: '' }, automation: { state: 'disabled', message: '' } },
};

const conversations = [{ id: 'c1', title: 'Acme contacts', lastMessageAt: '2026-10-10T08:00:00Z' }];

describe('ai locale', () => {
    it('has the same keys in Bengali as in English', () => {
        expect([...bengali.keys()].sort()).toEqual([...english.keys()].sort());
    });

    it('keeps placeholders and has no empty or unsafe messages', () => {
        const mismatched = [...english].filter(([key, value]) => placeholders(value).join() !== placeholders(bengali.get(key) ?? '').join()).map(([key]) => key);
        expect(mismatched).toEqual([]);

        for (const messages of [english, bengali]) {
            expect([...messages].filter(([, value]) => value.trim() === '' || /[@|]/.test(value)).map(([key]) => key)).toEqual([]);
        }
    });

    it('translates every key used by the pages', () => {
        const used = new Set<string>();
        const walk = (directory: string): void => {
            for (const name of readdirSync(directory)) {
                const path = join(directory, name);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (/\.(vue|ts)$/.test(name)) {
                    const source = readFileSync(path, 'utf8').replace(/route\(\s*['`][^'`]*['`]/g, '');

                    for (const match of source.matchAll(/\bte?\(\s*['`]ai\.([A-Za-z0-9_.]+)['`]/g)) {
                        used.add(match[1]!.replaceAll('.', '|'));
                    }
                }
            }
        };
        walk(join(process.cwd(), 'resources/js/Modules/Ai'));

        const missing = [...used].filter((key) => ![...english.keys()].some((known) => known === key || known.startsWith(`${key}|`)));

        expect(missing).toEqual([]);
    });

    it('has the navigation labels the manifest names', () => {
        const nav = (en.shell as unknown as { nav: Record<string, string> }).nav;
        const bnNav = (bn.shell as unknown as { nav: Record<string, string> }).nav;

        for (const key of ['aiAssistant', 'aiUsage', 'aiSettings']) {
            expect(nav[key], key).toBeTruthy();
            expect(bnNav[key], key).toBeTruthy();
        }
    });

    it('translates every state, status and feature the server sends', () => {
        const expected = [
            ...['ready', 'not_configured', 'unavailable', 'disabled', 'limit_reached', 'rate_limited', 'too_long'].flatMap((state) => [`state|${state}|title`, `state|${state}|body`]),
            ...['pending', 'confirmed', 'rejected', 'failed', 'expired'].map((status) => `proposal|status|${status}`),
            ...['chat', 'drafting', 'automation'].map((feature) => `feature|${feature}`),
        ];

        expect(expected.filter((key) => !english.has(key) || !bengali.has(key))).toEqual([]);
    });
});

describe('ai figures', () => {
    it('computes the share used and bar widths', () => {
        expect(usedPercent({ limit: 1000, used: 250, remaining: 750 })).toBe(25);
        expect(usedPercent({ limit: 1000, used: 4000, remaining: 0 })).toBe(100);
        expect(usedPercent({ limit: null, used: 10, remaining: null })).toBeNull();
        expect(barWidth(50, 200)).toBe(25);
        expect(barWidth(0, 200)).toBe(0);
        expect(barWidth(1, 100000)).toBe(2);
    });

    it('only offers the composer when something can be generated', () => {
        expect(canCompose('ready')).toBe(true);
        expect(canCompose('rate_limited')).toBe(true);
        for (const state of ['not_configured', 'disabled', 'limit_reached', 'unavailable']) {
            expect(canCompose(state)).toBe(false);
        }
        expect(proposalTone('confirmed')).toBe('ok');
    });
});

describe('assistant index', () => {
    const props = {
        conversations,
        tools: [
            { name: 'contacts_search', description: 'Search contacts', proposes: false },
            { name: 'contacts_propose_create', description: 'Propose a contact', proposes: true },
        ],
        canConfirm: true,
    };

    it('mounts with the history, the tools and an enabled composer when ready', () => {
        const wrapper = mount(AssistantIndex, { props: { ...props, availability: ready }, global: stubs });

        expect(wrapper.text()).toContain('Acme contacts');
        expect(wrapper.get('[data-testid="tools"]').text()).toContain('Search contacts');
        expect(wrapper.find('[data-testid="ai-availability"]').exists()).toBe(false);
        expect(wrapper.get('textarea').attributes('disabled')).toBeUndefined();
    });

    it('shows the honest not_configured state and offers no composer', () => {
        const wrapper = mount(AssistantIndex, { props: { ...props, availability: notConfigured }, global: stubs });
        const notice = wrapper.get('[data-testid="ai-availability"]');

        expect(notice.attributes('data-state')).toBe('not_configured');
        expect(notice.text()).toContain('AI is not set up on this platform yet');
        expect(wrapper.get('textarea').attributes('disabled')).toBeDefined();
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});

describe('assistant show', () => {
    const props = {
        conversation: conversations[0]!,
        conversations,
        messages: [
            { id: 'm1', role: 'user' as const, content: 'Who is Acme?', aiGenerated: false, toolCalls: [], inputTokens: null, outputTokens: null, createdAt: '2026-10-10T08:00:00Z' },
            {
                id: 'm2',
                role: 'assistant' as const,
                content: '<b>Acme</b> is a company.',
                aiGenerated: true,
                toolCalls: [{ tool: 'companies_search', status: 'ok' }],
                inputTokens: 10,
                outputTokens: 5,
                createdAt: '2026-10-10T08:00:05Z',
            },
        ],
        proposals: [
            { id: 'p1', tool: 'contacts_propose_create', summary: 'Create contact Jo', changes: [{ field: 'first_name', old: null, new: 'Jo' }, { field: 'job_title', old: 'Buyer', new: '<b>CEO</b>' }], status: 'pending' as const, pending: true, expiresAt: '2026-10-11T08:00:00Z', decidedAt: null },
            { id: 'p2', tool: 'contacts_propose_update', summary: 'Rename contact', changes: [], status: 'rejected' as const, pending: false, expiresAt: '2026-10-11T08:00:00Z', decidedAt: '2026-10-10T09:00:00Z' },
        ],
        availability: ready,
        canConfirm: true,
    };

    it('renders model output as text, labels it AI-generated and lists proposals', () => {
        const wrapper = mount(AssistantShow, { props, global: stubs });
        const messages = wrapper.get('[data-testid="messages"]');

        expect(messages.text()).toContain('<b>Acme</b> is a company.');
        expect(messages.find('b').exists()).toBe(false);
        expect(messages.text()).toContain('AI-generated');
        expect(messages.text()).toContain('Search companies');

        const proposals = wrapper.get('[data-testid="proposals"]');
        expect(proposals.text()).toContain('Create contact Jo');
        expect(proposals.text()).toContain('Apply change');
        expect(proposals.findAll('button')).toHaveLength(2);
    });

    it('shows the old and new values a pending proposal would write, as plain text', () => {
        const wrapper = mount(AssistantShow, { props, global: stubs });
        const changes = wrapper.get('[data-testid="proposal-changes"]');

        expect(changes.text()).toContain('First name');
        expect(changes.text()).toContain('New: Jo');
        expect(changes.text()).toContain('Now: Buyer');
        expect(changes.text()).toContain('New: <b>CEO</b>');
        expect(changes.find('b').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="proposal-changes"]')).toHaveLength(1);
    });

    it('hides the apply control without the confirm permission', () => {
        const wrapper = mount(AssistantShow, { props: { ...props, canConfirm: false }, global: stubs });
        const proposals = wrapper.get('[data-testid="proposals"]');

        expect(proposals.text()).not.toContain('Apply change');
        expect(proposals.text()).toContain('You do not have permission to apply changes');
    });

    it('disables the reply box when AI is not configured', () => {
        const wrapper = mount(AssistantShow, { props: { ...props, availability: notConfigured }, global: stubs });

        expect(wrapper.get('[data-testid="ai-availability"]').attributes('data-state')).toBe('not_configured');
        expect(wrapper.get('[data-testid="reply"]').attributes('disabled')).toBeDefined();
    });
});

describe('settings', () => {
    const props = {
        settings: { enabled: true, chatEnabled: true, draftingEnabled: true, automationEnabled: false, voiceEnabled: false, dataScopes: ['contacts'], retentionDays: 90, redactPii: false },
        options: { dataScopes: ['contacts', 'companies'], retention: { min: 1, max: 365 }, platformRedaction: false },
    };

    it('mounts with switches and readable scopes', () => {
        const wrapper = mount(SettingsIndex, { props: { ...props, availability: ready }, global: stubs });

        expect(wrapper.text()).toContain('Turn AI on for this workspace');
        expect(wrapper.text()).toContain('Contacts');
        expect(wrapper.text()).toContain('claude-sonnet-5-5');
        expect(wrapper.text()).not.toMatch(/api[_ -]?key/i);
    });

    it('says the credential is not set up and shows no provider or model', () => {
        const wrapper = mount(SettingsIndex, { props: { ...props, availability: notConfigured }, global: stubs });

        expect(wrapper.get('[data-testid="provider"]').text()).toContain('Not set up');
        expect(wrapper.get('[data-testid="provider"]').text()).not.toContain('anthropic');
        expect(wrapper.get('[data-testid="ai-availability"]').attributes('data-state')).toBe('not_configured');
    });

    it('names every feature next to its state, however long the state is', () => {
        const wrapper = mount(SettingsIndex, { props: { ...props, availability: notConfigured }, global: stubs });
        const features = wrapper.get('[data-testid="features"]').findAll('li');

        expect(features.map((item) => item.find('span').text())).toEqual(['Assistant chat', 'Drafting', 'Workflow text']);
        expect(features.every((item) => !item.find('span').classes().includes('truncate'))).toBe(true);
    });
});

describe('usage', () => {
    it('shows the allowance and the breakdown', () => {
        const wrapper = mount(UsageIndex, {
            props: {
                allowance: { limit: 1000, used: 250, remaining: 750 },
                breakdown: {
                    byFeature: [{ feature: 'chat', tokens: 200, requests: 4 }, { feature: 'mystery', tokens: 50, requests: 1 }],
                    byDay: [{ date: '2026-10-09', tokens: 250 }],
                },
                availability: ready,
            },
            global: stubs,
        });

        expect(wrapper.get('[data-testid="allowance"]').text()).toContain('25%');
        expect(wrapper.get('[data-testid="by-feature"]').text()).toContain('Assistant chat');
        expect(wrapper.get('[data-testid="by-feature"]').text()).toContain('Other');
        expect(wrapper.findAll('[data-testid="by-day"] li')).toHaveLength(1);
    });

    it('handles no limit and no usage, and keeps the not_configured notice', () => {
        const wrapper = mount(UsageIndex, {
            props: { allowance: { limit: null, used: 0, remaining: null }, breakdown: { byFeature: [], byDay: [] }, availability: notConfigured },
            global: stubs,
        });

        expect(wrapper.find('[data-testid="no-limit"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No AI use in the last 30 days');
        expect(wrapper.get('[data-testid="ai-availability"]').attributes('data-state')).toBe('not_configured');
    });
});

describe('dashboard widget', () => {
    it('mounts with an allowance and without one', () => {
        const limited = mount(AiUsageWidget, { props: { data: { limit: 1000, used: 100, remaining: 900, state: 'ready' } } });
        expect(limited.text()).toContain('AI is ready');
        expect(limited.text()).toContain('1,000');

        const open = mount(AiUsageWidget, { props: { data: { limit: null, used: 5, remaining: null, state: 'not_configured' } } });
        expect(open.text()).toContain('AI is not set up on this platform yet');
        expect(open.text()).toContain('No token limit on your plan');
    });
});
