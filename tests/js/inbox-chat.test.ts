import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: ['communication.inbox.view'] }, tenant: { name: 'Acme' } } },
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
}));

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

import { setLocale } from '@/Core/i18n';
import { channelIcon, channelLabelKey, createPoller, createReadTracker, FALLBACK_CHANNEL_ICON, hasNewInbound, type InboxThreadView, isSendShortcut, MESSAGES_MAX, nextMessageLimit, parseThreadKey, POLL_INTERVAL, threadKey } from '@/Modules/Communication/Components/inboxChat';
import InboxIndex, { type InboxRow } from '@/Modules/Communication/Pages/Inbox/Index.vue';

const ID = '01JABCDEFGHJKMNPQRSTVWXYZ0';

describe('inbox chat helpers', () => {
    it('names a conversation as source and id, and accepts only what the server accepts', () => {
        expect(threadKey('whatsapp_marketing.conversations', ID)).toBe(`whatsapp_marketing.conversations:${ID}`);
        expect(parseThreadKey(`social.comments:${ID}`)).toEqual({ source: 'social.comments', id: ID });
        expect(parseThreadKey('social.comments')).toBeNull();
        expect(parseThreadKey(`Social.Comments:${ID}`)).toBeNull();
        expect(parseThreadKey(`social.comments:${ID}x`)).toBeNull();
        expect(parseThreadKey(`social.comments:${ID}:extra`)).toBeNull();
        expect(parseThreadKey(null)).toBeNull();
    });

    it('knows the channels of today and the next sources, and falls back for any other', () => {
        for (const channel of ['whatsapp', 'facebook', 'messenger', 'instagram', 'web']) {
            expect(channelIcon(channel)).not.toBe(FALLBACK_CHANNEL_ICON);
            expect(channelLabelKey(channel)).toBe(`communication.inbox.channels.${channel}`);
        }

        expect(channelIcon('telegram')).toBe(FALLBACK_CHANNEL_ICON);
        expect(channelLabelKey('telegram')).toBeNull();
        expect(channelLabelKey('toString')).toBeNull();
    });

    it('sees new inbound messages only past the last one already shown', () => {
        const at = (minute: number) => `2026-10-13T08:${String(minute).padStart(2, '0')}:00+00:00`;
        const message = (id: string, direction: 'in' | 'out', minute: number) => ({ id, direction, text: id, at: at(minute), author: null, status: null, attachment: null });
        const shown = [message('a', 'in', 10), message('b', 'out', 11)];

        expect(hasNewInbound(null, shown)).toBe(false);
        expect(hasNewInbound(shown, shown)).toBe(false);
        expect(hasNewInbound(shown, [...shown, message('c', 'out', 12)])).toBe(false);
        expect(hasNewInbound(shown, [...shown, message('d', 'in', 12)])).toBe(true);
        // "Load older" adds earlier messages: not new.
        expect(hasNewInbound(shown, [message('z', 'in', 1), ...shown])).toBe(false);
    });

    it('decides when to mark read: something unread, a source with an unread state, a visible tab', () => {
        const base = { key: 'whatsapp_marketing.conversations:a', marksRead: true, messages: [] as InboxThreadView['messages'] };
        const newer = [{ id: 'n', direction: 'in' as const, text: 'Hi', at: '2026-10-13T09:00:00Z', author: null, status: null, attachment: null }];
        const tracker = createReadTracker();

        expect(tracker.observe(base, 2, false)).toBe(true);
        expect(tracker.observe(base, 0, false)).toBe(false);
        expect(tracker.observe({ ...base, messages: newer }, 0, true)).toBe(false);
        // The read waits for a visible look at the same conversation.
        expect(tracker.observe({ ...base, messages: newer }, 0, false)).toBe(true);
        // Another conversation not in the loaded list: its badge is unknown, so it is read once.
        expect(tracker.observe({ ...base, key: 'whatsapp_marketing.conversations:b' }, null, false)).toBe(true);
        expect(tracker.observe({ ...base, key: 'social.comments:c', marksRead: false }, 5, false)).toBe(false);
        expect(tracker.observe(null, null, false)).toBe(false);
        expect(tracker.observe({ ...base, key: 'whatsapp_marketing.conversations:d' }, 0, false)).toBe(false);
    });

    it('loads older messages in steps up to the cap', () => {
        expect(nextMessageLimit(50)).toBe(100);
        expect(nextMessageLimit(180)).toBe(MESSAGES_MAX);
        expect(nextMessageLimit(MESSAGES_MAX)).toBeNull();
    });

    it('sends on Ctrl+Enter or Cmd+Enter only, never while an IME is composing', () => {
        const key = (overrides: Partial<KeyboardEvent>) => ({ key: 'Enter', ctrlKey: false, metaKey: false, isComposing: false, ...overrides });

        expect(isSendShortcut(key({ ctrlKey: true }))).toBe(true);
        expect(isSendShortcut(key({ metaKey: true }))).toBe(true);
        expect(isSendShortcut(key({}))).toBe(false);
        expect(isSendShortcut(key({ ctrlKey: true, isComposing: true }))).toBe(false);
        expect(isSendShortcut(key({ key: 'a', ctrlKey: true }))).toBe(false);
    });

    it('polls on an interval, never while hidden and never twice at once', () => {
        vi.useFakeTimers();
        let hidden = false;
        const finishers: Array<() => void> = [];
        const tick = vi.fn((done: () => void) => { finishers.push(done); });
        const poller = createPoller({ interval: 1000, tick, isHidden: () => hidden });

        poller.start();
        expect(poller.running).toBe(true);
        vi.advanceTimersByTime(1000);
        expect(tick).toHaveBeenCalledTimes(1);

        // The first request is still running: the next tick is skipped.
        vi.advanceTimersByTime(1000);
        expect(tick).toHaveBeenCalledTimes(1);

        finishers.shift()!();
        vi.advanceTimersByTime(1000);
        expect(tick).toHaveBeenCalledTimes(2);
        finishers.shift()!();

        hidden = true;
        vi.advanceTimersByTime(5000);
        expect(tick).toHaveBeenCalledTimes(2);

        hidden = false;
        poller.fire();
        expect(tick).toHaveBeenCalledTimes(3);
        finishers.shift()!();

        poller.stop();
        vi.advanceTimersByTime(5000);
        expect(tick).toHaveBeenCalledTimes(3);
        expect(poller.running).toBe(false);
        vi.useRealTimers();
    });
});

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const row = (overrides: Partial<InboxRow> = {}): InboxRow => ({
    key: `whatsapp_marketing.conversations:${ID}`, source: 'whatsapp_marketing.conversations', sourceLabel: 'WhatsApp', channel: 'whatsapp',
    title: 'Rahim Uddin', preview: 'Is it in stock?', at: '2026-10-13T08:00:00Z', state: 'unassigned', owner: null, unread: 1,
    url: `/whatsapp/inbox/${ID}`, contactId: 'contact-1', chat: true, ...overrides,
});
const thread = (overrides: Partial<InboxThreadView> = {}): InboxThreadView => ({
    key: `whatsapp_marketing.conversations:${ID}`, source: 'whatsapp_marketing.conversations', id: ID, sourceLabel: 'WhatsApp', channel: 'whatsapp', marksRead: false,
    title: 'Rahim Uddin', subtitle: '+8801711000001', contactId: 'contact-1', url: `/whatsapp/inbox/${ID}`,
    replyAllowed: true, canReply: true, replyBlockedReason: null, hasOlder: false, limit: 50,
    messages: [
        { id: 'm1', direction: 'in', text: '<b>Is it in stock?</b>', at: '2026-10-13T08:00:00Z', author: null, status: null, attachment: null },
        { id: 'm2', direction: 'out', text: 'Yes.', at: '2026-10-13T08:05:00Z', author: 'Owner', status: 'delivered', attachment: null },
        { id: 'm3', direction: 'in', text: null, at: '2026-10-13T08:06:00Z', author: null, status: null, attachment: 'Image' },
    ],
    ...overrides,
});
const sources = [{ key: 'whatsapp_marketing.conversations', label: 'WhatsApp', channel: 'whatsapp' }, { key: 'support.tickets', label: 'Support tickets', channel: 'support' }];
const filters = { view: 'list' as const, source: null, owner: null, state: 'open' as const, search: '', open: null };
const counts = { unassigned: 1, active: 0, closed: 0 };
const list = (items: InboxRow[]) => ({ items, next: null, after: null });

const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

beforeEach(() => {
    setLocale('en');
    inertia.router.get.mockClear();
    inertia.router.post.mockClear();
    inertia.router.reload.mockClear();
    fetchMock.mockClear();
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Unified inbox chat workspace', () => {
    it('opens a chat conversation beside the list and keeps other sources linking to their app', async () => {
        const wrapper = track(mount(InboxIndex, { props: {
            sources, filters, counts, columns: null,
            list: list([row(), row({ key: 'support.tickets:t1', source: 'support.tickets', sourceLabel: 'Support tickets', url: '/support/tickets/t1', chat: false })]),
        } }));

        expect(wrapper.find('a[href="/support/tickets/t1"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Open a conversation');

        await wrapper.get('button[data-chat="true"]').trigger('click');
        const [url, params, options] = inertia.router.get.mock.calls[0]!;
        expect(url).toBe('/communication.inbox.index');
        expect(params).toMatchObject({ open: `whatsapp_marketing.conversations:${ID}` });
        expect(options).toMatchObject({ only: ['thread', 'filters'], preserveState: true });
    });

    it('shows the messages as text, with their author, status and attachment', () => {
        const wrapper = track(mount(InboxIndex, { props: { sources, filters: { ...filters, open: `whatsapp_marketing.conversations:${ID}` }, counts, columns: null, list: list([row()]), thread: thread() } }));
        const pane = wrapper.get('[data-testid="inbox-thread"]');

        expect(pane.find('h2').text()).toBe('Rahim Uddin');
        expect(pane.text()).toContain('<b>Is it in stock?</b>');
        expect(pane.find('b').exists()).toBe(false);
        expect(pane.findAll('li[data-direction]').map((item) => item.attributes('data-direction'))).toEqual(['in', 'out', 'in']);
        expect(pane.text()).toContain('Delivered');
        expect(pane.text()).toContain('Attachment: Image');
        expect(pane.get(`a[href="/whatsapp/inbox/${ID}"]`).text()).toContain('Open in app');
        expect(wrapper.get('button[data-chat="true"]').attributes('aria-current')).toBe('true');
    });

    it('sends with Ctrl+Enter through the inbox reply route', async () => {
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row()]), thread: thread() } }));
        const textarea = wrapper.get('textarea');

        await textarea.setValue('On its way');
        await textarea.trigger('keydown', { key: 'Enter' });
        expect(inertia.router.post).not.toHaveBeenCalled();

        await textarea.trigger('keydown', { key: 'Enter', ctrlKey: true });
        const [url, data] = inertia.router.post.mock.calls[0]!;
        expect(url).toBe(`/communication.inbox.reply?source=whatsapp_marketing.conversations&id=${ID}`);
        expect(data).toEqual({ text: 'On its way' });
    });

    it('explains why a reply cannot be written now and hides the composer without the permission', async () => {
        const blocked = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row()]), thread: thread({ canReply: false, replyBlockedReason: 'The 24-hour window has closed.' }) } }));

        expect(blocked.get('[data-testid="reply-blocked"]').text()).toBe('The 24-hour window has closed.');
        // The reason replaces the composer (it is no longer a disabled one), with the way to the app that can act.
        expect(blocked.find('textarea').exists()).toBe(false);
        expect(blocked.find('[data-testid="inbox-thread"] button[type="submit"]').exists()).toBe(false);
        expect(blocked.get('[data-testid="reply-blocked"]').element.parentElement!.querySelector(`a[href="/whatsapp/inbox/${ID}"]`)?.textContent).toContain('Open in app');

        const readOnly = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row()]), thread: thread({ replyAllowed: false, canReply: false }) } }));
        expect(readOnly.find('textarea').exists()).toBe(false);
        expect(readOnly.find('form[aria-label="Reply"]').exists()).toBe(false);
    });

    it('asks for older messages in steps', async () => {
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row()]), thread: thread({ hasOlder: true }) } }));

        await wrapper.findAll('button').find((button) => button.text() === 'Load older messages')!.trigger('click');
        const [, params, options] = inertia.router.get.mock.calls[0]!;
        expect(params).toMatchObject({ open: `whatsapp_marketing.conversations:${ID}`, messages: '100' });
        expect(options).toMatchObject({ only: ['thread'] });
    });

    it('polls the open conversation, but not while the page is hidden', async () => {
        vi.useFakeTimers();
        const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
        track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row()]), thread: thread() } }));

        vi.advanceTimersByTime(POLL_INTERVAL);
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
        expect(inertia.router.reload.mock.calls[0]![0]).toMatchObject({ only: ['thread'], async: true, showProgress: false });
        (inertia.router.reload.mock.calls[0]![0] as { onFinish: () => void }).onFinish();

        hidden.mockReturnValue(true);
        vi.advanceTimersByTime(POLL_INTERVAL * 3);
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
        hidden.mockRestore();
        await nextTick();
    });

    it('says a conversation is no longer available instead of polling it, and goes back to the list', async () => {
        vi.useFakeTimers();
        const wrapper = track(mount(InboxIndex, { props: { sources, filters: { ...filters, open: `whatsapp_marketing.conversations:${ID}` }, counts, columns: null, list: list([row()]), thread: null } }));
        const notice = wrapper.get('[data-testid="inbox-thread-unavailable"]');

        expect(notice.text()).toContain('This conversation is no longer available');
        expect(notice.find('[role="status"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="inbox-thread"]').exists()).toBe(false);

        vi.advanceTimersByTime(POLL_INTERVAL * 2);
        expect(inertia.router.reload).not.toHaveBeenCalled();

        await notice.findAll('button').find((button) => button.text() === 'Back to the list')!.trigger('click');
        const [, params, options] = inertia.router.get.mock.calls[0]!;
        expect(params).toMatchObject({ open: undefined });
        expect(options).toMatchObject({ only: ['thread', 'filters'] });
    });

    it('stops polling when a poll finds the conversation gone', async () => {
        vi.useFakeTimers();
        const wrapper = track(mount(InboxIndex, { props: { sources, filters: { ...filters, open: `whatsapp_marketing.conversations:${ID}` }, counts, columns: null, list: list([row()]), thread: thread() } }));

        vi.advanceTimersByTime(POLL_INTERVAL);
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
        (inertia.router.reload.mock.calls[0]![0] as { onFinish: () => void }).onFinish();

        await wrapper.setProps({ thread: null });
        expect(wrapper.text()).toContain('This conversation is no longer available');
        vi.advanceTimersByTime(POLL_INTERVAL * 3);
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
    });

    it('marks an opened unread conversation read with a POST and clears its badge at once', async () => {
        document.cookie = 'XSRF-TOKEN=token%3D1';
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row({ unread: 3 })]) } }));
        expect(wrapper.get('button[data-chat="true"]').text()).toContain('3 unread');
        expect(fetchMock).not.toHaveBeenCalled();

        await wrapper.setProps({ thread: thread({ marksRead: true }) });
        expect(wrapper.get('button[data-chat="true"]').text()).not.toContain('unread');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(`/communication.inbox.read?source=whatsapp_marketing.conversations&id=${ID}`);
        expect(init).toMatchObject({ method: 'POST', credentials: 'same-origin' });
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('token=1');

        // A poll with nothing new does not post again.
        await wrapper.setProps({ thread: thread({ marksRead: true }) });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('posts again when a poll brings new inbound messages, but not for its own replies', async () => {
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row({ unread: 0 })]), thread: thread({ marksRead: true }) } }));
        expect(fetchMock).not.toHaveBeenCalled();

        const base = thread().messages;
        await wrapper.setProps({ thread: thread({ marksRead: true, messages: [...base, { id: 'm4', direction: 'out', text: 'Sent from here', at: '2026-10-13T08:07:00Z', author: 'Owner', status: 'sent', attachment: null }] }) });
        expect(fetchMock).not.toHaveBeenCalled();

        await wrapper.setProps({ thread: thread({ marksRead: true, messages: [...base, { id: 'm5', direction: 'in', text: 'Thanks!', at: '2026-10-13T08:08:00Z', author: null, status: null, attachment: null }] }) });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('never posts while the tab is hidden, and reads once it is visible again', async () => {
        const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(true);
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row({ unread: 2 })]), thread: thread({ marksRead: true }) } }));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.get('button[data-chat="true"]').text()).toContain('2 unread');

        hidden.mockReturnValue(false);
        await wrapper.setProps({ thread: thread({ marksRead: true }) });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(wrapper.get('button[data-chat="true"]').text()).not.toContain('unread');
    });

    it('never posts for a source without an unread state', async () => {
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row({ unread: 2 })]), thread: thread({ marksRead: false }) } }));
        await wrapper.setProps({ thread: thread({ marksRead: false, messages: [...thread().messages, { id: 'm6', direction: 'in', text: 'Hi', at: '2026-10-13T09:00:00Z', author: null, status: null, attachment: null }] }) });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.get('button[data-chat="true"]').text()).toContain('2 unread');
    });

    it('names each channel by an icon, and an unknown one by the source label', () => {
        const rows = [
            row({ key: `social.messenger:${ID}`, channel: 'messenger', sourceLabel: 'Messenger' }),
            row({ key: `social.instagram:${ID}`, channel: 'instagram', sourceLabel: 'Instagram' }),
            row({ key: `support.web_chat:${ID}`, channel: 'web', sourceLabel: 'Live chat' }),
            row({ key: `other.telegram:${ID}`, channel: 'telegram', sourceLabel: 'Telegram' }),
        ];
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list(rows) } }));
        const names = Object.fromEntries(wrapper.findAll('[data-channel]').map((icon) => [icon.attributes('data-channel'), icon.attributes('aria-label')]));

        expect(names).toEqual({ messenger: 'Messenger', instagram: 'Instagram', web: 'Website chat', telegram: 'Telegram' });
        expect(wrapper.findAll('[data-channel] svg')).toHaveLength(4);
    });

    it('does not poll when no conversation is open', () => {
        vi.useFakeTimers();
        track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: list([row()]) } }));

        vi.advanceTimersByTime(POLL_INTERVAL * 2);
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
});
