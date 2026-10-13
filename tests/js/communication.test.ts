import { mount, type VueWrapper } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Submission {
    method: string;
    url: string;
    data: Record<string, unknown>;
}

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: [] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        let defaults = structuredClone(initial);
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.defaults = (values: Record<string, unknown>) => {
            defaults = { ...defaults, ...structuredClone(values) };
            return form;
        };
        form.reset = () => {
            Object.assign(form, structuredClone(defaults));
            return form;
        };
        form.clearErrors = () => {};
        form.transform = (callback: (data: Record<string, unknown>) => Record<string, unknown>) => {
            transform = callback;
            return form;
        };
        const submit = (method: string, url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, JSON.parse(JSON.stringify(toRaw(form[key]) ?? null))]));
            inertia.submissions.push({ method, url, data: transform(data) });
            options?.onSuccess?.();
        };
        form.post = (url: string, options?: { onSuccess?: () => void }) => submit('post', url, options);
        form.put = (url: string, options?: { onSuccess?: () => void }) => submit('put', url, options);
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
import type { ChannelDescription } from '@/Modules/Communication/Components/ChannelCard.vue';
import NotificationsWidget from '@/Modules/Communication/Components/NotificationsWidget.vue';
import { addressFor, newRequestId, severityTone, statusTone } from '@/Modules/Communication/Components/communicationFormat';
import type { MessageDetail, MessageRow, NotificationItem } from '@/Modules/Communication/Components/types';
import AudienceIndex from '@/Modules/Communication/Pages/Audience/Index.vue';
import MessagesCompose from '@/Modules/Communication/Pages/Messages/Compose.vue';
import MessagesIndex from '@/Modules/Communication/Pages/Messages/Index.vue';
import MessagesShow from '@/Modules/Communication/Pages/Messages/Show.vue';
import NotificationsIndex from '@/Modules/Communication/Pages/Notifications/Index.vue';
import NotificationsPreferences from '@/Modules/Communication/Pages/Notifications/Preferences.vue';
import SettingsIndex from '@/Modules/Communication/Pages/Settings/Index.vue';
import TemplatesIndex from '@/Modules/Communication/Pages/Templates/Index.vue';
import TimelineShow from '@/Modules/Communication/Pages/Timeline/Show.vue';

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

const english = flatten(en.communication as unknown as Tree);
const bengali = flatten(bn.communication as unknown as Tree);

const mounted: VueWrapper[] = [];

function track<T extends VueWrapper>(wrapper: T): T {
    mounted.push(wrapper);

    return wrapper;
}

function paginated<T>(data: T[]) {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: data.length,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
        prev_page_url: null,
        next_page_url: null,
        links: [],
    };
}

function notification(overrides: Partial<NotificationItem> = {}): NotificationItem {
    return {
        id: 'n1',
        category: 'approvals',
        severity: 'info',
        title: 'Leave request from Nadia',
        body: null,
        titleKey: 'Leave request from :name',
        bodyKey: null,
        params: { name: 'Nadia' },
        url: '/hrm/leave/requests',
        read: false,
        archived: false,
        createdAt: '2026-10-11T09:00:00+00:00',
        ...overrides,
    };
}

function message(overrides: Partial<MessageRow> = {}): MessageRow {
    return {
        id: 'm1',
        channel: 'email',
        purpose: 'transactional',
        status: 'queued',
        recipient: 'rahim@example.com',
        subject: 'Your invoice',
        source: 'composer',
        contact: { id: 'c1', name: 'Rahim Uddin' },
        company: null,
        attempts: 0,
        failureCode: null,
        queuedAt: '2026-10-11T09:00:00+00:00',
        nextAttemptAt: '2026-10-11T09:00:00+00:00',
        sentAt: null,
        deliveredAt: null,
        failedAt: null,
        createdAt: '2026-10-11T09:00:00+00:00',
        retryable: false,
        cancellable: false,
        ...overrides,
    };
}

function detail(overrides: Partial<MessageDetail> = {}): MessageDetail {
    return { ...message(), body: 'Hello <b>Rahim</b>', locale: 'en', provider: 'mail', failureMessage: null, templateId: null, owner: { id: 'o1', name: 'Karim' }, ...overrides };
}

function channelCard(channel: 'email' | 'sms' | 'whatsapp', overrides: Partial<ChannelDescription> = {}): ChannelDescription {
    const none = channel !== 'email';

    return {
        channel,
        provider: none ? 'none' : 'mail',
        providerLabel: none ? 'No provider' : 'Platform mail',
        isTestDouble: false,
        enabled: true,
        saved: false,
        state: none ? 'not_configured' : 'configured',
        settings: {},
        credentialRef: null,
        webhookSecretRef: null,
        supportsWebhooks: false,
        rateLimitPerMinute: null,
        rateLimitCeiling: 60,
        providers: [{ key: none ? 'none' : 'mail', label: none ? 'No provider' : 'Platform mail', isTestDouble: false, supportsWebhooks: false, fields: [] }],
        ...overrides,
    };
}

const stubs = { Teleport: true };

beforeEach(() => {
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.auth.permissions = [];
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('communication locale', () => {
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

    it('labels every enum the server sends', () => {
        const groups: Record<string, string[]> = {
            channel: ['email', 'sms', 'whatsapp'],
            channelState: ['configured', 'not_configured'],
            purpose: ['transactional', 'marketing'],
            status: ['queued', 'sending', 'sent', 'delivered', 'failed', 'bounced', 'suppressed', 'cancelled'],
            interaction: ['note', 'call', 'meeting', 'email', 'sms', 'whatsapp', 'system'],
            direction: ['inbound', 'outbound'],
            severity: ['info', 'success', 'warning', 'critical'],
            category: ['system', 'assignments', 'reminders', 'approvals', 'billing', 'mentions'],
            locale: ['en', 'bn'],
            'audience|consentStatus': ['opted_in', 'opted_out'],
            'audience|methods': ['form', 'verbal', 'written', 'import', 'other'],
            'audience|scopeName': ['all', 'marketing'],
            'audience|reasonName': ['unsubscribed', 'bounced', 'complained', 'invalid', 'manual'],
        };
        const missing = Object.entries(groups).flatMap(([group, values]) =>
            values.filter((value) => !english.has(`${group}|${value}`) || !bengali.has(`${group}|${value}`)).map((value) => `${group}.${value}`),
        );

        expect(missing).toEqual([]);
    });

    it('translates every key used by the module', () => {
        const used = new Set<string>();
        const walk = (directory: string): void => {
            for (const name of readdirSync(directory)) {
                const path = join(directory, name);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (/\.(vue|ts)$/.test(name)) {
                    const stripped = readFileSync(path, 'utf8')
                        .replace(/route\(\s*['`][^'`]*['`]/g, '')
                        .replace(/\b(name|permission):\s*['`][^'`]*['`]/g, '')
                        .replace(/\bcan\(\s*['`][^'`]*['`]/g, '');

                    for (const match of stripped.matchAll(/['`]communication\.([A-Za-z0-9_.]+)['`]/g)) {
                        used.add(match[1]!.replaceAll('.', '|'));
                    }
                }
            }
        };
        walk(join(process.cwd(), 'resources/js/Modules/Communication'));

        const missing = [...used].filter((key) => ![...english.keys()].some((known) => known === key || known.startsWith(`${key}|`)));

        expect(missing).toEqual([]);
    });

    it('has the navigation labels, permissions and limit the manifest names', () => {
        const nav = (en.shell as unknown as { nav: Record<string, string> }).nav;
        const bnNav = (bn.shell as unknown as { nav: Record<string, string> }).nav;

        for (const key of ['communicationNotifications', 'communicationMessages', 'communicationTemplates', 'communicationAudience', 'communicationSettings', 'communicationInbox']) {
            expect(nav[key], key).toBeTruthy();
            expect(bnNav[key], key).toBeTruthy();
        }

        for (const source of [en.catalogue, bn.catalogue] as unknown as { modules: Record<string, string>; permissions: Record<string, unknown>; limits: Record<string, unknown> }[]) {
            expect(source.modules.communication).toBeTruthy();
            expect(Object.keys(source.permissions).filter((key) => key.startsWith('communication.'))).toHaveLength(13);
            expect(source.limits['communication.messages']).toBeTruthy();
        }
    });
});

describe('communication helpers', () => {
    it('maps statuses and severities to tones', () => {
        expect(statusTone('delivered')).toBe('ok');
        expect(statusTone('failed')).toBe('danger');
        expect(statusTone('suppressed')).toBe('warn');
        expect(statusTone('cancelled')).toBe('neutral');
        expect(severityTone('critical')).toBe('danger');
        expect(severityTone('info')).toBe('neutral');
    });

    it('picks the address a channel needs and makes a safe request id', () => {
        const contact = { email: 'a@example.com', phone: null };

        expect(addressFor('email', contact)).toBe('a@example.com');
        expect(addressFor('sms', contact)).toBeNull();
        expect(addressFor('sms', undefined)).toBeNull();
        expect(newRequestId()).toMatch(/^[A-Za-z0-9_-]{1,64}$/);
    });
});

describe('notification pages', () => {
    it('lists notifications and acts through the server routes', () => {
        const wrapper = track(
            mount(NotificationsIndex, {
                props: {
                    notifications: paginated([notification(), notification({ id: 'n2', read: true, title: 'Invoice paid' })]),
                    filters: { filter: 'all', category: null },
                    unreadCount: 1,
                    categories: ['approvals', 'billing'],
                },
            }),
        );

        expect(wrapper.text()).toContain('Leave request from Nadia');
        expect(wrapper.text()).toContain('Mark all as read');
        expect(wrapper.get('a[href="/hrm/leave/requests"]').text()).toContain('Leave request from Nadia');

        const read = wrapper.findAll('button').find((button) => button.attributes('aria-label') === 'Mark "Leave request from Nadia" as read');
        read!.trigger('click');
        expect(inertia.router.post).toHaveBeenCalledWith('/communication.notifications.read?notification=n1', {}, expect.anything());

        const readAll = wrapper.findAll('button').find((button) => button.text() === 'Mark all as read');
        readAll!.trigger('click');
        expect(inertia.router.post).toHaveBeenCalledWith('/communication.notifications.read-all', {}, expect.anything());
    });

    it('shows the empty state and hides mark-all when nothing is unread', () => {
        const wrapper = track(
            mount(NotificationsIndex, {
                props: { notifications: paginated([]), filters: { filter: 'unread', category: null }, unreadCount: 0, categories: [] },
            }),
        );

        expect(wrapper.text()).toContain('You are all caught up');
        expect(wrapper.text()).not.toContain('Mark all as read');
    });

    it('saves every category with its two choices and says when email cannot be sent', () => {
        const wrapper = track(
            mount(NotificationsPreferences, {
                props: {
                    preferences: [
                        { category: 'system', in_app: true, email: false },
                        { category: 'billing', in_app: true, email: true },
                    ],
                    emailAvailable: false,
                },
            }),
        );

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(4);
        expect(wrapper.text()).toContain('Email notifications are not available');

        wrapper.get('form').trigger('submit');
        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toMatchObject({ method: 'put', url: '/communication.preferences.update' });
        expect(inertia.submissions[0]!.data.preferences).toEqual([
            { category: 'system', in_app: true, email: false },
            { category: 'billing', in_app: true, email: true },
        ]);
    });

    it('renders the dashboard widget with the unread count and links', () => {
        const wrapper = track(mount(NotificationsWidget, { props: { data: { unread: 7, items: [notification()] } } }));

        expect(wrapper.text()).toContain('Leave request from Nadia');
        expect(wrapper.text()).toContain('and 6 more');
        expect(wrapper.find('a[href="/communication.notifications.index"]').exists()).toBe(true);

        const empty = track(mount(NotificationsWidget, { props: { data: { unread: 0, items: [] } } }));
        expect(empty.text()).toContain('You are all caught up');
    });
});

describe('message pages', () => {
    const channels = [
        { channel: 'email' as const, state: 'configured' as const },
        { channel: 'sms' as const, state: 'not_configured' as const },
        { channel: 'whatsapp' as const, state: 'not_configured' as const },
    ];

    it('lists messages with their status and offers the composer only to senders', () => {
        const props = {
            messages: paginated([message(), message({ id: 'm2', status: 'failed', failureCode: 'no_consent', recipient: '+8801700000000', channel: 'sms' })]),
            filters: { search: '', status: null, channel: null, source: null, contact: null },
            statuses: ['queued', 'failed'],
            channels,
            canSend: true,
        };
        const wrapper = track(mount(MessagesIndex, { props }));

        expect(wrapper.text()).toContain('rahim@example.com');
        expect(wrapper.text()).toContain('Queued');
        expect(wrapper.text()).toContain('The contact has not given marketing consent.');
        expect(wrapper.text()).toContain('Not configured');
        expect(wrapper.find('a[href="/communication.messages.create"]').exists()).toBe(true);

        const denied = track(mount(MessagesIndex, { props: { ...props, canSend: false } }));
        expect(denied.find('a[href="/communication.messages.create"]').exists()).toBe(false);
    });

    it('filters through the server with the chosen values', async () => {
        const wrapper = track(
            mount(MessagesIndex, {
                props: {
                    messages: paginated([]),
                    filters: { search: '', status: null, channel: null, source: null, contact: null },
                    statuses: ['queued', 'failed'],
                    channels,
                    canSend: false,
                },
            }),
        );

        await wrapper.get('input[type="text"]').setValue('rahim');
        await wrapper.get('form[role="search"]').trigger('submit');

        expect(inertia.router.get).toHaveBeenCalledWith('/communication.messages.index', { search: 'rahim' }, expect.anything());
    });

    it('shows a message as text, with retry only when the server allows it', () => {
        const failed = track(
            mount(MessagesShow, {
                props: { message: detail({ status: 'failed', failureCode: 'provider_not_configured', retryable: true, failureMessage: 'Refused' }) },
            }),
        );

        expect(failed.text()).toContain('Hello <b>Rahim</b>');
        expect(failed.find('b').exists()).toBe(false);
        expect(failed.text()).toContain('No provider is configured for this channel.');

        const retry = failed.findAll('button').find((button) => button.text() === 'Send again');
        retry!.trigger('click');
        expect(inertia.router.post).toHaveBeenCalledWith('/communication.messages.retry?message=m1', {}, expect.anything());

        const sent = track(mount(MessagesShow, { props: { message: detail({ status: 'sent' }) } }));
        expect(sent.findAll('button').some((button) => button.text() === 'Send again' || button.text() === 'Cancel message')).toBe(false);
    });

    it('offers cancel only for a cancellable message', () => {
        const wrapper = track(mount(MessagesShow, { props: { message: detail({ cancellable: true }) } }));

        expect(wrapper.findAll('button').some((button) => button.text() === 'Cancel message')).toBe(true);
    });

    it('does not offer a channel that is not configured', () => {
        const wrapper = track(
            mount(MessagesCompose, {
                props: {
                    contacts: [{ id: 'c1', name: 'Rahim Uddin', email: 'rahim@example.com', phone: null }],
                    channels,
                    purposes: ['transactional', 'marketing'],
                    canChoosePurpose: true,
                    templates: [],
                    contactId: 'c1',
                },
            }),
        );

        const options = wrapper.findAll('select')[1]!.findAll('option');
        const sms = options.find((option) => option.text().startsWith('SMS'));

        expect(sms!.text()).toBe('SMS (not configured)');
        expect(sms!.attributes('disabled')).toBeDefined();
        expect(options.find((option) => option.text() === 'Email')!.attributes('disabled')).toBeUndefined();
    });

    it('sends one message with an idempotency key and no template fields when a template is used', async () => {
        const wrapper = track(
            mount(MessagesCompose, {
                props: {
                    contacts: [{ id: 'c1', name: 'Rahim Uddin', email: 'rahim@example.com', phone: null }],
                    channels,
                    purposes: ['transactional', 'marketing'],
                    canChoosePurpose: true,
                    templates: [{ id: 't1', name: 'Invoice sent', channel: 'email', locale: 'en', purpose: 'transactional', subject: 'Invoice' }],
                    contactId: 'c1',
                },
            }),
        );

        await wrapper.get('input[type="text"]').setValue('Hello');
        await wrapper.get('textarea').setValue('Your invoice is ready.');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions[0]).toMatchObject({ method: 'post', url: '/communication.messages.store' });
        expect(inertia.submissions[0]!.data).toMatchObject({ contact_id: 'c1', channel: 'email', purpose: 'transactional', template_id: null, subject: 'Hello', body: 'Your invoice is ready.' });
        expect(String(inertia.submissions[0]!.data.request_id)).toMatch(/^[A-Za-z0-9_-]+$/);
    });

    it('hides the purpose control and sends no purpose without the transactional permission', async () => {
        const wrapper = track(
            mount(MessagesCompose, {
                props: {
                    contacts: [{ id: 'c1', name: 'Rahim Uddin', email: 'rahim@example.com', phone: null }],
                    channels,
                    purposes: ['marketing'],
                    canChoosePurpose: false,
                    templates: [],
                    contactId: 'c1',
                },
            }),
        );

        expect(wrapper.find('[data-testid="purpose-fixed"]').exists()).toBe(true);
        expect(wrapper.findAll('select')).toHaveLength(2);

        await wrapper.get('input[type="text"]').setValue('Hello');
        await wrapper.get('textarea').setValue('Hi there');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions[0]!.data).toMatchObject({ purpose: null, body: 'Hi there' });
    });
});

describe('templates, audience and timeline', () => {
    it('lists templates and offers editing only to managers', () => {
        const props = {
            templates: [{ id: 't1', slug: 'invoice-sent', name: 'Invoice sent', channel: 'email' as const, locale: 'bn', purpose: 'transactional' as const, subject: 'Invoice', body: 'Hi', isActive: true }],
            variables: [{ key: 'contact.first_name', label: 'Contact first name' }],
            channels: ['email', 'sms'],
            locales: ['en', 'bn'],
            purposes: ['transactional', 'marketing'],
            canManage: true,
        };
        const manager = track(mount(TemplatesIndex, { props, global: { stubs } }));

        expect(manager.text()).toContain('Invoice sent');
        expect(manager.text()).toContain('Bengali');
        expect(manager.text()).toContain('New template');

        const viewer = track(mount(TemplatesIndex, { props: { ...props, canManage: false }, global: { stubs } }));
        expect(viewer.text()).not.toContain('New template');
        expect(viewer.findAll('button').some((button) => (button.attributes('aria-label') ?? '').startsWith('Edit'))).toBe(false);
    });

    it('shows consent and suppressions and keeps complaints unremovable', () => {
        const wrapper = track(
            mount(AudienceIndex, {
                props: {
                    consents: paginated([
                        { id: 'k1', contact: { id: 'c1', name: 'Rahim Uddin' }, channel: 'email', purpose: 'marketing', status: 'opted_in' as const, method: 'form', evidence: 'Sign-up form', recordedAt: '2026-10-11T09:00:00+00:00' },
                    ]),
                    suppressions: paginated([
                        { id: 's1', channel: 'email', address: 'bounce@example.com', scope: 'all', reason: 'bounced', note: null, contactId: null, removable: true, createdAt: '2026-10-11T09:00:00+00:00' },
                        { id: 's2', channel: 'email', address: 'angry@example.com', scope: 'marketing', reason: 'complained', note: null, contactId: null, removable: false, createdAt: '2026-10-11T09:00:00+00:00' },
                    ]),
                    filters: { search: '', status: null, channel: null },
                    contacts: [{ id: 'c1', name: 'Rahim Uddin', email: null, phone: null }],
                    methods: ['form', 'verbal'],
                    channels: ['email', 'sms'],
                    scopes: ['all', 'marketing'],
                    canManageConsent: true,
                    canManageSuppressions: true,
                },
                global: { stubs },
            }),
        );

        expect(wrapper.text()).toContain('Opted in');
        expect(wrapper.text()).toContain('bounce@example.com');
        expect(wrapper.text()).toContain('Cannot be removed by hand');
        expect(wrapper.findAll('button').filter((button) => (button.attributes('aria-label') ?? '').startsWith('Remove '))).toHaveLength(1);
        expect(wrapper.text()).toContain('Record consent');
    });

    it('logs a timeline entry on the contact and hides logging from viewers', async () => {
        const props = {
            subject: { type: 'contact' as const, id: 'c1', name: 'Rahim Uddin', email: null, phone: null },
            entries: paginated([
                {
                    id: 'i1', type: 'call' as const, direction: 'outbound' as const, summary: 'Called about the order', body: null, occurredAt: '2026-10-11T09:00:00+00:00',
                    sourceModule: null, sourceType: null, sourceId: null, contactId: 'c1', messageId: null, messageStatus: null, owner: { id: 'o1', name: 'Karim' }, deletable: true,
                },
            ]),
            filters: { type: null },
            types: ['note', 'call', 'meeting', 'email'],
            canLog: true,
            canCompose: true,
        };
        const wrapper = track(mount(TimelineShow, { props, global: { stubs } }));

        expect(wrapper.text()).toContain('Called about the order');
        expect(wrapper.find('a[href="/communication.messages.create?contact=c1"]').exists()).toBe(true);

        await wrapper.get('input[type="text"]').setValue('Spoke to him');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions[0]).toMatchObject({ method: 'post', url: '/communication.timeline.contact.store?contact=c1' });
        expect(inertia.submissions[0]!.data).toMatchObject({ type: 'note', summary: 'Spoke to him', direction: null, body: null, occurred_at: null });

        const viewer = track(mount(TimelineShow, { props: { ...props, canLog: false, canCompose: false }, global: { stubs } }));
        expect(viewer.find('form').exists()).toBe(false);
    });
});

describe('settings', () => {
    it('says honestly that SMS and WhatsApp have no provider and offers no form for them', () => {
        const wrapper = track(
            mount(SettingsIndex, {
                props: {
                    settings: { fromName: null, replyToEmail: null, quietHoursEnabled: false, quietHoursStart: '21:00:00', quietHoursEnd: '08:00:00', footerText: null },
                    channels: [channelCard('email'), channelCard('sms'), channelCard('whatsapp')],
                    credentialRefs: [],
                    senderGuidance: { platformSender: 'no-reply@oyosend.test', customDomainVerified: false },
                },
            }),
        );

        const sms = wrapper.get('[data-testid="channel-sms"]');
        expect(sms.text()).toContain('Not configured');
        expect(sms.text()).toContain('No provider is available for this channel yet.');
        expect(sms.find('form').exists()).toBe(false);

        const email = wrapper.get('[data-testid="channel-email"]');
        expect(email.text()).toContain('Ready to send');
        expect(email.find('form').exists()).toBe(true);
        expect(wrapper.text()).toContain('no-reply@oyosend.test');
    });

    it('saves the sender identity and quiet hours', async () => {
        const wrapper = track(
            mount(SettingsIndex, {
                props: {
                    settings: { fromName: 'Acme', replyToEmail: null, quietHoursEnabled: true, quietHoursStart: '21:00:00', quietHoursEnd: '08:00:00', footerText: null },
                    channels: [],
                    credentialRefs: [],
                    senderGuidance: { platformSender: 'no-reply@oyosend.test', customDomainVerified: false },
                },
            }),
        );

        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions[0]).toMatchObject({ method: 'put', url: '/communication.settings.update' });
        expect(inertia.submissions[0]!.data).toMatchObject({
            from_name: 'Acme',
            reply_to_email: null,
            quiet_hours_enabled: true,
            quiet_hours_start: '21:00',
            quiet_hours_end: '08:00',
            footer_text: null,
        });
    });

    it('renders in Bengali', () => {
        setLocale('bn');
        const wrapper = track(mount(NotificationsWidget, { props: { data: { unread: 0, items: [] } } }));

        expect(wrapper.text()).toContain('সবকিছু পড়া হয়ে গেছে');
    });
});
