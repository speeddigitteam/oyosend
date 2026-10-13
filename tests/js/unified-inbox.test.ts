import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: ['communication.inbox.view'] }, tenant: { name: 'Acme' } } },
    router: { get: vi.fn() },
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
import InboxIndex, { type InboxRow } from '@/Modules/Communication/Pages/Inbox/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const row = (overrides: Partial<InboxRow> = {}): InboxRow => ({
    key: 'whatsapp_marketing.conversations:c1', source: 'whatsapp_marketing.conversations', sourceLabel: 'WhatsApp', channel: 'whatsapp',
    title: 'Rahim Uddin', preview: 'Is it in stock?', at: '2026-10-13T08:00:00Z', state: 'unassigned', owner: null, unread: 2,
    url: '/whatsapp/inbox/c1', contactId: 'contact-1', ...overrides,
});
const sources = [{ key: 'whatsapp_marketing.conversations', label: 'WhatsApp', channel: 'whatsapp' }, { key: 'support.tickets', label: 'Support tickets', channel: 'support' }];
const filters = { view: 'list' as const, source: null, owner: null, state: 'open' as const, search: '' };
const counts = { unassigned: 1, active: 1, closed: 0 };

beforeEach(() => { setLocale('en'); inertia.router.get.mockClear(); });
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()));

describe('Unified inbox', () => {
    it('lists conversations of every source and appends the next page when asked', async () => {
        const wrapper = track(mount(InboxIndex, { props: {
            sources, filters, counts, columns: null,
            list: { items: [row(), row({ key: 'support.tickets:t1', source: 'support.tickets', sourceLabel: 'Support tickets', title: 'Nadia Karim', preview: 'TKT-1 · Refund', state: 'active', owner: 'Owner', unread: 0, url: '/support/tickets/t1' })], next: 'pos-1', after: null },
        } }));

        expect(wrapper.get('a[href="/whatsapp/inbox/c1"]').text()).toContain('Is it in stock?');
        expect(wrapper.get('a[href="/whatsapp/inbox/c1"]').text()).toContain('2 unread');
        expect(wrapper.get('a[href="/support/tickets/t1"]').text()).toContain('Assigned to Owner');
        expect(wrapper.get('a[href="/support/tickets/t1"]').text()).toContain('In progress');

        await wrapper.findAll('button').find((button) => button.text() === 'Load more')!.trigger('click');
        const [url, params, options] = inertia.router.get.mock.calls[0]!;
        expect(url).toBe('/communication.inbox.index');
        expect(params).toMatchObject({ after: 'pos-1' });
        expect(options).toMatchObject({ only: ['list'], preserveState: true });

        await wrapper.setProps({ list: { items: [row({ key: 'whatsapp_marketing.conversations:c9', title: 'Older chat', url: '/whatsapp/inbox/c9' })], next: null, after: 'pos-1' } });
        await nextTick();

        expect(wrapper.findAll('li')).toHaveLength(3);
        expect(wrapper.text()).toContain('Older chat');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Load more')).toBe(false);
    });

    it('shows the board as three columns with their totals', () => {
        const wrapper = track(mount(InboxIndex, { props: {
            sources, counts: { unassigned: 3, active: 0, closed: 1 }, list: null, filters: { ...filters, view: 'board' as const },
            columns: [
                { state: 'unassigned' as const, total: 3, items: [row()] },
                { state: 'active' as const, total: 0, items: [] },
                { state: 'closed' as const, total: 1, items: [row({ key: 'support.tickets:t2', state: 'closed', url: '/support/tickets/t2' })] },
            ],
        } }));

        expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Unassigned', 'In progress', 'Closed']);
        expect(wrapper.text()).toContain('1 of 3');
        expect(wrapper.text()).toContain('Nothing here');
        expect(wrapper.find('select[aria-invalid]').exists()).toBe(false);
    });

    it('explains when no inbox source is available to the member', () => {
        const wrapper = track(mount(InboxIndex, { props: { sources: [], filters, counts: { unassigned: 0, active: 0, closed: 0 }, list: { items: [], next: null, after: null }, columns: null } }));

        expect(wrapper.text()).toContain('No inbox is available to you');
        expect(wrapper.find('form').exists()).toBe(false);
    });
});
