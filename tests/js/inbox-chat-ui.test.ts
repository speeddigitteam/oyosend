import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
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
import { formatDate } from '@/Core/lib/format';
import InboxThreadPane from '@/Modules/Communication/Components/InboxThreadPane.vue';
import {
    appendPage,
    attachmentIcon,
    AVATAR_TINTS,
    avatarTint,
    calendarDay,
    chatItems,
    dayBucket,
    type DayLabels,
    deliveryState,
    ENTER_SENDS_QUERY,
    type InboxThreadMessage,
    type InboxThreadView,
    initialsOf,
    isNearBottom,
    isNearTop,
    linkify,
    LIST_POLL_INTERVAL,
    listTimeLabel,
    mergeFirstPage,
    planScroll,
    separatorLabel,
    shouldSend,
} from '@/Modules/Communication/Components/inboxChat';
import InboxIndex, { type InboxRow } from '@/Modules/Communication/Pages/Inbox/Index.vue';

const ID = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const DHAKA = 'Asia/Dhaka';
// Wednesday 14 October 2026, 11:00 in Dhaka.
const NOW = new Date('2026-10-14T05:00:00Z');

const message = (overrides: Partial<InboxThreadMessage> = {}): InboxThreadMessage => ({
    id: 'm', direction: 'in', text: 'Hello', at: '2026-10-14T04:00:00Z', author: null, status: null, attachment: null, attachmentKind: null, ...overrides,
});

describe('days in the workspace timezone', () => {
    it('puts a moment on the calendar day of the workspace, across midnight', () => {
        // 00:30 on the 14th in Dhaka is still the 13th in UTC.
        expect(calendarDay('2026-10-13T18:30:00Z', DHAKA)).toBe('2026-10-14');
        expect(calendarDay('2026-10-13T18:30:00Z', 'UTC')).toBe('2026-10-13');
        expect(calendarDay('2026-10-13T17:59:00Z', DHAKA)).toBe('2026-10-13');
        expect(calendarDay('not a date', DHAKA)).toBeNull();
        // An unknown zone falls back to UTC instead of throwing.
        expect(calendarDay('2026-10-13T18:30:00Z', 'Mars/Olympus')).toBe('2026-10-13');
    });

    it('sorts moments into today, yesterday, this week, this year and older', () => {
        expect(dayBucket('2026-10-13T18:30:00Z', NOW, DHAKA)).toBe('today');
        expect(dayBucket('2026-10-13T18:30:00Z', NOW, 'UTC')).toBe('yesterday');
        expect(dayBucket('2026-10-13T17:59:00Z', NOW, DHAKA)).toBe('yesterday');
        expect(dayBucket('2026-10-11T08:00:00Z', NOW, DHAKA)).toBe('week');
        expect(dayBucket('2026-10-08T08:00:00Z', NOW, DHAKA)).toBe('week');
        expect(dayBucket('2026-10-07T08:00:00Z', NOW, DHAKA)).toBe('year');
        expect(dayBucket('2025-12-30T08:00:00Z', NOW, DHAKA)).toBe('older');
        // A clock slightly ahead still reads as now.
        expect(dayBucket('2026-10-15T08:00:00Z', NOW, DHAKA)).toBe('today');
    });

    it('labels list times and separators from the bucket', () => {
        const labels: DayLabels = { today: 'Today', yesterday: 'Yesterday', time: () => 'TIME', weekday: () => 'WEEKDAY', short: () => 'SHORT', date: () => 'DATE' };

        expect(['2026-10-14T04:00:00Z', '2026-10-13T08:00:00Z', '2026-10-11T08:00:00Z', '2026-09-01T08:00:00Z', '2025-09-01T08:00:00Z'].map((at) => listTimeLabel(at, NOW, DHAKA, labels)))
            .toEqual(['TIME', 'Yesterday', 'WEEKDAY', 'SHORT', 'DATE']);
        expect(['2026-10-14T04:00:00Z', '2026-10-13T08:00:00Z', '2026-10-11T08:00:00Z', '2026-09-01T08:00:00Z', '2025-09-01T08:00:00Z'].map((at) => separatorLabel(at, NOW, DHAKA, labels)))
            .toEqual(['Today', 'Yesterday', 'WEEKDAY', 'DATE', 'DATE']);
    });

    it('names the weekday through the shared formatter, in the workspace timezone and language', () => {
        const context = { locale: 'en', numerals: 'latn' as const, timezone: DHAKA, currency: 'BDT' };

        expect(formatDate('2026-10-12T06:00:00Z', context, 'weekday')).toBe('Monday');
        // 23:30 UTC on Monday is already Tuesday in Dhaka.
        expect(formatDate('2026-10-12T23:30:00Z', context, 'weekday')).toBe('Tuesday');
        expect(formatDate('2026-10-12T06:00:00Z', { ...context, locale: 'bn', numerals: 'beng' }, 'weekday')).toBe('সোমবার');
    });
});

describe('delivery ticks', () => {
    it('normalises every status the chat sources send for outgoing messages', () => {
        const tick = (status: string | null, direction: 'in' | 'out' = 'out') => deliveryState({ direction, status });

        // WhatsApp: the outbox states.
        expect(tick('queued')).toEqual({ tick: 'pending', label: 'queued' });
        expect(tick('sent')).toEqual({ tick: 'sent', label: 'sent' });
        expect(tick('delivered')).toEqual({ tick: 'delivered', label: 'delivered' });
        expect(tick('read')).toEqual({ tick: 'read', label: 'read' });
        expect(tick('failed')).toEqual({ tick: 'failed', label: 'failed' });
        expect(tick('blocked')).toEqual({ tick: 'failed', label: 'blocked' });
        // The page's own reply on its way, and a future source's word for waiting.
        expect(tick('sending')).toEqual({ tick: 'pending', label: 'sending' });
        expect(tick('pending')).toEqual({ tick: 'pending', label: 'queued' });
        // Comment states, inbound messages, nothing and anything unknown: no tick.
        for (const status of ['hidden', 'deleted', 'received', 'toString', 'weird', null]) {
            expect(tick(status)).toBeNull();
        }
        expect(tick('read', 'in')).toBeNull();
    });
});

describe('avatars', () => {
    it('takes one or two initials from the first words that start with a letter', () => {
        expect(initialsOf('Rahim Uddin')).toBe('RU');
        expect(initialsOf('Nadia Karim on Brand Page')).toBe('NK');
        expect(initialsOf('karim')).toBe('K');
        expect(initialsOf('  +8801711000001 ')).toBe('');
        expect(initialsOf('রহিম উদ্দিন')).toBe('রউ');
    });

    it('picks the same tested tint for the same conversation every time', () => {
        const key = `whatsapp_marketing.conversations:${ID}`;

        expect(avatarTint(key)).toBe(avatarTint(key));
        expect(AVATAR_TINTS).toContain(avatarTint(key));
        expect(new Set(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'].map((seed) => avatarTint(`social.comments:${seed}`))).size).toBeGreaterThan(1);
    });

    it('picks an attachment icon by kind and a paperclip otherwise', () => {
        expect(attachmentIcon('image')).not.toBe(attachmentIcon(null));
        expect(attachmentIcon('video')).not.toBe(attachmentIcon('audio'));
        expect(attachmentIcon('toString')).toBe(attachmentIcon(undefined));
    });
});

describe('links in message text', () => {
    it('links http and https addresses and leaves the sentence around them as text', () => {
        expect(linkify('See https://example.com/a?b=1&c=2. Thanks')).toEqual([
            { kind: 'text', text: 'See ' },
            { kind: 'link', text: 'https://example.com/a?b=1&c=2', href: 'https://example.com/a?b=1&c=2' },
            { kind: 'text', text: '. Thanks' },
        ]);
        expect(linkify('(details at http://shop.test/item_(blue)), ok')).toEqual([
            { kind: 'text', text: '(details at ' },
            { kind: 'link', text: 'http://shop.test/item_(blue)', href: 'http://shop.test/item_(blue)' },
            { kind: 'text', text: '), ok' },
        ]);
        expect(linkify('HTTPS://Example.com')).toEqual([{ kind: 'link', text: 'HTTPS://Example.com', href: 'https://example.com/' }]);
    });

    it('never links another scheme, and keeps markup and entities as text', () => {
        for (const text of ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x', 'ftp://files.test/a', 'xhttps://a.test', 'https://', '<b>bold</b> &amp; &lt;i&gt;']) {
            expect(linkify(text)).toEqual([{ kind: 'text', text }]);
        }

        expect(linkify('<a href="https://evil.test">x</a>')).toEqual([
            { kind: 'text', text: '<a href="' },
            { kind: 'link', text: 'https://evil.test', href: 'https://evil.test/' },
            { kind: 'text', text: '">x</a>' },
        ]);
        expect(linkify('')).toEqual([]);
    });
});

describe('the conversation as rows', () => {
    it('separates days in the workspace timezone and groups a side within five minutes', () => {
        const items = chatItems([
            message({ id: 'a', at: '2026-10-13T17:50:00Z' }),
            // 00:01 on the 14th in Dhaka: a new day, so a new group.
            message({ id: 'b', at: '2026-10-13T18:01:00Z' }),
            message({ id: 'c', at: '2026-10-13T18:04:00Z' }),
            message({ id: 'd', at: '2026-10-13T18:10:00Z' }),
            message({ id: 'e', direction: 'out', author: 'Owner', at: '2026-10-13T18:11:00Z' }),
        ], DHAKA);

        expect(items.map((item) => (item.kind === 'day' ? item.key : `${item.key}:${item.first ? 'first' : 'cont'}:${item.last ? 'last' : ''}`))).toEqual([
            'day:2026-10-13', 'a:first:last',
            'day:2026-10-14', 'b:first:', 'c:cont:last', 'd:first:last', 'e:first:last',
        ]);
    });

    it('names authors only when a side has more than one in view, and a new author starts a group', () => {
        const one = chatItems([message({ id: 'a', direction: 'out', author: 'Owner' }), message({ id: 'b', direction: 'out', author: 'Owner' })], DHAKA);
        expect(one.filter((item) => item.kind === 'message' && item.showAuthor)).toHaveLength(0);

        const two = chatItems([
            message({ id: 'a', direction: 'out', author: 'Owner' }),
            message({ id: 'b', direction: 'out', author: 'Owner', at: '2026-10-14T04:01:00Z' }),
            message({ id: 'c', direction: 'out', author: 'Agent', at: '2026-10-14T04:02:00Z' }),
        ], DHAKA).filter((item) => item.kind === 'message');

        expect(two.map((item) => [item.key, item.first, item.showAuthor])).toEqual([['a', true, true], ['b', false, false], ['c', true, true]]);
    });
});

describe('scrolling', () => {
    it('knows when the log is at its newest message or at the top', () => {
        expect(isNearBottom({ scrollTop: 1520, scrollHeight: 2000, clientHeight: 400 })).toBe(true);
        expect(isNearBottom({ scrollTop: 1000, scrollHeight: 2000, clientHeight: 400 })).toBe(false);
        expect(isNearBottom({ scrollTop: 0, scrollHeight: 0, clientHeight: 0 })).toBe(true);
        expect(isNearTop({ scrollTop: 40 })).toBe(true);
        expect(isNearTop({ scrollTop: 400 })).toBe(false);
    });

    it('pins to the newest message when the reader is there, and otherwise counts what arrived', () => {
        const ids = (...list: string[]) => list.map((id) => ({ id }));

        expect(planScroll(null, ids('a', 'b'), false)).toEqual({ action: 'bottom', unseen: 0 });
        expect(planScroll(ids('a', 'b'), ids('a', 'b', 'c', 'd'), true)).toEqual({ action: 'bottom', unseen: 0 });
        expect(planScroll(ids('a', 'b'), ids('a', 'b', 'c', 'd'), false)).toEqual({ action: 'anchor', unseen: 2 });
        expect(planScroll(ids('a', 'b'), ids('a', 'b', 'c'), false, true)).toEqual({ action: 'bottom', unseen: 0 });
        // Older messages above: keep the place.
        expect(planScroll(ids('c', 'd'), ids('a', 'b', 'c', 'd'), false)).toEqual({ action: 'anchor', unseen: 0 });
        expect(planScroll(ids('a', 'b'), ids('a', 'b'), true)).toEqual({ action: 'anchor', unseen: 0 });
        // The page's own reply replaced by the stored one.
        expect(planScroll(ids('a', 'pending-1'), ids('a', 'b'), true)).toEqual({ action: 'bottom', unseen: 0 });
        expect(planScroll([], ids('a'), false)).toEqual({ action: 'bottom', unseen: 0 });
    });
});

describe('composer keys', () => {
    const key = (overrides: Partial<KeyboardEvent>) => ({ key: 'Enter', ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, isComposing: false, ...overrides });

    it('sends on Enter on a desktop, and Shift+Enter starts a new line', () => {
        expect(shouldSend(key({}), true)).toBe(true);
        expect(shouldSend(key({ shiftKey: true }), true)).toBe(false);
        expect(shouldSend(key({ isComposing: true }), true)).toBe(false);
        expect(shouldSend(key({ key: 'a' }), true)).toBe(false);
    });

    it('keeps Enter a new line on touch and narrow screens, and Ctrl or Cmd+Enter always sends', () => {
        expect(shouldSend(key({}), false)).toBe(false);
        expect(shouldSend(key({ ctrlKey: true }), false)).toBe(true);
        expect(shouldSend(key({ metaKey: true }), true)).toBe(true);
    });
});

describe('the list merge', () => {
    const r = (key: string, preview = key) => ({ key, preview });

    it('updates rows already shown, puts new ones first and keeps the pages loaded below', () => {
        const shown = [r('a'), r('b'), r('c'), r('d'), r('e')];

        expect(mergeFirstPage(shown, [r('x'), r('c', 'c2'), r('a', 'a2')])).toEqual([r('x'), r('c', 'c2'), r('a', 'a2'), r('b'), r('d'), r('e')]);
        expect(mergeFirstPage(shown, [])).toEqual(shown);
        expect(appendPage([r('a'), r('b')], [r('b', 'b2'), r('c')])).toEqual([r('a'), r('b'), r('c')]);
    });
});

// ---- components ---------------------------------------------------------------------------------------------------

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

const thread = (overrides: Partial<InboxThreadView> = {}): InboxThreadView => ({
    key: `whatsapp_marketing.conversations:${ID}`, source: 'whatsapp_marketing.conversations', id: ID, sourceLabel: 'WhatsApp', channel: 'whatsapp', marksRead: false,
    title: 'Rahim Uddin', subtitle: '+8801711000001', contactId: 'contact-1', url: `/whatsapp/inbox/${ID}`,
    replyAllowed: true, canReply: true, replyBlockedReason: null, hasOlder: false, limit: 50,
    messages: [
        message({ id: 'm1', text: 'Is it in stock?', at: '2026-10-13T08:00:00Z' }),
        message({ id: 'm2', direction: 'out', text: 'Yes.', at: '2026-10-14T04:00:00Z', author: 'Owner', status: 'delivered' }),
    ],
    ...overrides,
});

function stubMedia(matches: (query: string) => boolean): void {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: matches(query), media: query, onchange: null, addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false }));
}

/** jsdom lays nothing out: give the log a size and a scroll position. */
function layOut(element: Element, box: { scrollHeight: number; clientHeight: number; scrollTop: number }): void {
    let top = box.scrollTop;
    Object.defineProperty(element, 'scrollHeight', { configurable: true, get: () => box.scrollHeight });
    Object.defineProperty(element, 'clientHeight', { configurable: true, get: () => box.clientHeight });
    Object.defineProperty(element, 'scrollTop', { configurable: true, get: () => top, set: (value: number) => { top = value; } });
}

const fetchMock = vi.fn<(input: string, init?: RequestInit) => Promise<Response>>(async () => new Response(null, { status: 204 }));

beforeEach(() => {
    setLocale('en');
    inertia.router.get.mockClear();
    inertia.router.post.mockClear();
    inertia.router.reload.mockClear();
    fetchMock.mockReset();
    fetchMock.mockImplementation(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('The open conversation', () => {
    it('separates days, shows ticks with their names and keeps unknown statuses quiet', () => {
        vi.useFakeTimers({ now: NOW });
        const statuses = ['queued', 'sent', 'delivered', 'read', 'failed', 'blocked', 'weird'];
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({
            messages: [
                message({ id: 'y1', text: 'Yesterday evening', at: '2026-10-13T12:00:00Z' }),
                ...statuses.map((status, index) => message({ id: `o${index}`, direction: 'out', text: status, status, at: `2026-10-14T04:0${index}:00Z` })),
            ],
        }) } }));

        expect(wrapper.findAll('[data-day]').map((day) => day.text())).toEqual(['Yesterday', 'Today']);
        const ticks = wrapper.findAll('[data-tick]');
        expect(ticks.map((tick) => [tick.attributes('data-tick'), tick.attributes('aria-label')])).toEqual([
            ['pending', 'Queued'], ['sent', 'Sent'], ['delivered', 'Delivered'], ['read', 'Read'], ['failed', 'Failed'], ['failed', 'Blocked'],
        ]);
        expect(ticks.every((tick) => tick.attributes('role') === 'img' && tick.find('svg').exists())).toBe(true);
        expect(ticks[3]!.classes()).toContain('text-accent-text');
        expect(ticks[4]!.classes()).toContain('text-danger');
        // One group of outgoing messages: one tail, tighter spacing after it.
        expect(wrapper.findAll('li[data-direction="out"]').map((item) => item.classes().includes('mt-2'))).toEqual([true, false, false, false, false, false, false]);
    });

    it('renders message text as text and only http links as safe links', () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({
            messages: [message({ id: 'x', text: 'Order at https://shop.test/p?id=1&x=2. Not javascript:alert(1) <b>bold</b> &amp;\nsecond line' })],
        }) } }));
        const links = wrapper.findAll('[data-message-id="x"] a');

        expect(links).toHaveLength(1);
        expect(links[0]!.attributes()).toMatchObject({ href: 'https://shop.test/p?id=1&x=2', target: '_blank', rel: 'noopener noreferrer nofollow' });
        expect(links[0]!.text()).toBe('https://shop.test/p?id=1&x=2');
        expect(wrapper.find('[data-message-id="x"] b').exists()).toBe(false);
        expect(wrapper.get('[data-message-id="x"] p').text()).toContain('javascript:alert(1) <b>bold</b> &amp;\nsecond line');
    });

    it('shows an attachment as a chip with an icon of its kind', () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({
            messages: [message({ id: 'p', text: null, attachment: 'Image', attachmentKind: 'image' }), message({ id: 'q', text: null, attachment: 'Sticker' })],
        }) } }));

        expect(wrapper.get('[data-message-id="p"] [data-attachment]').attributes('data-attachment')).toBe('image');
        expect(wrapper.get('[data-message-id="p"] [data-attachment] svg').classes()).toContain('lucide-image');
        expect(wrapper.get('[data-message-id="p"]').text()).toContain('Attachment: Image');
        expect(wrapper.get('[data-message-id="q"] [data-attachment] svg').classes()).toContain('lucide-paperclip');
    });

    it('replaces the composer with the reason and the way to the app when a reply cannot be written', () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({ canReply: false, replyBlockedReason: 'The 24-hour window has closed. Only an approved template can be sent now: send one from the WhatsApp inbox.' }) } }));

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(false);
        const banner = wrapper.get('footer [role="status"]');
        expect(banner.text()).toContain('send one from the WhatsApp inbox');
        expect(banner.get(`a[href="/whatsapp/inbox/${ID}"]`).text()).toContain('Open in app');

        const readOnly = track(mount(InboxThreadPane, { props: { thread: thread({ replyAllowed: false, canReply: false }) } }));
        expect(readOnly.find('footer').exists()).toBe(false);
    });

    it('sends on Enter on a desktop and starts a new line on Shift+Enter', async () => {
        stubMedia((query) => query === ENTER_SENDS_QUERY);
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread() } }));
        const textarea = wrapper.get('textarea');

        await textarea.setValue('Line one');
        await textarea.trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(inertia.router.post).not.toHaveBeenCalled();

        await textarea.trigger('keydown', { key: 'Enter' });
        expect(inertia.router.post).toHaveBeenCalledTimes(1);
        expect(inertia.router.post.mock.calls[0]![1]).toEqual({ text: 'Line one' });
        expect(wrapper.text()).toContain('Enter sends the reply. Shift+Enter starts a new line.');
    });

    it('keeps Enter a new line on a touch screen', async () => {
        stubMedia(() => false);
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread() } }));
        const textarea = wrapper.get('textarea');

        await textarea.setValue('Line one');
        await textarea.trigger('keydown', { key: 'Enter' });
        expect(inertia.router.post).not.toHaveBeenCalled();
        await textarea.trigger('keydown', { key: 'Enter', ctrlKey: true });
        expect(inertia.router.post).toHaveBeenCalledTimes(1);
    });

    it('shows a reply as sending at once, keeps the text when it is refused, and gives way to the stored message', async () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread() } }));
        const textarea = wrapper.get('textarea');

        await textarea.setValue('On its way');
        await wrapper.get('form').trigger('submit');
        const sending = wrapper.get('li[data-pending="true"]');
        expect(sending.text()).toContain('On its way');
        expect(sending.get('[data-tick]').attributes()).toMatchObject({ 'data-tick': 'pending', 'aria-label': 'Sending' });
        expect((textarea.element as HTMLTextAreaElement).value).toBe('');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();

        const [, , refused] = inertia.router.post.mock.calls[0]! as [string, unknown, { onError: (errors: Record<string, string>) => void; onFinish: () => void }];
        refused.onError({ text: 'The 24-hour window has closed.' });
        refused.onFinish();
        await nextTick();
        expect(wrapper.find('li[data-pending="true"]').exists()).toBe(false);
        expect((textarea.element as HTMLTextAreaElement).value).toBe('On its way');
        expect(wrapper.get('[role="alert"]').text()).toBe('The 24-hour window has closed.');
        expect(textarea.attributes('aria-invalid')).toBe('true');

        // Again, and this time the server stores it: the stored message replaces the one on its way.
        await wrapper.get('form').trigger('submit');
        const [, , accepted] = inertia.router.post.mock.calls[1]! as [string, unknown, { onFinish: () => void }];
        await wrapper.setProps({ thread: thread({ messages: [...thread().messages, message({ id: 'm9', direction: 'out', text: 'On its way', at: new Date().toISOString(), author: 'Owner', status: 'sent' })] }) });
        expect(wrapper.find('li[data-pending="true"]').exists()).toBe(false);
        accepted.onFinish();
        await nextTick();
        expect(wrapper.findAll('li[data-direction="out"]').map((item) => item.attributes('data-message-id'))).toEqual(['m2', 'm9']);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('shows the character count only near the limit', async () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread() } }));

        await wrapper.get('textarea').setValue('a'.repeat(100));
        expect(wrapper.text()).not.toContain('characters left');
        await wrapper.get('textarea').setValue('a'.repeat(3900));
        expect(wrapper.text()).toContain('196 characters left');
    });

    it('offers a jump to the newest message with a count when news arrives while reading older ones', async () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread() } }));
        const log = wrapper.get('[data-testid="inbox-scroller"]');
        layOut(log.element, { scrollHeight: 2000, clientHeight: 400, scrollTop: 600 });

        await log.trigger('scroll');
        expect(wrapper.find('[data-testid="jump-to-latest"]').exists()).toBe(true);

        await wrapper.setProps({ thread: thread({ messages: [...thread().messages, message({ id: 'n1', text: 'Still there?', at: '2026-10-14T04:30:00Z' })] }) });
        const jump = wrapper.get('[data-testid="jump-to-latest"]');
        expect(jump.text()).toContain('1 new');
        expect(jump.text()).toContain('Jump to the latest message');

        await jump.trigger('click');
        expect(log.element.scrollTop).toBe(2000);
        expect(wrapper.find('[data-testid="jump-to-latest"]').exists()).toBe(false);
    });

    it('stays at the newest message when the reader is there', async () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread() } }));
        const log = wrapper.get('[data-testid="inbox-scroller"]');
        layOut(log.element, { scrollHeight: 1000, clientHeight: 400, scrollTop: 600 });

        await wrapper.setProps({ thread: thread({ messages: [...thread().messages, message({ id: 'n1', at: '2026-10-14T04:30:00Z' })] }) });
        await nextTick();
        expect(log.element.scrollTop).toBe(1000);
        expect(wrapper.find('[data-testid="jump-to-latest"]').exists()).toBe(false);
    });

    it('asks for older messages once when scrolled to the top, and keeps a button for the keyboard', async () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({ hasOlder: true }) } }));
        const log = wrapper.get('[data-testid="inbox-scroller"]');
        layOut(log.element, { scrollHeight: 2000, clientHeight: 400, scrollTop: 30 });

        await log.trigger('scroll');
        await log.trigger('scroll');
        expect(wrapper.emitted('older')).toEqual([[100]]);

        await wrapper.setProps({ olderLoading: true });
        await wrapper.setProps({ olderLoading: false, thread: thread({ hasOlder: false, limit: 100 }) });
        await log.trigger('scroll');
        expect(wrapper.emitted('older')).toHaveLength(1);

        const again = track(mount(InboxThreadPane, { props: { thread: thread({ hasOlder: true }) } }));
        await again.findAll('button').find((button) => button.text() === 'Load older messages')!.trigger('click');
        expect(again.emitted('older')).toEqual([[100]]);
    });
});

describe('Suggest a reply', () => {
    const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    const composer = (wrapper: VueWrapper) => wrapper.get('textarea').element as HTMLTextAreaElement;

    it('is offered only when the thread allows drafting and a reply can be written', () => {
        expect(track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: true }) } })).find('[data-testid="suggest-reply"]').exists()).toBe(true);
        expect(track(mount(InboxThreadPane, { props: { thread: thread() } })).find('[data-testid="suggest-reply"]').exists()).toBe(false);
        expect(track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: true, canReply: false, replyBlockedReason: 'Closed.' }) } })).find('[data-testid="suggest-reply"]').exists()).toBe(false);
        expect(track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: false, replyAllowed: false, canReply: false }) } })).find('[data-testid="suggest-reply"]').exists()).toBe(false);
    });

    it('puts the suggestion into the composer, labelled, without sending it, and can restore the member\'s text', async () => {
        fetchMock.mockImplementationOnce(async () => json({ state: 'ok', text: 'Yes, the blue one is in stock.', message: '', retryable: false }));
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: true }) } }));
        await wrapper.get('textarea').setValue('Let me check');

        const button = wrapper.get('[data-testid="suggest-reply"]');
        expect(button.text()).toBe('Suggest a reply');
        expect(button.find('svg').exists()).toBe(true);
        await button.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]![0]).toBe(`/communication.inbox.suggest?source=whatsapp_marketing.conversations&id=${ID}`);
        expect(fetchMock.mock.calls[0]![1]).toMatchObject({ method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } });
        expect(composer(wrapper).value).toBe('Yes, the blue one is in stock.');
        expect(wrapper.get('[data-testid="suggest-hint"]').text()).toContain('AI-generated. Check it before sending.');
        // Nothing was sent.
        expect(inertia.router.post).not.toHaveBeenCalled();

        await wrapper.findAll('button').find((candidate) => candidate.text() === 'Restore my text')!.trigger('click');
        expect(composer(wrapper).value).toBe('Let me check');
        expect(wrapper.find('[data-testid="suggest-hint"]').exists()).toBe(false);
    });

    it('sends an edited suggestion only when the member sends it, and the label goes with it', async () => {
        fetchMock.mockImplementationOnce(async () => json({ state: 'ok', text: 'Yes, it is.', message: '', retryable: false }));
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: true }) } }));
        await wrapper.get('[data-testid="suggest-reply"]').trigger('click');
        await flushPromises();
        await wrapper.get('textarea').setValue('Yes, it is. Shall we send it today?');
        expect(wrapper.find('[data-testid="suggest-hint"]').exists()).toBe(true);

        await wrapper.get('form').trigger('submit');
        expect(inertia.router.post).toHaveBeenCalledTimes(1);
        expect(inertia.router.post.mock.calls[0]![0]).toBe(`/communication.inbox.reply?source=whatsapp_marketing.conversations&id=${ID}`);
        expect(inertia.router.post.mock.calls[0]![1]).toEqual({ text: 'Yes, it is. Shall we send it today?' });
        await nextTick();
        expect(wrapper.find('[data-testid="suggest-hint"]').exists()).toBe(false);
    });

    it('shows the server\'s safe message when no suggestion can be made, and keeps the composer', async () => {
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: true }) } }));
        await wrapper.get('textarea').setValue('My own words');
        const answers: Array<[Response, string]> = [
            [json({ state: 'not_configured', text: '', message: 'AI is not configured on this platform yet.', retryable: false }), 'AI is not configured on this platform yet.'],
            [json({ state: 'limit_reached', text: '', message: 'This workspace has used its AI allowance for the period.', retryable: false }), 'This workspace has used its AI allowance for the period.'],
            [json({ message: 'The given data was invalid.', errors: { text: ['There is no customer message to reply to yet.'] } }, 422), 'There is no customer message to reply to yet.'],
            [json({ message: 'Too Many Attempts.' }, 429), 'No suggestion could be made. Try again in a minute.'],
        ];

        for (const [response, shown] of answers) {
            fetchMock.mockImplementationOnce(async () => response);
            await wrapper.get('[data-testid="suggest-reply"]').trigger('click');
            await flushPromises();

            expect(wrapper.get('[data-testid="suggest-notice"]').text()).toBe(shown);
            expect(composer(wrapper).value).toBe('My own words');
            expect(wrapper.find('[data-testid="suggest-hint"]').exists()).toBe(false);
        }

        fetchMock.mockImplementationOnce(async () => { throw new TypeError('offline'); });
        await wrapper.get('[data-testid="suggest-reply"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="suggest-notice"]').text()).toBe('No suggestion could be made. Try again in a minute.');
        expect(inertia.router.post).not.toHaveBeenCalled();
    });

    it('drops an answer that arrives after another conversation was opened', async () => {
        let finish: (response: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => { finish = resolve; }));
        const wrapper = track(mount(InboxThreadPane, { props: { thread: thread({ aiDrafting: true }) } }));
        await wrapper.get('[data-testid="suggest-reply"]').trigger('click');

        await wrapper.setProps({ thread: thread({ aiDrafting: true, key: 'support.tickets:01JABCDEFGHJKMNPQRSTVWXYZ1', source: 'support.tickets', id: '01JABCDEFGHJKMNPQRSTVWXYZ1' }) });
        finish(json({ state: 'ok', text: 'For the other conversation', message: '', retryable: false }));
        await flushPromises();

        expect(composer(wrapper).value).toBe('');
        expect(wrapper.find('[data-testid="suggest-hint"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="suggest-notice"]').exists()).toBe(false);
    });
});

describe('The conversation list', () => {
    const row = (key: string, overrides: Partial<InboxRow> = {}): InboxRow => ({
        key: `whatsapp_marketing.conversations:${key}`, source: 'whatsapp_marketing.conversations', sourceLabel: 'WhatsApp', channel: 'whatsapp',
        title: `Customer ${key}`, preview: `Preview ${key}`, at: '2026-10-14T04:00:00Z', state: 'unassigned', owner: null, unread: 0,
        url: `/whatsapp/inbox/${key}`, contactId: null, chat: true, ...overrides,
    });
    const sources = [{ key: 'whatsapp_marketing.conversations', label: 'WhatsApp', channel: 'whatsapp' }, { key: 'support.tickets', label: 'Support tickets', channel: 'support' }];
    const filters = { view: 'list' as const, source: null, owner: null, state: 'open' as const, search: '', open: null };
    const counts = { unassigned: 2, active: 0, closed: 0 };
    const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    const titles = (wrapper: VueWrapper) => wrapper.findAll('[data-row-key]').map((element) => element.attributes('data-row-key')!.split(':')[1]);

    it('refreshes itself every 20 seconds, merging the newest page into the loaded ones', async () => {
        vi.useFakeTimers({ now: NOW });
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: { items: [row('a'), row('b')], next: 'pos-1', after: null } } }));
        await wrapper.setProps({ list: { items: [row('c')], next: 'pos-2', after: 'pos-1' } });
        expect(titles(wrapper)).toEqual(['a', 'b', 'c']);

        fetchMock.mockImplementationOnce(async () => json({ items: [row('n', { unread: 2, preview: 'New here' }), row('b', { preview: 'Newer from b' })], next: 'other' }));
        await vi.advanceTimersByTimeAsync(LIST_POLL_INTERVAL);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]![0]).toBe('/communication.inbox.poll');
        expect(titles(wrapper)).toEqual(['n', 'b', 'a', 'c']);
        expect(wrapper.text()).toContain('Newer from b');
        expect(wrapper.get('[data-row-key$=":n"]').text()).toContain('2 unread');

        // "Load more" continues after the pages already loaded, not after the refreshed first page.
        await wrapper.findAll('button').find((button) => button.text() === 'Load more')!.trigger('click');
        expect(inertia.router.get.mock.calls.at(-1)![1]).toMatchObject({ after: 'pos-2' });
    });

    it('asks with the filters on screen, never twice at once and not while the tab is hidden', async () => {
        vi.useFakeTimers({ now: NOW });
        const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
        let finish: (response: Response) => void = () => {};
        fetchMock.mockImplementation(() => new Promise<Response>((resolve) => { finish = resolve; }));
        track(mount(InboxIndex, { props: { sources, filters: { ...filters, owner: 'me' as const, state: 'all' as const, search: 'rahim' }, counts, columns: null, list: { items: [row('a')], next: null, after: null } } }));

        await vi.advanceTimersByTimeAsync(LIST_POLL_INTERVAL * 3);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]![0]).toBe('/communication.inbox.poll?owner=me&state=all&search=rahim');
        expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: 'same-origin', headers: { Accept: 'application/json' } });

        finish(json({ items: [], next: null }));
        await flushPromises();
        hidden.mockReturnValue(true);
        await vi.advanceTimersByTimeAsync(LIST_POLL_INTERVAL * 3);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        hidden.mockReturnValue(false);
        await vi.advanceTimersByTimeAsync(LIST_POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        hidden.mockRestore();
    });

    it('drops a refresh that answers for filters no longer on screen', async () => {
        vi.useFakeTimers({ now: NOW });
        let finish: (response: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => { finish = resolve; }));
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: { items: [row('a')], next: null, after: null } } }));

        await vi.advanceTimersByTimeAsync(LIST_POLL_INTERVAL);
        await wrapper.setProps({ filters: { ...filters, owner: 'me' as const }, list: { items: [row('m')], next: null, after: null } });
        finish(json({ items: [row('stale')], next: null }));
        await flushPromises();

        expect(titles(wrapper)).toEqual(['m']);
    });

    it('shows avatars, list times, unread pills and the selected row', async () => {
        vi.useFakeTimers({ now: NOW });
        const wrapper = track(mount(InboxIndex, { props: {
            sources, filters: { ...filters, open: `whatsapp_marketing.conversations:${ID}` }, counts, columns: null,
            list: { items: [
                row(ID, { title: 'Rahim Uddin', unread: 3, at: '2026-10-14T04:00:00Z' }),
                row('y', { at: '2026-10-13T08:00:00Z' }),
                row('w', { at: '2026-10-12T08:00:00Z' }),
                row('o', { at: '2026-09-01T08:00:00Z', title: '+8801711000001' }),
            ], next: null, after: null },
            thread: thread(),
        } }));
        const items = wrapper.findAll('[data-row-key]');

        expect(items[0]!.find('[aria-hidden="true"][data-tint]').text()).toBe('RU');
        expect(items[3]!.find('[data-tint] svg').exists()).toBe(true);
        const context = { locale: 'en', numerals: 'latn' as const, timezone: DHAKA, currency: 'BDT' };
        expect(items.map((item) => item.get('time').text())).toEqual([formatDate('2026-10-14T04:00:00Z', context, 'time'), 'Yesterday', 'Monday', formatDate('2026-09-01T08:00:00Z', context, 'short')]);
        expect(items[0]!.get('time').text()).toMatch(/^10:00\s?AM$/u);
        expect(items[0]!.text()).toContain('3 unread');
        expect(items[0]!.get('.bg-accent').text()).toBe('3');
        expect(items[0]!.attributes('aria-current')).toBe('true');
        expect(items[0]!.classes()).toContain('bg-hover');
        expect(items[1]!.text()).toContain('Unassigned');
    });

    it('asks the server for every chip, Unread included, and shows what it answers', async () => {
        const wrapper = track(mount(InboxIndex, { props: { sources, filters, counts, columns: null, list: { items: [row('a', { unread: 1 }), row('b')], next: 'pos-1', after: null } } }));

        // "All" with nothing to clear asks for nothing.
        await wrapper.get('[data-chip="all"]').trigger('click');
        expect(inertia.router.get).not.toHaveBeenCalled();

        await wrapper.get('[data-chip="unread"]').trigger('click');
        expect(inertia.router.get).toHaveBeenCalledTimes(1);
        expect(inertia.router.get.mock.calls[0]![1]).toMatchObject({ unread: '1' });
        // Nothing is narrowed on the page: the rows are the server's.
        expect(titles(wrapper)).toEqual(['a', 'b']);

        await wrapper.get('[data-chip="mine"]').trigger('click');
        expect(inertia.router.get.mock.calls[1]![1]).toMatchObject({ owner: 'me', unread: undefined });
        await wrapper.get('[data-chip="unassigned"]').trigger('click');
        expect(inertia.router.get.mock.calls[2]![1]).toMatchObject({ owner: 'unassigned' });
        expect(wrapper.get('[data-chip="state"]').attributes('aria-label')).toBe('Status: Open');
        expect(wrapper.get('[data-chip="source"]').attributes('aria-label')).toBe('Channel: All channels');
    });

    it('keeps the server\'s Unread filter for paging, the refresh and the other chips, and clears it with All', async () => {
        vi.useFakeTimers({ now: NOW });
        const unreadFilters = { ...filters, unread: true };
        const wrapper = track(mount(InboxIndex, { props: { sources, filters: unreadFilters, counts, columns: null, list: { items: [row('a', { unread: 1 })], next: 'pos-1', after: null } } }));

        expect(wrapper.get('[data-chip="unread"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('[data-chip="all"]').attributes('aria-pressed')).toBe('false');

        fetchMock.mockImplementationOnce(async () => json({ items: [row('a', { unread: 2 })], next: null }));
        await vi.advanceTimersByTimeAsync(LIST_POLL_INTERVAL);
        await flushPromises();
        expect(fetchMock.mock.calls[0]![0]).toBe('/communication.inbox.poll?unread=1');

        await wrapper.findAll('button').find((button) => button.text() === 'Load more')!.trigger('click');
        expect(inertia.router.get.mock.calls.at(-1)![1]).toMatchObject({ unread: '1', after: 'pos-1' });

        await wrapper.get('[data-chip="mine"]').trigger('click');
        expect(inertia.router.get.mock.calls.at(-1)![1]).toMatchObject({ owner: 'me', unread: '1' });

        await wrapper.get('[data-chip="unread"]').trigger('click');
        expect(inertia.router.get.mock.calls.at(-1)![1]).toMatchObject({ unread: undefined });

        await wrapper.get('[data-chip="all"]').trigger('click');
        expect(inertia.router.get.mock.calls.at(-1)![1]).toMatchObject({ owner: undefined, unread: undefined });

        // The server found nothing unread: say so, with the way back to everything.
        await wrapper.setProps({ list: { items: [], next: null, after: null }, filters: { ...unreadFilters, search: 'x' } });
        expect(wrapper.text()).toContain('No unread conversations');
        expect(wrapper.text()).toContain('Every conversation that matches these filters has been read.');
    });

    it('closes the open conversation with Escape on a narrow screen only', async () => {
        const props = { sources, filters: { ...filters, open: `whatsapp_marketing.conversations:${ID}` }, counts, columns: null, list: { items: [row(ID)], next: null, after: null }, thread: thread() };
        track(mount(InboxIndex, { props, attachTo: document.body }));

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(inertia.router.get).not.toHaveBeenCalled();

        stubMedia((query) => query.includes('max-width'));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(inertia.router.get).toHaveBeenCalledTimes(1);
        expect(inertia.router.get.mock.calls[0]![1]).toMatchObject({ open: undefined });
    });
});
