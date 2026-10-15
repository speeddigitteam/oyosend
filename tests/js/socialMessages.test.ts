import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.messages.view', 'social.messages.reply', 'social.accounts.manage'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    submissions: [] as { method: string; url: string; data: Record<string, unknown> }[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        const defaults = structuredClone(initial);
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.reset = () => { Object.assign(form, structuredClone(defaults)); return form; };
        form.data = () => Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));
        form.post = (url: string, options?: { onSuccess?: () => void }) => {
            inertia.submissions.push({ method: 'post', url, data: form.data() });
            options?.onSuccess?.();
        };
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
import type { ConversationRow, ConversationThread } from '@/Modules/Social/Components/messages/messageTypes';
import MessagesIndex from '@/Modules/Social/Pages/Messages/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

function row(overrides: Partial<ConversationRow> = {}): ConversationRow {
    return {
        id: 'c1', platform: 'messenger', title: 'Messenger user ••1234', accountName: 'Acme Bakery',
        preview: 'দাম কত? <img src=x onerror=alert(1)>', lastMessageAt: '2026-10-14T04:00:00+00:00', unread: 2, status: 'open', ownerName: null,
        ...overrides,
    };
}

function thread(overrides: Partial<ConversationThread> = {}): ConversationThread {
    return {
        ...row(),
        owner: null,
        messages: [
            { id: 'm1', direction: 'in', text: 'দাম কত? <img src=x onerror=alert(1)>', at: '2026-10-14T04:00:00+00:00', authorName: null, status: 'received', attachment: null },
            { id: 'm2', direction: 'in', text: null, at: '2026-10-14T04:01:00+00:00', authorName: null, status: 'received', attachment: 'Image' },
            { id: 'm3', direction: 'out', text: 'Yes, in stock.', at: '2026-10-14T04:02:00+00:00', authorName: 'Nadia Karim', status: 'delivered', attachment: null },
        ],
        hasOlder: false, canReply: true, replyable: true, blockedReason: null, windowEndsAt: '2026-10-15T04:00:00+00:00', textMax: 2000,
        ...overrides,
    };
}

function props(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        availability: { problem: null, platforms: ['messenger', 'instagram'], instagram: true, windowHours: 24, messengerScopes: ['pages_messaging'], instagramScopes: ['instagram_manage_messages'], webhookUrl: 'https://oyosend.test/social/webhooks/facebook' },
        pages: [{ id: 'a1', name: 'Acme Bakery', status: 'connected', enabled: true, state: 'on', instagramState: 'not_linked', missingScopes: [], lastError: null, lastEventAt: null }],
        conversations: { data: [row()], prev_page_url: null, next_page_url: null, from: 1, to: 1, total: 1 },
        filters: { platform: null, status: 'open', q: null },
        thread: null,
        members: [{ id: 'mem1', name: 'Nadia Karim' }],
        can: { reply: true, manage: true },
        ...overrides,
    };
}

beforeEach(() => {
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('social messages page', () => {
    it('says plainly when the operator has not switched direct messages on', () => {
        const wrapper = track(mount(MessagesIndex, { props: props({ availability: { ...(props().availability as object), problem: 'not_enabled', platforms: [] }, conversations: { data: [], prev_page_url: null, next_page_url: null, from: null, to: null, total: 0 } }) as never }));

        expect(wrapper.find('[data-testid="messages-off"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Direct messages are not switched on');
        expect(wrapper.text()).not.toContain('Switch on');
    });

    it('shows message text as text, opens a conversation and posts that it was read', async () => {
        const wrapper = track(mount(MessagesIndex, { props: props() as never }));

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-testid="conversation-c1"]').text()).toContain('<img src=x onerror=alert(1)>');

        await wrapper.get('[data-testid="conversation-c1"]').trigger('click');
        expect(inertia.router.get).toHaveBeenLastCalledWith('/social.messages.index', expect.objectContaining({ conversation: 'c1' }), expect.anything());

        track(mount(MessagesIndex, { props: props({ thread: thread() }) as never }));
        expect(inertia.router.post).toHaveBeenCalledWith('/social.messages.read?conversation=c1', {}, expect.anything());
    });

    it('renders the thread with attachments as labels and sends a reply', async () => {
        const wrapper = track(mount(MessagesIndex, { props: props({ thread: thread() }) as never }));
        const panel = wrapper.get('[data-testid="message-thread"]');

        expect(panel.find('img').exists()).toBe(false);
        expect(panel.get('[data-testid="message-m2"]').text()).toContain('Attachment: Image');
        expect(panel.get('[data-testid="message-m3"]').text()).toContain('Delivered');

        await panel.get('textarea').setValue('Yes, we deliver to Sylhet.');
        await panel.get('form').trigger('submit');

        expect(inertia.submissions).toEqual([{ method: 'post', url: '/social.messages.reply?conversation=c1', data: { text: 'Yes, we deliver to Sylhet.' } }]);
    });

    it('disables the composer and says why outside the 24-hour window', () => {
        const reason = 'The 24-hour reply window has closed. The person must write again before the Page can answer.';
        const wrapper = track(mount(MessagesIndex, { props: props({ thread: thread({ canReply: false, blockedReason: reason, windowEndsAt: null }) }) as never }));

        expect(wrapper.get('[data-testid="reply-blocked"]').text()).toBe(reason);
        expect(wrapper.get('textarea').attributes('disabled')).toBeDefined();
    });

    it('hides the composer and the capture switch from a member who may only read', () => {
        const wrapper = track(mount(MessagesIndex, { props: props({ thread: thread({ canReply: false, replyable: false }), can: { reply: false, manage: false }, members: [] }) as never }));

        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.text()).toContain('You can read this conversation but not answer it.');
        expect(wrapper.text()).not.toContain('Switch off');
    });

    it('shows each Page\'s capture state and the Instagram reason', () => {
        const wrapper = track(mount(MessagesIndex, { props: props() as never }));

        expect(wrapper.get('[data-testid="capture-a1"]').text()).toContain('Instagram: no professional account is linked to this Page');
        expect(wrapper.get('[data-testid="capture-a1"]').text()).toContain('Switch off');
    });
});
