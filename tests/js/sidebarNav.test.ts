import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    router: { on: () => () => {}, visit: vi.fn(), post: vi.fn() },
}));

import SidebarNav, { type ShellNavSection } from '@/Core/Components/Shell/SidebarNav.vue';
import { useNavGroups } from '@/Core/composables/useNavGroups';

const crm = { key: 'crm', label: 'CRM & Sales' };
const projects = { key: 'projects', label: 'Project Management' };

function item(key: string, group?: { key: string; label: string }, current = false) {
    return { key, label: key, href: `/${key}`, icon: 'target', current, group };
}

function sections(currentKey?: string): ShellNavSection[] {
    return [
        { key: 'workspace', label: 'Workspace', items: [item('dashboard')] },
        {
            key: 'apps',
            label: 'Apps',
            items: [
                item('appStore'),
                item('leads', crm, currentKey === 'leads'),
                item('deals', crm, currentKey === 'deals'),
                item('projects', projects, currentKey === 'projects'),
            ],
        },
    ];
}

beforeEach(() => {
    window.localStorage.clear();
    useNavGroups().reload();
});

describe('SidebarNav groups', () => {
    it('keeps core entries flat and puts each module under one header', () => {
        const wrapper = mount(SidebarNav, { props: { sections: sections() } });
        const headers = wrapper.findAll('button[data-nav-group]');

        expect(headers.map((header) => header.text())).toEqual(['CRM & Sales', 'Project Management']);
        expect(headers.every((header) => header.attributes('aria-expanded') === 'true')).toBe(true);
        expect(wrapper.findAll('a')).toHaveLength(5);
        // Not inside any group list.
        expect(wrapper.find('a[href="/appStore"]').element.closest('ul[id]')).toBeNull();
        expect(wrapper.find('a[href="/leads"]').element.closest('ul[id]')).not.toBeNull();
    });

    it('collapses a group, points aria-controls at its list and remembers the choice', async () => {
        const wrapper = mount(SidebarNav, { props: { sections: sections() }, attachTo: document.body });
        const header = wrapper.findAll('button[data-nav-group]')[0]!;
        const list = document.getElementById(header.attributes('aria-controls')!)!;

        expect(list).not.toBeNull();
        expect(list.style.display).not.toBe('none');

        await header.trigger('click');

        expect(header.attributes('aria-expanded')).toBe('false');
        expect(list.style.display).toBe('none');
        expect(JSON.parse(window.localStorage.getItem('oyosend.nav.collapsed') ?? '[]')).toEqual(['apps:crm']);

        // A new sidebar (next page, or the mobile drawer) starts the way it was left.
        useNavGroups().reload();
        const again = mount(SidebarNav, { props: { sections: sections() } });

        expect(again.findAll('button[data-nav-group]')[0]!.attributes('aria-expanded')).toBe('false');
        expect(again.findAll('button[data-nav-group]')[1]!.attributes('aria-expanded')).toBe('true');

        wrapper.unmount();
    });

    it('opens the group that holds the current page but still lets it be collapsed', async () => {
        const wrapper = mount(SidebarNav, { props: { sections: sections('deals') } });
        const header = wrapper.findAll('button[data-nav-group]')[0]!; // CRM holds the current page.

        // Open by default while it holds the current page, with no active marker on the header.
        expect(header.attributes('aria-expanded')).toBe('true');
        expect(header.find('span.bg-accent').exists()).toBe(false);

        await header.trigger('click');

        // It closes like any other group and remembers the choice.
        expect(header.attributes('aria-expanded')).toBe('false');
        expect(JSON.parse(window.localStorage.getItem('oyosend.nav.collapsed') ?? '[]')).toEqual(['apps:crm']);
        // A marker now shows the active page is tucked inside the collapsed group.
        expect(header.find('span.bg-accent').exists()).toBe(true);
    });

    it('keeps a collapsed group collapsed even after navigating into it', () => {
        window.localStorage.setItem('oyosend.nav.collapsed', JSON.stringify(['apps:crm']));
        useNavGroups().reload();

        const wrapper = mount(SidebarNav, { props: { sections: sections('deals') } });
        const header = wrapper.findAll('button[data-nav-group]')[0]!;

        expect(header.attributes('aria-expanded')).toBe('false');
        expect(header.find('span.bg-accent').exists()).toBe(true);
    });

    it('still groups a single module and survives unreadable storage', () => {
        window.localStorage.setItem('oyosend.nav.collapsed', '{not json');
        useNavGroups().reload();

        const only: ShellNavSection[] = [{ key: 'apps', label: 'Apps', items: [item('leads', crm)] }];
        const wrapper = mount(SidebarNav, { props: { sections: only } });

        expect(wrapper.findAll('button[data-nav-group]')).toHaveLength(1);
        expect(wrapper.find('button[data-nav-group]').attributes('aria-expanded')).toBe('true');
    });
});
