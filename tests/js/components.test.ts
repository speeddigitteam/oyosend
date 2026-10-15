import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, type Component } from 'vue';

// Inertia's Link needs a running router; a plain anchor is enough to test our components.
vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ name: 'InertiaHeadStub', setup: () => () => null }),
    usePage: () => ({
        props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' } },
        flash: {},
    }),
    router: { on: () => () => {}, visit: vi.fn(), post: vi.fn() },
}));

import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';

describe('Button', () => {
    it('is a real button that does not submit by default', () => {
        const wrapper = mount(Button, { slots: { default: 'Save' } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.text()).toBe('Save');
    });

    it('becomes a link when given an href', () => {
        const wrapper = mount(Button, { props: { href: '/team/members' }, slots: { default: 'Members' } });

        expect(wrapper.element.tagName).toBe('A');
        expect(wrapper.attributes('href')).toBe('/team/members');
    });

    it('cannot be activated while loading', async () => {
        const wrapper = mount(Button, { props: { loading: true }, slots: { default: 'Save' } });

        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('keeps an icon-only button labelled for screen readers', () => {
        const wrapper = mount(Button, { props: { icon: true }, slots: { default: 'Close' } });

        expect(wrapper.find('.sr-only').text()).toBe('Close');
    });

    it('uses the brand colour only for the primary variant', () => {
        expect(mount(Button, { props: { variant: 'primary' } }).classes()).toContain('bg-brand');
        expect(mount(Button).classes()).not.toContain('bg-brand');
        expect(mount(Button, { props: { variant: 'danger' } }).classes()).toContain('bg-danger');
    });
});

describe('Input', () => {
    it('ties the label, the error and the control together', () => {
        const wrapper = mount(Input, { props: { label: 'Email', error: 'Enter a valid email.', modelValue: '' } });
        const input = wrapper.get('input');
        const id = input.attributes('id');

        expect(wrapper.get('label').attributes('for')).toBe(id);
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe(`${id}-error`);
        expect(wrapper.get('[role="alert"]').text()).toBe('Enter a valid email.');
    });

    it('shows the hint only when there is no error', () => {
        const hinted = mount(Input, { props: { label: 'Name', hint: 'As it should appear to others.', modelValue: '' } });

        expect(hinted.text()).toContain('As it should appear to others.');
        expect(hinted.find('[role="alert"]').exists()).toBe(false);
    });

    it('updates its model and passes native attributes through', async () => {
        const wrapper = mount(Input, {
            props: { modelValue: '', 'onUpdate:modelValue': (value: unknown) => wrapper.setProps({ modelValue: value as string }) },
            attrs: { autocomplete: 'username', name: 'email' },
        });

        await wrapper.get('input').setValue('owner@acme.test');

        expect(wrapper.props('modelValue')).toBe('owner@acme.test');
        expect(wrapper.get('input').attributes('autocomplete')).toBe('username');
        expect(wrapper.get('input').attributes('name')).toBe('email');
    });
});

describe('form fields', () => {
    // A class on a field places it in its layout (a grid span, a width, flex growth), so it lands on the wrapper that
    // also holds the label and the hint. Only controlClass styles the control itself.
    it.each([
        ['Input', Input, 'input', {}],
        ['Select', Select, 'select', { options: [{ value: 'a', label: 'A' }] }],
        ['Textarea', Textarea, 'textarea', {}],
    ] as [string, Component, string, Record<string, unknown>][])('%s puts class on its wrapper and controlClass on the control', (_name, field, tag, props) => {
        const wrapper = mount(field, {
            props: { ...props, label: 'Name', modelValue: '', controlClass: 'font-mono' },
            attrs: { class: 'sm:col-span-2', style: 'order: 2', name: 'field' },
        });
        const control = wrapper.get(tag);

        expect(wrapper.classes()).toContain('sm:col-span-2');
        expect(wrapper.attributes('style')).toContain('order: 2');
        expect(control.classes()).toContain('control');
        expect(control.classes()).toContain('font-mono');
        expect(control.classes()).not.toContain('sm:col-span-2');
        expect(control.attributes('style')).toBeUndefined();
        expect(control.attributes('name')).toBe('field');
    });
});

describe('Select', () => {
    it('renders its options and a placeholder', () => {
        const wrapper = mount(Select, {
            props: {
                modelValue: '',
                placeholder: 'Choose a role',
                options: [
                    { value: 'a', label: 'Admin' },
                    { value: 'b', label: 'Manager' },
                ],
            },
        });

        expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['Choose a role', 'Admin', 'Manager']);
        expect(wrapper.findAll('option')[0]!.attributes('disabled')).toBeDefined();
    });
});

describe('Checkbox', () => {
    it('toggles its model', async () => {
        const wrapper = mount(Checkbox, {
            props: { modelValue: false, label: 'Remember me', 'onUpdate:modelValue': (value: boolean) => wrapper.setProps({ modelValue: value }) },
        });

        await wrapper.get('input').setValue(true);

        expect(wrapper.props('modelValue')).toBe(true);
    });

    it('announces a partial selection', () => {
        const wrapper = mount(Checkbox, { props: { modelValue: false, indeterminate: true, label: 'All' } });

        expect(wrapper.get('input').attributes('aria-checked')).toBe('mixed');
    });
});

describe('Badge', () => {
    it('maps tones to token colours', () => {
        expect(mount(Badge, { props: { tone: 'ok' } }).classes()).toEqual(expect.arrayContaining(['bg-ok-soft', 'text-ok']));
        expect(mount(Badge, { props: { tone: 'danger' } }).classes()).toEqual(expect.arrayContaining(['bg-danger-soft', 'text-danger']));
    });
});

describe('EmptyState', () => {
    it('shows a title, a description and its actions', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No invitations', description: 'Invite someone to get started.' },
            slots: { default: '<button>Invite</button>' },
        });

        expect(wrapper.text()).toContain('No invitations');
        expect(wrapper.text()).toContain('Invite someone to get started.');
        expect(wrapper.find('button').exists()).toBe(true);
    });
});

describe('DataTable', () => {
    const columns = [
        { key: 'name', label: 'Name', sortable: true },
        { key: 'members', label: 'Members', sortable: true, align: 'right' as const },
        { key: 'locale', label: 'Language', hideable: true },
    ];

    const rows = [
        { id: '1', name: 'Globex', members: 12, locale: 'bn' },
        { id: '2', name: 'acme', members: 3, locale: 'en' },
        { id: '3', name: 'Initech', members: 25, locale: 'en' },
    ];

    // The table is generic over its row type, which `mount` cannot infer; the
    // props are checked where the table is used, by vue-tsc.
    function mountTable(props: Record<string, unknown> = {}, slots: Record<string, string> = {}) {
        return mount(DataTable as Component, { props: { columns, rows, rowKey: 'id', caption: 'Workspaces', ...props }, slots });
    }

    function firstColumn(wrapper: ReturnType<typeof mountTable>): string[] {
        return wrapper.findAll('tbody tr').map((row) => row.findAll('td')[0]!.text());
    }

    it('renders a captioned table with a row per record', () => {
        const wrapper = mountTable();

        expect(wrapper.get('caption').text()).toBe('Workspaces');
        expect(wrapper.findAll('tbody tr')).toHaveLength(3);
        expect(wrapper.findAll('th').map((cell) => cell.text())).toEqual(['Name', 'Members', 'Language']);
    });

    it('sorts text case-insensitively and numbers numerically', async () => {
        const wrapper = mountTable();
        const [name, members] = wrapper.findAll('th button');

        await name!.trigger('click');
        expect(firstColumn(wrapper)).toEqual(['acme', 'Globex', 'Initech']);
        expect(wrapper.findAll('th')[0]!.attributes('aria-sort')).toBe('ascending');

        await name!.trigger('click');
        expect(firstColumn(wrapper)).toEqual(['Initech', 'Globex', 'acme']);

        await members!.trigger('click');
        expect(firstColumn(wrapper)).toEqual(['acme', 'Globex', 'Initech']);

        // A third click on the same column returns to the server's order.
        await members!.trigger('click');
        await members!.trigger('click');
        expect(firstColumn(wrapper)).toEqual(['Globex', 'acme', 'Initech']);
    });

    it('leaves ordering to the server in server mode', async () => {
        const wrapper = mountTable({ sortMode: 'server' });

        await wrapper.find('th button').trigger('click');

        expect(firstColumn(wrapper)).toEqual(['Globex', 'acme', 'Initech']);
        expect(wrapper.emitted('update:sort')?.[0]).toEqual([{ key: 'name', direction: 'asc' }]);
    });

    it('renders custom cells and the empty slot', () => {
        const filled = mountTable({}, { 'cell-name': '<template #cell-name="{ row }"><strong>{{ row.name }}</strong></template>' });

        expect(filled.findAll('tbody strong')).toHaveLength(3);

        const empty = mountTable({ rows: [] }, { empty: '<p class="empty">Nothing yet</p>' });

        expect(empty.get('.empty').text()).toBe('Nothing yet');
    });

    it('shows placeholders instead of rows while loading', () => {
        const wrapper = mountTable({ loading: true });

        expect(wrapper.text()).not.toContain('Globex');
        expect(wrapper.findAll('tbody tr')).toHaveLength(5);
    });

    it('hides columns marked hidden by default', () => {
        const wrapper = mountTable({
            columns: [...columns.slice(0, 2), { key: 'locale', label: 'Language', hideable: true, hiddenByDefault: true }],
        });

        expect(wrapper.findAll('th').map((cell) => cell.text())).toEqual(['Name', 'Members']);
    });
});
