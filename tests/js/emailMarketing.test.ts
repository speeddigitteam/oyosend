import { mount, type VueWrapper } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Visit { method: string; url: string }
interface Submission extends Visit { data: Record<string, unknown> }
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['email_marketing.campaigns.view'] as string[] },
            tenant: { name: 'Acme' },
            errors: {} as Record<string, string>,
        },
    },
    visits: [] as Visit[],
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
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
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key] instanceof File ? form[key] : JSON.parse(JSON.stringify(toRaw(form[key]) ?? null))]));
            inertia.visits.push({ method, url });
            inertia.submissions.push({ method, url, data: transform(data) });
            options?.onSuccess?.();
        };
        form.post = (url: string, options?: { onSuccess?: () => void }) => submit('post', url, options);
        form.put = (url: string, options?: { onSuccess?: () => void }) => submit('put', url, options);
        form.patch = (url: string, options?: { onSuccess?: () => void }) => submit('patch', url, options);
        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

vi.mock('@/Modules/Automation/lib/http', () => ({
    sendJson: vi.fn(async () => ({ ok: true, status: 200, message: null, errors: {}, data: { valid: true, errors: {}, subject: 'Hi Alex', html: '<p>x</p>', text: 'Hi Alex' } })),
}));

import { setLocale } from '@/Core/i18n';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import BlockEditor from '@/Modules/EmailMarketing/Components/BlockEditor.vue';
import EmailMarketingOverviewWidget from '@/Modules/EmailMarketing/Components/EmailMarketingOverviewWidget.vue';
import {
    delayMinutes,
    delayParts,
    isLocalDateTime,
    isSafeLink,
    moveItem,
    newBlock,
    newStep,
    percent,
    variableToken,
} from '@/Modules/EmailMarketing/Components/emailFormat';
import type { Block, CampaignDetail, CampaignSummary, MemberRow } from '@/Modules/EmailMarketing/Components/types';
import CampaignsIndex from '@/Modules/EmailMarketing/Pages/Campaigns/Index.vue';
import CampaignsShow from '@/Modules/EmailMarketing/Pages/Campaigns/Show.vue';
import ListsShow from '@/Modules/EmailMarketing/Pages/Lists/Show.vue';
import ReportsIndex from '@/Modules/EmailMarketing/Pages/Reports/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const figures = {
    planned: 4, pending: 0, held: 0, sending: 0, queued: 0, sent: 2, delivered: 1, failed: 0, bounced: 0, blocked: 1, skipped: 0, cancelled: 0,
    sentTotal: 3, clickers: 1, clicks: 2, viewers: 0, clickRate: 3333, viewRate: 0, bounceRate: null,
};

function summary(overrides: Partial<CampaignSummary> = {}): CampaignSummary {
    return {
        id: 'camp-1', name: 'Eid offers', subject: 'Eid offers for you', status: 'sent', listId: 'list-1', listName: 'Newsletter', segmentName: null,
        scheduledFor: null, startedAt: '2026-10-01T04:00:00+00:00', finishedAt: '2026-10-01T04:10:00+00:00', pausedReason: null, abEnabled: false,
        audienceTotal: 4, counts: { planned: 4, sent: 3, blocked: 1, skipped: 0, failed: 0, bounced: 0, pending: 0 },
        createdAt: '2026-09-30T04:00:00+00:00', owner: { id: 'm-1', name: 'Nadia Karim' }, ...overrides,
    };
}

function detail(overrides: Partial<CampaignDetail> = {}): CampaignDetail {
    return {
        ...summary(),
        preheader: null,
        blocks: [{ type: 'text', text: 'Hello', align: 'left' }],
        templateId: null,
        segmentId: null,
        trackClicks: true,
        scheduledForLocal: null,
        ab: { enabled: false, subjectB: null, percent: 20, waitHours: 4, metric: 'clicks', winner: null, winnerReason: null, decideAt: null, decidedAt: null },
        report: { figures, variants: {}, reasons: [{ reason: 'no_consent', status: 'blocked', count: 1 }] },
        preview: { subject: 'Eid offers for Alex', html: '<p>Hello Alex</p>', text: 'Hello Alex' },
        abilities: { edit: false, delete: false, schedule: false, unschedule: false, pause: false, resume: false, cancel: false, test: false, duplicate: false, export: false },
        ...overrides,
    };
}

function member(overrides: Partial<MemberRow> = {}): MemberRow {
    return {
        id: 'sub-1', listId: 'list-1', listName: 'Newsletter', contact: { id: 'c-1', name: 'Amina Rahman', email: 'amina@example.test' },
        status: 'subscribed', source: 'manual', consentMethod: 'website_form', consentEvidence: 'Form', consentedAt: null, subscribedAt: '2026-09-01T04:00:00+00:00',
        unsubscribedAt: null, statusReason: null, properties: {}, platform: { allowed: true, reason: 'ok' }, ...overrides,
    };
}

const paginated = <T,>(data: T[]) => ({ data, current_page: 1, last_page: 1, from: 1, to: data.length, total: data.length, prev_page_url: null, next_page_url: null });

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['email_marketing.campaigns.view'];
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('email marketing helpers', () => {
    it('builds a valid block of every type', () => {
        expect(newBlock('heading')).toEqual({ type: 'heading', text: '', level: 2, align: 'left' });
        expect(newBlock('image')).toMatchObject({ type: 'image', url: '', link_url: null, width: 600 });
        expect(newBlock('divider')).toEqual({ type: 'divider' });
        expect(newBlock('spacer')).toEqual({ type: 'spacer', height: 24 });
    });

    it('moves an item and ignores a move off either end', () => {
        expect(moveItem(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
        expect(moveItem(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
        expect(moveItem(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
        expect(moveItem(['a', 'b'], 1, 1)).toEqual(['a', 'b']);
    });

    it('splits a delay into the largest exact unit and back', () => {
        expect(delayParts(0)).toEqual({ value: 0, unit: 'minutes' });
        expect(delayParts(90)).toEqual({ value: 90, unit: 'minutes' });
        expect(delayParts(120)).toEqual({ value: 2, unit: 'hours' });
        expect(delayParts(2880)).toEqual({ value: 2, unit: 'days' });
        expect(delayMinutes(2, 'days')).toBe(2880);
        expect(delayMinutes(-3, 'hours')).toBe(0);
        expect(newStep(1).delayMinutes).toBe(0);
        expect(newStep(2).delayMinutes).toBe(1440);
    });

    it('accepts only the links the server accepts', () => {
        expect(isSafeLink('https://example.com/a?b=1')).toBe(true);
        expect(isSafeLink('http://example.com')).toBe(true);
        expect(isSafeLink('javascript:alert(1)')).toBe(false);
        expect(isSafeLink('data:text/html,hi')).toBe(false);
        expect(isSafeLink('https://user:pw@example.com')).toBe(false);
        expect(isSafeLink('mailto:a@example.com')).toBe(false);
        expect(isSafeLink('mailto:a@example.com', true)).toBe(true);
        expect(isSafeLink('{{unsubscribe_url}}')).toBe(true);
        expect(isSafeLink('')).toBe(false);
    });

    it('formats rates and tokens', () => {
        expect(percent(3333)).toBe(33.33);
        expect(percent(null)).toBeNull();
        expect(variableToken('first_name')).toBe('{{first_name}}');
        expect(isLocalDateTime('2026-10-20T14:30')).toBe(true);
        expect(isLocalDateTime('2026-10-20 14:30')).toBe(false);
    });
});

describe('block editor', () => {
    const variables = ['first_name', 'company', 'unsubscribe_url'];

    function editor(blocks: Block[], props: Record<string, unknown> = {}) {
        const updates: Block[][] = [];
        const wrapper = track(mount(BlockEditor, {
            props: { modelValue: blocks, variables, 'onUpdate:modelValue': (value: Block[]) => updates.push(value), ...props },
        }));

        return { wrapper, updates };
    }

    it('adds a block of the chosen type at the end', async () => {
        const { wrapper, updates } = editor([newBlock('text')]);
        const add = wrapper.findAll('[role="group"] button').find((button) => button.text() === 'Button');
        await add!.trigger('click');

        expect(updates.at(-1)!.map((block) => block.type)).toEqual(['text', 'button']);
    });

    it('moves and removes blocks, and cannot move past either end', async () => {
        const blocks: Block[] = [newBlock('heading'), newBlock('text'), newBlock('divider')];
        const { wrapper, updates } = editor(blocks);
        const items = wrapper.findAll('[data-testid="blocks"] > li');

        expect(items[0]!.get('button[aria-label="Move up"]').attributes('disabled')).toBeDefined();
        expect(items[2]!.get('button[aria-label="Move down"]').attributes('disabled')).toBeDefined();

        await items[1]!.get('button[aria-label="Move up"]').trigger('click');
        expect(updates.at(-1)!.map((block) => block.type)).toEqual(['text', 'heading', 'divider']);

        await items[2]!.get('button[aria-label="Remove block"]').trigger('click');
        expect(updates.at(-1)!.map((block) => block.type)).toEqual(['heading', 'text']);
    });

    it('edits text and appends a variable token from the allow-list only', async () => {
        const { wrapper, updates } = editor([{ type: 'text', text: 'Hello', align: 'left' }]);
        await wrapper.get('textarea').setValue('Hello there');
        expect(updates.at(-1)![0]).toMatchObject({ text: 'Hello there' });

        const select = wrapper.findAll('select').at(-1)!;
        expect([...select.findAll('option')].map((option) => option.text())).toEqual(['Add a variable', '{{first_name}}', '{{company}}', '{{unsubscribe_url}}']);
        await select.setValue('first_name');
        expect(updates.at(-1)![0]).toMatchObject({ text: 'Hello {{first_name}}' });
    });

    it('warns about a plain http image and an unsafe link before the server does', () => {
        const { wrapper } = editor([
            { type: 'image', url: 'http://example.com/a.png', alt: 'A', link_url: 'javascript:alert(1)', width: 600 },
            { type: 'button', label: 'Go', url: 'javascript:alert(1)', align: 'left', style: 'primary' },
        ]);

        expect(wrapper.text()).toContain('Use an https address.');
        expect(wrapper.text()).toContain('Use an https:// address.');
    });

    it('shows the server error of a field next to it', () => {
        const { wrapper } = editor([newBlock('heading')], { errors: { 'blocks.0.text': 'A heading needs text.' } });

        expect(wrapper.text()).toContain('A heading needs text.');
    });

    it('stops adding at the limit', () => {
        const { wrapper } = editor([newBlock('divider'), newBlock('divider')], { max: 2 });

        expect(wrapper.text()).toContain('An email can have at most 2 blocks.');
        expect(wrapper.findAll('[role="group"] button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    });
});

describe('campaign pages', () => {
    const index = {
        filters: { status: null, list_id: null, q: null },
        statuses: ['draft', 'sent'] as const,
        lists: [],
        canCreate: true,
    };

    it('lists campaigns with their status, list and sent count', () => {
        const wrapper = track(mount(CampaignsIndex, { props: { ...index, statuses: [...index.statuses], campaigns: paginated([summary()]) } }));

        expect(wrapper.text()).toContain('Eid offers');
        expect(wrapper.text()).toContain('Sent');
        expect(wrapper.text()).toContain('Newsletter');
        expect(wrapper.find('a[href="/email_marketing.campaigns.show?campaign=camp-1"]').exists()).toBe(true);
    });

    it('offers a new campaign only to someone who can create one', () => {
        const allowed = track(mount(CampaignsIndex, { props: { ...index, statuses: [...index.statuses], campaigns: paginated([]) } }));
        expect(allowed.find('a[href="/email_marketing.campaigns.create"]').exists()).toBe(true);

        const denied = track(mount(CampaignsIndex, { props: { ...index, statuses: [...index.statuses], canCreate: false, campaigns: paginated([]) } }));
        expect(denied.find('a[href="/email_marketing.campaigns.create"]').exists()).toBe(false);
    });

    it('explains why a paused campaign is paused', () => {
        const wrapper = track(mount(CampaignsIndex, {
            props: { ...index, statuses: [...index.statuses], campaigns: paginated([summary({ status: 'paused', pausedReason: 'limit_reached' })]) },
        }));

        expect(wrapper.text()).toContain('plan message limit was reached');
    });

    it('shows results honestly: opens are not measured and a missing rate is not zero', () => {
        const wrapper = track(mount(CampaignsShow, {
            props: {
                campaign: detail(), deliveries: paginated([]), deliveryStatus: null, members: [], maxTestRecipients: 5, timezone: 'Asia/Dhaka',
            },
            global: { stubs },
        }));

        const text = wrapper.get('[data-testid="campaign-figures"]').text();
        expect(text).toContain('Not measured');
        expect(text).toContain('33.3%');
        expect(wrapper.text()).toContain('No marketing consent recorded');
        expect(wrapper.text()).toContain('plain text');
        expect(wrapper.find('iframe').attributes('sandbox')).toBe('');
    });

    it('shows only the actions the campaign state and the person allow', () => {
        const none = track(mount(CampaignsShow, {
            props: { campaign: detail({ status: 'draft' }), deliveries: paginated([]), deliveryStatus: null, members: [], maxTestRecipients: 5, timezone: 'UTC' },
            global: { stubs },
        }));
        expect(none.text()).not.toContain('Send now');

        const sender = track(mount(CampaignsShow, {
            props: {
                campaign: detail({ status: 'draft', abilities: { ...detail().abilities, schedule: true, test: true, edit: true } }),
                deliveries: paginated([]), deliveryStatus: null, members: [{ id: 'm-1', name: 'Nadia', email: 'nadia@example.test' }], maxTestRecipients: 5, timezone: 'UTC',
            },
            global: { stubs },
        }));
        expect(sender.text()).toContain('Send now');
        expect(sender.text()).toContain('Send a test');
        expect(sender.text()).not.toContain('Back to draft');
    });

    it('sends a scheduled time in the shape the server expects', async () => {
        const wrapper = track(mount(CampaignsShow, {
            props: {
                campaign: detail({ status: 'draft', abilities: { ...detail().abilities, schedule: true } }),
                deliveries: paginated([]), deliveryStatus: null, members: [], maxTestRecipients: 5, timezone: 'Asia/Dhaka',
            },
            global: { stubs },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Schedule')!.trigger('click');
        await wrapper.get('input[type="datetime-local"]').setValue('2026-11-02T09:30');
        await wrapper.findAll('button').filter((button) => button.text() === 'Schedule').at(-1)!.trigger('click');

        const last = inertia.submissions.at(-1)!;
        expect(last.url).toBe('/email_marketing.campaigns.schedule?campaign=camp-1');
        expect(last.data).toEqual({ scheduled_for: '2026-11-02T09:30' });
    });
});

describe('members and lists', () => {
    const list = { id: 'list-1', name: 'Newsletter', description: null, status: 'active' as const, counts: { pending: 1, subscribed: 1, unsubscribed: 0, bounced: 0, complained: 0 }, createdAt: null };
    const props = {
        list,
        segments: [],
        filters: { status: null, segment: null, q: null },
        statuses: ['pending', 'subscribed'] as const,
        consentMethods: ['website_form', 'phone'],
        segmentFields: ['city'],
        segmentOperators: { city: ['equals'] },
        canManage: true,
        canManageSubscribers: true,
        canExport: true,
    };

    it('shows the platform answer for each address and never calls a pending member sendable', () => {
        const wrapper = track(mount(ListsShow, {
            props: {
                ...props,
                statuses: [...props.statuses],
                members: paginated([
                    member(),
                    member({ id: 'sub-2', status: 'pending', consentMethod: null, consentEvidence: null, contact: { id: 'c-2', name: 'Rafi Hasan', email: 'rafi@example.test' }, platform: { allowed: false, reason: 'no_consent' } }),
                ]),
            },
            global: { stubs },
        }));

        const text = wrapper.text();
        expect(text).toContain('Can receive email');
        expect(text).toContain('No marketing consent recorded');
        expect(text).toContain('Not sent to until consent is recorded.');
        expect(text).toContain('Record consent');
    });

    it('records consent evidence with a method and refuses a bare claim', async () => {
        const wrapper = track(mount(ListsShow, {
            props: {
                ...props,
                statuses: [...props.statuses],
                members: paginated([member({ status: 'pending', consentMethod: null, consentEvidence: null, platform: null })]),
            },
            global: { stubs },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Record consent')!.trigger('click');
        const submit = () => wrapper.findAll('button').filter((button) => button.text() === 'Save').at(-1)!;
        expect(submit().attributes('disabled')).toBeDefined();

        await wrapper.get('textarea').setValue('Ticked the box on the footer form');
        expect(submit().attributes('disabled')).toBeUndefined();
        await submit().trigger('click');
        await wrapper.get('form#member-consent').trigger('submit');

        const last = inertia.submissions.at(-1)!;
        expect(last.method).toBe('put');
        expect(last.url).toBe('/email_marketing.subscriptions.update?subscription=sub-1');
        expect(last.data).toMatchObject({ action: 'confirm', consent_method: 'website_form', consent_evidence: 'Ticked the box on the footer form' });
    });

    it('hides member actions from someone who may not manage subscribers', () => {
        const wrapper = track(mount(ListsShow, {
            props: { ...props, statuses: [...props.statuses], canManageSubscribers: false, canExport: false, canManage: false, members: paginated([member({ status: 'pending', platform: null })]) },
            global: { stubs },
        }));

        expect(wrapper.text()).not.toContain('Record consent');
        expect(wrapper.text()).not.toContain('Add subscriber');
        expect(wrapper.text()).not.toContain('Export CSV');
    });
});

describe('reports and widget', () => {
    it('states the period figures and that opens are not measured', () => {
        const wrapper = track(mount(ReportsIndex, {
            props: {
                days: 30, from: '2026-09-12T00:00:00+00:00', to: '2026-10-12T00:00:00+00:00',
                report: {
                    deliveries: figures,
                    campaigns: { finished: 2, active: 0, scheduled: 1 },
                    subscribers: { subscribed: 10, pending: 2, unsubscribed: 1, bounced: 0, complained: 0, joined: 4, left: 1 },
                    reasons: [{ reason: 'suppressed', count: 3 }],
                },
            },
        }));

        expect(wrapper.get('[data-testid="delivery-figures"]').text()).toContain('Not measured');
        expect(wrapper.get('[data-testid="people-figures"]').text()).toContain('Subscribed');
        expect(wrapper.get('[data-testid="reasons"]').text()).toContain('Address is suppressed');
    });

    it('renders the dashboard widget with and without a finished campaign', () => {
        const filled = track(mount(EmailMarketingOverviewWidget, {
            props: { data: { scheduled: 1, sending: 0, paused: 2, subscribers: 120, last: { id: 'c', name: 'Eid offers', finishedAt: null, sent: 90, blocked: 4, clickRate: 1250 } } },
        }));
        expect(filled.text()).toContain('120');
        expect(filled.get('[data-testid="email-marketing-last"]').text()).toContain('Eid offers');
        expect(filled.get('[data-testid="email-marketing-last"]').text()).toContain('12.5%');

        const empty = track(mount(EmailMarketingOverviewWidget, {
            props: { data: { scheduled: 0, sending: 0, paused: 0, subscribers: null, last: null } },
        }));
        expect(empty.text()).not.toContain('Subscribers');
        expect(empty.get('[data-testid="email-marketing-last"]').text()).toContain('No campaign has finished yet.');
    });
});

describe('Bengali', () => {
    it('reads the empty campaign list in Bengali', () => {
        setLocale('bn');
        const wrapper = track(mount(CampaignsIndex, {
            props: { campaigns: paginated([]), filters: { status: null, list_id: null, q: null }, statuses: [], lists: [], canCreate: true },
        }));

        expect(wrapper.text()).toContain(bn.emailMarketing.campaigns.empty);
        expect(wrapper.text()).not.toContain('emailMarketing.');
    });
});

describe('Email Marketing message coverage', () => {
    const root = join(process.cwd(), 'resources/js/Modules/EmailMarketing');

    function sources(dir: string): string[] {
        return readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);

            return statSync(path).isDirectory() ? sources(path) : /\.(vue|ts)$/.test(name) ? [path] : [];
        });
    }

    function lookup(tree: unknown, path: string): unknown {
        return path.split('.').reduce<unknown>((node, key) => (node !== null && typeof node === 'object' ? (node as Record<string, unknown>)[key] : undefined), tree);
    }

    it('has every statically used message key in English and Bengali', () => {
        const keys = new Set<string>();

        for (const file of sources(root)) {
            for (const match of readFileSync(file, 'utf8').matchAll(/\bt\('(emailMarketing\.[A-Za-z0-9_.]+)'/g)) {
                keys.add(match[1]!.replace(/^emailMarketing\./, ''));
            }
        }

        expect(keys.size).toBeGreaterThan(200);

        for (const key of keys) {
            expect(typeof lookup(en.emailMarketing, key), `en ${key}`).toBe('string');
            expect(typeof lookup(bn.emailMarketing, key), `bn ${key}`).toBe('string');
        }
    });

    it('covers every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'status.campaign': ['draft', 'scheduled', 'preparing', 'sending', 'awaiting_winner', 'paused', 'sent', 'cancelled'],
            'status.delivery': ['pending', 'held', 'sending', 'queued', 'sent', 'delivered', 'failed', 'bounced', 'blocked', 'skipped', 'cancelled'],
            'status.subscription': ['pending', 'subscribed', 'unsubscribed', 'bounced', 'complained'],
            'status.sequence': ['draft', 'active', 'paused'],
            'status.list': ['active', 'archived'],
            consentMethod: ['website_form', 'in_person', 'phone', 'email_reply', 'existing_customer', 'other'],
            'editor.types': ['heading', 'text', 'image', 'button', 'divider', 'spacer'],
            'segments.fields': ['email_domain', 'city', 'country', 'job_title', 'has_company', 'source', 'subscribed_after', 'subscribed_before', 'property'],
            'segments.operators': ['equals', 'not_equals', 'contains', 'exists', 'not_exists', 'is_true', 'is_false', 'on_or_after', 'on_or_before'],
            'sequences.units': ['minutes', 'hours', 'days'],
            'sequences.after': ['minutes', 'hours', 'days'],
            'sequences.enrollment': ['active', 'completed', 'stopped'],
            'ab.reason': ['clicks', 'views', 'tie', 'no_responses', 'not_measured'],
            reason: ['no_consent', 'opted_out', 'suppressed', 'unsubscribed', 'channel_unavailable', 'module_unavailable', 'no_address', 'unknown_contact', 'not_subscribed', 'no_unsubscribe_link', 'content_invalid', 'campaign_cancelled', 'limit_reached', 'other'],
            pauseReason: ['paused_by_member', 'limit_reached', 'channel_unavailable', 'module_unavailable', 'other'],
            'widget': ['scheduled', 'sending', 'paused', 'subscribers'],
            'report': ['planned', 'sent', 'blocked', 'skipped', 'bounced', 'failed'],
        };

        for (const [family, names] of Object.entries(families)) {
            for (const name of names) {
                expect(typeof lookup(en.emailMarketing, `${family}.${name}`), `en ${family}.${name}`).toBe('string');
                expect(typeof lookup(bn.emailMarketing, `${family}.${name}`), `bn ${family}.${name}`).toBe('string');
            }
        }
    });

    it('registers permissions, limits, navigation and automation text', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/EmailMarketing/EmailMarketingServiceProvider.php'), 'utf8');
        const permissions = [...manifest.matchAll(/new PermissionDefinition\('(email_marketing\.[\w.]+)'/g)].map((match) => match[1]!);
        const groups = new Set([...manifest.matchAll(/new PermissionDefinition\('email_marketing\.[\w.]+', '(\w+)'/g)].map((match) => match[1]!));
        const nav = [...manifest.matchAll(/new NavigationItem\('(emailMarketing\w+)'/g)].map((match) => match[1]!);

        expect(permissions).toHaveLength(18);
        expect(nav).toHaveLength(8);

        for (const messages of [en, bn]) {
            expect(typeof messages.catalogue.modules.email_marketing).toBe('string');
            expect(typeof messages.appStore.catalog.email_marketing.name).toBe('string');
            expect(messages.appStore.catalog.email_marketing.features).toHaveLength(6);

            for (const limit of ['email_marketing.campaigns_per_month', 'email_marketing.subscribers']) {
                expect((messages.catalogue.limits as Record<string, { label: string }>)[limit]?.label, limit).toBeTruthy();
            }

            for (const permission of permissions) {
                const entry = (messages.catalogue.permissions as Record<string, { label: string; description: string }>)[permission];
                expect(entry?.label, permission).toBeTruthy();
                expect(entry?.description, permission).toBeTruthy();
            }

            for (const group of groups) {
                expect((messages.catalogue.permissionGroups as Record<string, string>)[group], group).toBeTruthy();
            }

            for (const key of nav) {
                expect((messages.shell.nav as unknown as Record<string, string>)[key], key).toBeTruthy();
            }

            const automation = messages.catalogue.automation;
            expect(Object.keys(automation.triggers).filter((key) => key.startsWith('email_marketing.'))).toHaveLength(2);
            expect(Object.keys(automation.actions).filter((key) => key.startsWith('email_marketing.'))).toHaveLength(1);
            expect(messages.catalogue.eventFamilies.email_marketing).toBeTruthy();
        }
    });

    it('keeps the English app text identical to the manifest', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/EmailMarketing/EmailMarketingServiceProvider.php'), 'utf8');
        const text = en.appStore.catalog.email_marketing;

        expect(manifest).toContain(`name: '${text.name}'`);
        expect(manifest).toContain(`description: '${text.description}'`);
        expect(manifest).toContain(`releaseNotes: '${text.releaseNotes}'`);

        for (const feature of text.features) {
            expect(manifest).toContain(`'${feature}'`);
        }
    });

    it('never renders user content as HTML', () => {
        for (const file of sources(root).filter((path) => path.endsWith('.vue'))) {
            expect(readFileSync(file, 'utf8'), file).not.toContain('v-html');
        }
    });
});
