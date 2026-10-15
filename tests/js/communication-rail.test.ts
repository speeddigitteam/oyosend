import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: [] as string[] } } },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    usePage: () => inertia.page,
}));

vi.mock('@/Core/lib/route', () => ({ route: (name: string) => `/${name}`, isCurrentRoute: () => false }));

import { setLocale } from '@/Core/i18n';
import CommunicationNav from '@/Modules/Communication/Components/CommunicationNav.vue';

const ALL = [
    'communication.inbox.view',
    'communication.notifications.view',
    'communication.messages.view',
    'communication.templates.view',
    'communication.audience.view',
    'communication.settings.manage',
];

describe('communication rail', () => {
    beforeEach(() => {
        setLocale('en');
        inertia.page.props.auth.permissions = [...ALL];
    });

    it('shows every permitted section as an icon link, marks the current one and pins Settings last', () => {
        const wrapper = mount(CommunicationNav, { props: { current: 'inbox', variant: 'rail' } });
        const links = wrapper.find('[data-testid="communication-rail"]').findAll('a');

        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/communication.inbox.index',
            '/communication.notifications.index',
            '/communication.messages.index',
            '/communication.templates.index',
            '/communication.audience.index',
            '/communication.settings.index',
        ]);
        expect(links[0].attributes('aria-current')).toBe('page');
        expect(links.slice(1).every((link) => link.attributes('aria-current') === undefined)).toBe(true);
        // Icon-only, but every link still has a name for screen readers and a hover title.
        expect(links.every((link) => link.find('.sr-only').text() !== '' && link.attributes('title') !== undefined)).toBe(true);
    });

    it('leaves out what the member may not open, Settings included', () => {
        inertia.page.props.auth.permissions = ['communication.inbox.view', 'communication.messages.view'];
        const wrapper = mount(CommunicationNav, { props: { current: 'messages', variant: 'rail' } });
        const hrefs = wrapper.findAll('a').map((link) => link.attributes('href'));

        expect(hrefs).toEqual(['/communication.inbox.index', '/communication.messages.index']);
        expect(wrapper.find('a[aria-current="page"]').attributes('href')).toBe('/communication.messages.index');
    });

    it('still renders the tab row by default', () => {
        const wrapper = mount(CommunicationNav, { props: { current: 'inbox' } });

        expect(wrapper.find('[data-testid="communication-rail"]').exists()).toBe(false);
        expect(wrapper.findAll('a')).toHaveLength(ALL.length);
    });
});
