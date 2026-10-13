import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    usePage: () => ({ props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' } } }),
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

import CustomerPanel, { type CustomerRecordSection } from '@/Core/Components/Contacts/CustomerPanel.vue';
import { setLocale } from '@/Core/i18n';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const jsonResponse = (body: unknown, ok = true): Response => ({ ok, json: async () => body }) as Response;
const sections: CustomerRecordSection[] = [
    {
        key: 'crm.deals', module: 'crm', label: 'Deals', total: 7, viewAllUrl: '/deals',
        items: [
            { id: 'deal-1', title: 'Bulk order', status: 'Open', tone: 'info', detail: 'Proposal · ৳25,000.00', at: '2026-10-12T08:00:00Z', url: '/deals/deal-1' },
            { id: 'deal-2', title: 'Spare parts', status: 'Won', tone: 'ok', detail: null, at: null, url: '/deals/deal-2' },
        ],
        actions: [{ key: 'create_deal', label: 'Create deal', url: '/crm/deals?create_for=contact-1' }],
    },
    {
        key: 'crm.activities', module: 'crm', label: 'Tasks and follow-ups', total: 1, viewAllUrl: null,
        items: [{ id: 'activity-1', title: 'Send price list', status: 'Overdue', tone: 'danger', detail: 'Follow-up', at: null, url: null }],
        actions: [],
    },
];

beforeEach(() => setLocale('en'));
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('CustomerPanel', () => {
    it('shows each app section with links back to the app, its actions and how much is hidden', async () => {
        const fetchMock = vi.fn(async (_input: RequestInfo | URL) => jsonResponse({ contact: { id: 'contact-1', name: 'Rahim Uddin' }, sections }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(CustomerPanel, { props: { contactId: 'contact-1', contactName: 'Rahim Uddin' } }));
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]![0])).toBe('/core.contacts.records?contact=contact-1');
        expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Deals 7', 'Tasks and follow-ups 1']);
        expect(wrapper.get('a[href="/deals/deal-1"]').text()).toContain('Bulk order');
        expect(wrapper.get('a[href="/crm/deals?create_for=contact-1"]').text()).toBe('Create deal');
        expect(wrapper.text()).toContain('2 of 7');
        expect(wrapper.get('a[href="/deals"]').text()).toContain('View all');
        expect(wrapper.text()).toContain('Overdue');
        expect(wrapper.find('a[href="null"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('What your apps hold about Rahim Uddin');
    });

    it('says when the overview could not be loaded and loads it again on request', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({}, false))
            .mockResolvedValueOnce(jsonResponse({ contact: { id: 'contact-1', name: 'Rahim Uddin' }, sections }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(CustomerPanel, { props: { contactId: 'contact-1' } }));
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('The customer overview could not be loaded.');
        await wrapper.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Bulk order');
    });

    it('says so when no app holds anything about the customer', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ contact: { id: 'contact-1', name: 'Rahim Uddin' }, sections: [] })));
        const wrapper = track(mount(CustomerPanel, { props: { contactId: 'contact-1' } }));
        await flushPromises();

        expect(wrapper.text()).toContain('No app has records about this customer yet.');
    });
});
