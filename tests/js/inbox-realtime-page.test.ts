import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';

const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: ['communication.inbox.view'] }, tenant: { name: 'Acme' } } },
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
}));

/** What the page handed to the realtime composable, and a switch for "subscribed". */
const live = vi.hoisted(() => ({ options: null as null | { config: () => unknown; onChange: (signals: Array<{ source: string; id: string }>) => void; onResync?: () => void }, connected: null as null | { value: boolean } }));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
    isCurrentRoute: () => false,
}));

vi.mock('@/Modules/Communication/Components/useInboxRealtime', async (original) => ({
    ...(await original<typeof import('@/Modules/Communication/Components/useInboxRealtime')>()),
    useInboxRealtime: (options: NonNullable<typeof live.options>) => {
        live.options = options;
        const connected = ref(false);
        live.connected = connected;

        return { connected };
    },
}));

import { setLocale } from '@/Core/i18n';
import { LIST_POLL_INTERVAL, POLL_INTERVAL, REALTIME_POLL_INTERVAL, type InboxThreadView } from '@/Modules/Communication/Components/inboxChat';
import InboxIndex, { type InboxRow } from '@/Modules/Communication/Pages/Inbox/Index.vue';

const ID = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const OTHER = '01JABCDEFGHJKMNPQRSTVWXYZ1';
const REALTIME = { key: 'app-key', host: 'localhost', port: 8080, scheme: 'http' as const, channel: 'tenant.01T.inbox', authEndpoint: '/broadcasting/auth' };

const mounted: VueWrapper[] = [];
const row = (overrides: Partial<InboxRow> = {}): InboxRow => ({
    key: `whatsapp_marketing.conversations:${ID}`, source: 'whatsapp_marketing.conversations', sourceLabel: 'WhatsApp', channel: 'whatsapp',
    title: 'Rahim Uddin', preview: 'Is it in stock?', at: '2026-10-13T08:00:00Z', state: 'unassigned', owner: null, unread: 0,
    url: `/whatsapp/inbox/${ID}`, contactId: 'contact-1', chat: true, ...overrides,
});
const thread = (): InboxThreadView => ({
    key: `whatsapp_marketing.conversations:${ID}`, source: 'whatsapp_marketing.conversations', id: ID, sourceLabel: 'WhatsApp', channel: 'whatsapp', marksRead: false,
    title: 'Rahim Uddin', subtitle: null, contactId: 'contact-1', url: `/whatsapp/inbox/${ID}`,
    replyAllowed: true, canReply: true, replyBlockedReason: null, hasOlder: false, limit: 50,
    messages: [{ id: 'm1', direction: 'in', text: 'Hello', at: '2026-10-13T08:00:00Z', author: null, status: null, attachment: null }],
});
const props = (extra: Record<string, unknown> = {}) => ({
    sources: [{ key: 'whatsapp_marketing.conversations', label: 'WhatsApp', channel: 'whatsapp' }],
    filters: { view: 'list' as const, source: null, owner: null, state: 'open' as const, search: '', open: `whatsapp_marketing.conversations:${ID}` },
    counts: { unassigned: 1, active: 0, closed: 0 },
    columns: null,
    list: { items: [row()], next: null, after: null },
    thread: thread(),
    ...extra,
});

const fetchMock = vi.fn(async () => new Response(JSON.stringify({ items: [], next: null }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
const listCalls = (): number => fetchMock.mock.calls.filter((call) => String((call as unknown[])[0]).startsWith('/communication.inbox.poll')).length;

beforeEach(() => {
    setLocale('en');
    vi.useFakeTimers();
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    inertia.router.reload.mockClear();
    fetchMock.mockClear();
    vi.stubGlobal('fetch', fetchMock);
    live.options = null;
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Unified inbox with realtime', () => {
    it('hands the server\'s realtime details to the composable, and nothing without them', () => {
        mounted.push(mount(InboxIndex, { props: props({ realtime: REALTIME }) }));
        expect(live.options!.config()).toEqual(REALTIME);

        mounted.push(mount(InboxIndex, { props: props() }));
        expect(live.options!.config() ?? null).toBeNull();
    });

    it('refreshes the list on any signal and the open thread only when it is the one that changed', async () => {
        mounted.push(mount(InboxIndex, { props: props({ realtime: REALTIME }) }));

        live.options!.onChange([{ source: 'support.tickets', id: OTHER }]);
        await nextTick();
        expect(listCalls()).toBe(1);
        expect(inertia.router.reload).not.toHaveBeenCalled();

        await vi.runOnlyPendingTimersAsync();
        live.options!.onChange([{ source: 'whatsapp_marketing.conversations', id: ID }]);
        await nextTick();
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
        expect(inertia.router.reload.mock.calls[0]![0]).toMatchObject({ only: ['thread'] });
    });

    it('polls slowly while subscribed and at the normal pace again when the connection drops', async () => {
        mounted.push(mount(InboxIndex, { props: props({ realtime: REALTIME }) }));

        live.connected!.value = true;
        await nextTick();
        vi.advanceTimersByTime(Math.max(POLL_INTERVAL, LIST_POLL_INTERVAL));
        expect(inertia.router.reload).not.toHaveBeenCalled();
        expect(listCalls()).toBe(0);

        vi.advanceTimersByTime(REALTIME_POLL_INTERVAL - Math.max(POLL_INTERVAL, LIST_POLL_INTERVAL));
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
        (inertia.router.reload.mock.calls[0]![0] as { onFinish: () => void }).onFinish();

        // Fallback: the socket is gone, the normal pace returns.
        live.connected!.value = false;
        await nextTick();
        vi.advanceTimersByTime(POLL_INTERVAL);
        expect(inertia.router.reload).toHaveBeenCalledTimes(2);
    });

    it('asks for everything once after a reconnection', async () => {
        mounted.push(mount(InboxIndex, { props: props({ realtime: REALTIME }) }));

        live.options!.onResync!();
        await nextTick();

        expect(listCalls()).toBe(1);
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
    });
});
