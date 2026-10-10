import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, type Component } from 'vue';

/*
 * Branches and departments, placing members in them, and permission scopes in
 * the role editor - mounted with the real catalogue and components. Inertia
 * and the route table are stood in for: visits and submitted forms are
 * recorded instead of sent.
 */
type FormStub = Record<string, unknown> & { errors: Record<string, string> };

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { membership: { id: 'member-owner', isOwner: true }, permissions: [] as string[] },
            tenant: { id: 'tenant-1', name: 'Acme', slug: 'acme', logoUrl: null },
        },
        flash: {},
    },
    visits: [] as Array<{ method: string; url: string; data?: unknown }>,
    sent: [] as Array<{ method: string; url: string; data: Record<string, unknown> }>,
    forms: [] as Array<Record<string, unknown>>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ name: 'InertiaHeadStub', setup: () => () => null }),
    usePage: () => inertia.page,
    router: {
        on: () => () => {},
        get: (url: string, data: unknown) => inertia.visits.push({ method: 'get', url, data }),
        patch: (url: string, data: unknown) => inertia.visits.push({ method: 'patch', url, data }),
        delete: (url: string, options?: { onSuccess?: () => void; onFinish?: () => void }) => {
            inertia.visits.push({ method: 'delete', url });
            options?.onSuccess?.();
            options?.onFinish?.();
        },
    },
    // Enough of a form to see what would be sent, after `transform`.
    useForm: (initial: Record<string, unknown>) => {
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;

        const send = (method: string, url: string, options?: { onSuccess?: () => void }): void => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, (form as Record<string, unknown>)[key]]));

            inertia.sent.push({ method, url, data: JSON.parse(JSON.stringify(transform(data))) as Record<string, unknown> });
            options?.onSuccess?.();
        };

        const form: Record<string, unknown> = reactive({
            ...(JSON.parse(JSON.stringify(initial)) as Record<string, unknown>),
            errors: {},
            processing: false,
            clearErrors: () => {},
            transform: (callback: typeof transform) => {
                transform = callback;

                return form;
            },
            post: (url: string, options?: { onSuccess?: () => void }) => send('post', url, options),
            put: (url: string, options?: { onSuccess?: () => void }) => send('put', url, options),
            patch: (url: string, options?: { onSuccess?: () => void }) => send('patch', url, options),
        });

        inertia.forms.push(form);

        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();

        return query === '' ? `/${name}` : `/${name}?${query}`;
    },
    isCurrentRoute: () => false,
}));

import type { BranchRow, DepartmentRow } from '@/Core/Components/Organization/types';
import PermissionGroup from '@/Core/Components/Team/PermissionGroup.vue';
import PlacementDialog from '@/Core/Components/Team/PlacementDialog.vue';
import { isChangeable, savedScopes, scopesUpTo } from '@/Core/Components/Team/scopes';
import type { CatalogueGroup, CatalogueModule, CataloguePermission, EditableRole, Member } from '@/Core/Components/Team/types';
import Organization from '@/Core/Pages/Organization/Index.vue';
import Members from '@/Core/Pages/Team/Members.vue';
import RoleEditor from '@/Core/Pages/Team/Roles/Edit.vue';

const mounted: VueWrapper[] = [];

function track<T extends VueWrapper>(wrapper: T): T {
    mounted.push(wrapper);

    return wrapper;
}

function allow(...permissions: string[]): void {
    inertia.page.props.auth.permissions = permissions;
}

/** A field of the dialog that is open; dialogs render outside the component, in the body. */
function field<T extends HTMLElement = HTMLInputElement>(name: string): T {
    const element = document.body.querySelector<T>(`[role="dialog"] [name="${name}"]`);

    if (!element) {
        throw new Error(`The open dialog has no field named [${name}].`);
    }

    return element;
}

function dialogText(): string {
    return document.body.querySelector('[role="dialog"]')?.textContent ?? '';
}

function dialogButton(label: string): HTMLButtonElement {
    const button = [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find(
        (candidate) => candidate.textContent?.trim() === label,
    );

    if (!button) {
        throw new Error(`The open dialog has no button labelled [${label}].`);
    }

    return button;
}

async function type(name: string, value: string): Promise<void> {
    const element = field(name);

    element.value = value;
    element.dispatchEvent(new Event(element.tagName === 'SELECT' ? 'change' : 'input'));

    await flushPromises();
}

async function submitDialog(): Promise<void> {
    document.body.querySelector('[role="dialog"] form')?.dispatchEvent(new Event('submit', { cancelable: true }));

    await flushPromises();
}

function buttonNamed(wrapper: VueWrapper, label: string): DOMWrapper<HTMLButtonElement> {
    const button = wrapper.findAll<HTMLButtonElement>('button').find((candidate) => candidate.text() === label);

    if (!button) {
        throw new Error(`No button labelled [${label}].`);
    }

    return button;
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.sent.length = 0;
    inertia.forms.length = 0;
    allow();
    window.localStorage.clear();
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

/*
 * Permission scopes
 */
function permission(name: string, overrides: Partial<CataloguePermission> = {}): CataloguePermission {
    return { name, sensitive: false, grantable: true, scopable: false, maxScope: 'all', ...overrides };
}

// The editor holds contacts for their department, may manage every contact, and may not delete any.
const view = permission('core.contacts.view', { scopable: true, maxScope: 'department' });
const manage = permission('core.contacts.manage', { scopable: true });
const remove = permission('core.contacts.delete', { scopable: true, sensitive: true, grantable: false, maxScope: null });
const members = permission('core.members.view');

const contacts: CatalogueGroup = { key: 'contacts', permissions: [view, manage, remove] };

const catalogue: CatalogueModule[] = [
    { module: 'core', name: 'Workspace', groups: [contacts, { key: 'members', permissions: [members] }] },
];

function checkbox(wrapper: VueWrapper, label: string): DOMWrapper<HTMLInputElement> {
    const found = wrapper.findAll('label').find((candidate) => candidate.text().startsWith(label));

    if (!found) {
        throw new Error(`No checkbox labelled [${label}].`);
    }

    return found.find<HTMLInputElement>('input[type="checkbox"]');
}

/** The list item of a permission: its checkbox and, when it is ticked and can be narrowed, its scope. */
function item(wrapper: VueWrapper, label: string): Element {
    const element = checkbox(wrapper, label).element.closest('li');

    if (!element) {
        throw new Error(`[${label}] is not a permission.`);
    }

    return element;
}

function scopeSelect(wrapper: VueWrapper, label: string): HTMLSelectElement | null {
    return item(wrapper, label).querySelector('select');
}

async function chooseScope(wrapper: VueWrapper, label: string, scope: string): Promise<void> {
    const select = scopeSelect(wrapper, label);

    if (!select) {
        throw new Error(`[${label}] offers no choice of scope.`);
    }

    select.value = scope;
    select.dispatchEvent(new Event('change'));

    await flushPromises();
}

describe('scope rules', () => {
    it('offers a scope and everything narrower, from the narrowest up', () => {
        expect(scopesUpTo('own')).toEqual(['own']);
        expect(scopesUpTo('branch')).toEqual(['own', 'department', 'branch']);
        expect(scopesUpTo('all')).toEqual(['own', 'department', 'branch', 'all']);
        expect(scopesUpTo(null)).toEqual([]);
    });

    it('lets the editor touch a grant only when they hold the permission at least as widely', () => {
        // Not granted yet: holding it is enough.
        expect(isChangeable(view, undefined)).toBe(true);
        expect(isChangeable(view, 'own')).toBe(true);
        expect(isChangeable(view, 'department')).toBe(true);
        expect(isChangeable(view, 'branch')).toBe(false);
        expect(isChangeable(view, 'all')).toBe(false);
        expect(isChangeable(manage, 'all')).toBe(true);
        expect(isChangeable(remove, undefined)).toBe(false);
        expect(isChangeable(remove, 'own')).toBe(false);
    });

    it('reads the scopes of a role that grants nothing as no scopes', () => {
        const base = { id: 'r', name: 'R', description: null, isSystem: false, isOwner: false, permissions: [] };

        expect(savedScopes(null)).toEqual({});
        // PHP has one empty array: an empty map arrives as an empty list.
        expect(savedScopes({ ...base, scopes: [] })).toEqual({});
        expect(savedScopes({ ...base, scopes: { 'core.contacts.view': 'own' } })).toEqual({ 'core.contacts.view': 'own' });
    });
});

describe('a permission group', () => {
    function mountGroup(props: Record<string, unknown> = {}): VueWrapper {
        return track(mount(PermissionGroup as Component, { props: { group: contacts, selected: new Set<string>(), ...props } }));
    }

    it('says nothing about scope for a permission that is not ticked', () => {
        const wrapper = mountGroup();

        expect(wrapper.findAll('select')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Scope');
    });

    it('offers a ticked permission the scopes up to the one the editor holds', () => {
        const wrapper = mountGroup({
            selected: new Set(['core.contacts.view', 'core.contacts.manage']),
            scopes: { 'core.contacts.view': 'own', 'core.contacts.manage': 'all' },
        });

        const narrow = scopeSelect(wrapper, 'View contacts')!;
        const wide = scopeSelect(wrapper, 'Add and edit contacts')!;

        expect([...narrow.options].map((option) => option.value)).toEqual(['own', 'department']);
        expect(narrow.value).toBe('own');
        expect(narrow.getAttribute('aria-label')).toBe('Scope of “View contacts”');
        // What the chosen scope means, and why "All" is not among the choices.
        expect(item(wrapper, 'View contacts').textContent).toContain('Only records the person owns.');
        expect(item(wrapper, 'View contacts').textContent).toContain('Your own scope for this is “Department”');

        expect([...wide.options].map((option) => option.textContent?.trim())).toEqual(['Own', 'Department', 'Branch', 'All']);
        expect(wide.value).toBe('all');
        expect(item(wrapper, 'Add and edit contacts').textContent).not.toContain('Your own scope');
    });

    it('reports the scope that is chosen', async () => {
        const wrapper = mountGroup({ selected: new Set(['core.contacts.manage']), scopes: { 'core.contacts.manage': 'all' } });

        await chooseScope(wrapper, 'Add and edit contacts', 'branch');

        expect(wrapper.emitted('scope')).toEqual([['core.contacts.manage', 'branch']]);
    });

    it('locks a permission the editor does not hold, and shows the scope it is granted with', () => {
        const wrapper = mountGroup({
            selected: new Set(['core.contacts.delete']),
            scopes: { 'core.contacts.delete': 'own' },
            saved: { 'core.contacts.delete': 'own' },
        });

        expect(checkbox(wrapper, 'Delete contacts').element.disabled).toBe(true);
        expect(scopeSelect(wrapper, 'Delete contacts')).toBeNull();
        expect(item(wrapper, 'Delete contacts').textContent).toContain('You do not hold this, so you cannot change it');
        expect(item(wrapper, 'Delete contacts').textContent).toContain('Scope: Own');
    });

    it('locks a grant that reaches further than the editor’s own, and leaves it out of "select all"', async () => {
        const wrapper = mountGroup({
            selected: new Set(['core.contacts.view']),
            scopes: { 'core.contacts.view': 'all' },
            saved: { 'core.contacts.view': 'all' },
        });

        expect(checkbox(wrapper, 'View contacts').element.disabled).toBe(true);
        expect(scopeSelect(wrapper, 'View contacts')).toBeNull();
        expect(item(wrapper, 'View contacts').textContent).toContain('This grant reaches further than yours');
        expect(item(wrapper, 'View contacts').textContent).toContain('Scope: All');

        await checkbox(wrapper, 'Contacts & companies').setValue(true);

        expect(wrapper.emitted('toggle')).toEqual([[['core.contacts.manage'], true]]);
    });

    it('shows a read-only role its scopes without anything to change', () => {
        const wrapper = mountGroup({ selected: new Set(['core.contacts.view', 'core.contacts.manage', 'core.contacts.delete']), readonly: true });

        expect(wrapper.findAll('input')).toHaveLength(0);
        expect(wrapper.findAll('select')).toHaveLength(0);
        expect(wrapper.text()).toContain('Scope: All');
    });
});

describe('the role editor', () => {
    function mountEditor(role: EditableRole | null = null): VueWrapper {
        return track(mount(RoleEditor as Component, { attachTo: document.body, props: { role, catalogue } }));
    }

    function saveButton(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement> {
        return wrapper.find<HTMLButtonElement>('button[type="submit"]');
    }

    const salesReps: EditableRole = {
        id: 'role-1',
        name: 'Sales Reps',
        description: null,
        isSystem: false,
        isOwner: false,
        permissions: ['core.contacts.delete', 'core.contacts.manage', 'core.contacts.view'],
        // View reaches further than the editor's department; delete is not theirs at all.
        scopes: { 'core.contacts.delete': 'own', 'core.contacts.manage': 'own', 'core.contacts.view': 'all' },
    };

    it('starts a newly ticked permission at the widest scope the editor may grant', async () => {
        const wrapper = mountEditor();

        await checkbox(wrapper, 'View contacts').setValue(true);
        await checkbox(wrapper, 'Add and edit contacts').setValue(true);
        await checkbox(wrapper, 'View members').setValue(true);

        expect(scopeSelect(wrapper, 'View contacts')!.value).toBe('department');
        expect(scopeSelect(wrapper, 'Add and edit contacts')!.value).toBe('all');
        // Nothing to choose for a permission that cannot be narrowed.
        expect(scopeSelect(wrapper, 'View members')).toBeNull();
    });

    it('sends the scope of each ticked permission along with the permissions', async () => {
        const wrapper = mountEditor();

        await wrapper.get('input[name="name"]').setValue('Helpers');
        await checkbox(wrapper, 'View contacts').setValue(true);
        await checkbox(wrapper, 'Add and edit contacts').setValue(true);
        await checkbox(wrapper, 'View members').setValue(true);
        await chooseScope(wrapper, 'Add and edit contacts', 'own');

        // Ticked and then unticked again: its scope does not travel.
        await checkbox(wrapper, 'View contacts').setValue(false);

        await wrapper.get('form').trigger('submit');

        expect(inertia.sent).toEqual([
            {
                method: 'post',
                url: '/core.roles.store',
                data: {
                    name: 'Helpers',
                    description: '',
                    permissions: ['core.contacts.manage', 'core.members.view'],
                    scopes: { 'core.contacts.manage': 'own' },
                },
            },
        ]);
    });

    it('sends the grants it may not touch back exactly as they were', async () => {
        const wrapper = mountEditor(salesReps);

        expect(checkbox(wrapper, 'View contacts').element.disabled).toBe(true);
        expect(checkbox(wrapper, 'Delete contacts').element.disabled).toBe(true);
        expect(wrapper.text()).toContain('2 permissions below are locked');

        await chooseScope(wrapper, 'Add and edit contacts', 'branch');
        await wrapper.get('form').trigger('submit');

        expect(inertia.sent).toHaveLength(1);
        expect(inertia.sent[0]).toMatchObject({ method: 'put', url: '/core.roles.update?role=role-1' });
        expect(inertia.sent[0]!.data.permissions).toEqual(['core.contacts.delete', 'core.contacts.manage', 'core.contacts.view']);
        expect(inertia.sent[0]!.data.scopes).toEqual({
            'core.contacts.delete': 'own',
            'core.contacts.manage': 'branch',
            'core.contacts.view': 'all',
        });
    });

    it('has nothing to save until something changes, and counts a changed scope as a change', async () => {
        const wrapper = mountEditor(salesReps);

        expect(saveButton(wrapper).element.disabled).toBe(true);

        await chooseScope(wrapper, 'Add and edit contacts', 'department');

        expect(saveButton(wrapper).element.disabled).toBe(false);
        expect(wrapper.get('[role="status"]').text()).toContain('1 with a new scope');

        // Back to what was saved: nothing to save again.
        await chooseScope(wrapper, 'Add and edit contacts', 'own');

        expect(saveButton(wrapper).element.disabled).toBe(true);
    });

    it('gives a permission that is ticked again the scope it was saved with', async () => {
        const wrapper = mountEditor(salesReps);

        await checkbox(wrapper, 'Add and edit contacts').setValue(false);

        expect(saveButton(wrapper).element.disabled).toBe(false);

        await checkbox(wrapper, 'Add and edit contacts').setValue(true);

        expect(scopeSelect(wrapper, 'Add and edit contacts')!.value).toBe('own');
        expect(saveButton(wrapper).element.disabled).toBe(true);
    });

    it('shows what the server says about the scopes', async () => {
        const wrapper = mountEditor(salesReps);

        (inertia.forms[0] as FormStub).errors.scopes = 'One of the selected scopes is not valid.';
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('One of the selected scopes is not valid.');
    });

    it('keeps the Owner role for reference only', () => {
        const wrapper = mountEditor({ ...salesReps, name: 'Owner', isSystem: true, isOwner: true, permissions: [], scopes: [] });

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
        expect(wrapper.findAll('select')).toHaveLength(0);
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Scope: All');
    });
});

/*
 * Placing members
 */
const dhaka = { id: 'branch-dhaka', name: 'Dhaka' };
const chattogram = { id: 'branch-ctg', name: 'Chattogram' };
const sales = { id: 'dept-sales', name: 'Sales' };

const owner: Member = {
    id: 'member-owner',
    name: 'Olivia Owner',
    email: 'olivia@acme.test',
    initials: 'OO',
    role: { id: 'role-owner', name: 'Owner' },
    branch: null,
    department: null,
    status: 'active',
    isOwner: true,
    isSelf: true,
    manageable: false,
    placeable: true,
    twoFactorEnabled: true,
    joinedAt: '2026-03-10T09:00:00+06:00',
    lastSeenAt: null,
};

const employee: Member = {
    ...owner,
    id: 'member-employee',
    name: 'Emon Employee',
    email: 'emon@acme.test',
    initials: 'EE',
    role: { id: 'role-employee', name: 'Employee' },
    branch: dhaka,
    department: sales,
    isOwner: false,
    isSelf: false,
    manageable: true,
};

describe('the placement dialog', () => {
    async function openFor(member: Member, props: Record<string, unknown> = {}): Promise<VueWrapper> {
        const wrapper = track(
            mount(PlacementDialog as Component, {
                attachTo: document.body,
                props: { open: false, member, branches: [chattogram, dhaka], departments: [sales], ...props },
            }),
        );

        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    it('starts from where the member sits, with "none" as a choice', async () => {
        await openFor(employee);

        expect(dialogText()).toContain('Branch and department for Emon Employee');
        expect([...field<HTMLSelectElement>('branch_id').options].map((option) => option.textContent?.trim())).toEqual([
            'No branch',
            'Chattogram',
            'Dhaka',
        ]);
        expect(field<HTMLSelectElement>('branch_id').value).toBe('branch-dhaka');
        expect(field<HTMLSelectElement>('department_id').value).toBe('dept-sales');
        // Nothing to save yet.
        expect(dialogButton('Save changes').disabled).toBe(true);
    });

    it('sends both ids, with null for "none"', async () => {
        await openFor(employee);

        await type('branch_id', '');

        expect(dialogButton('Save changes').disabled).toBe(false);

        await submitDialog();

        expect(inertia.sent).toEqual([
            {
                method: 'patch',
                url: '/core.members.update?membership=member-employee',
                data: { branch_id: null, department_id: 'dept-sales' },
            },
        ]);
    });

    it('shows only the pickers the workspace has something for', async () => {
        await openFor(owner, { departments: [] });

        expect(document.body.querySelector('[role="dialog"] [name="department_id"]')).toBeNull();

        await type('branch_id', 'branch-ctg');
        await submitDialog();

        expect(inertia.sent[0]!.data).toEqual({ branch_id: 'branch-ctg', department_id: null });
    });

    it('points someone who may add branches and departments to where that is done', async () => {
        await openFor(employee);

        expect(document.body.querySelector('[role="dialog"] a')).toBeNull();

        mounted.splice(0).forEach((wrapper) => wrapper.unmount());

        await openFor(employee, { organizationUrl: '/core.organization.index' });

        expect(document.body.querySelector('[role="dialog"] a')?.getAttribute('href')).toBe('/core.organization.index');
    });
});

describe('the members list', () => {
    function page(data: Member[]): Record<string, unknown> {
        return {
            data,
            total: data.length,
            current_page: 1,
            last_page: 1,
            per_page: 25,
            from: 1,
            to: data.length,
            prev_page_url: null,
            next_page_url: null,
        };
    }

    function mountMembers(props: Record<string, unknown> = {}): VueWrapper {
        return track(
            mount(Members as Component, {
                attachTo: document.body,
                props: {
                    members: page([owner, employee]),
                    invitations: [],
                    filters: { search: '' },
                    assignableRoles: [],
                    branches: [chattogram, dhaka],
                    departments: [sales],
                    ...props,
                },
            }),
        );
    }

    function headings(wrapper: VueWrapper): string[] {
        return wrapper.findAll('thead th').map((heading) => heading.text());
    }

    it('keeps branch and department out of the table on a narrow screen until they are asked for', () => {
        allow('core.members.view');

        expect(headings(mountMembers())).not.toContain('Branch');
    });

    it('shows where each member sits once the columns are on', () => {
        allow('core.members.view');
        window.localStorage.setItem('oyo:columns:members', '[]');

        const wrapper = mountMembers();
        const rows = wrapper.findAll('tbody tr');

        expect(headings(wrapper)).toEqual(expect.arrayContaining(['Branch', 'Department']));
        expect(rows[1]!.text()).toContain('Dhaka');
        expect(rows[1]!.text()).toContain('Sales');
        expect(rows[0]!.text()).toContain('No branch');
    });

    it('has no column for something the workspace does not have', () => {
        allow('core.members.view');
        window.localStorage.setItem('oyo:columns:members', '[]');

        expect(headings(mountMembers({ departments: [] }))).toContain('Branch');
        expect(headings(mountMembers({ departments: [] }))).not.toContain('Department');
        expect(headings(mountMembers({ branches: [], departments: [] }))).not.toContain('Branch');
    });

    it('gives the owner a menu on their own row only when there is somewhere to sit', () => {
        allow('core.members.view', 'core.members.manage');

        const placed = mountMembers();

        expect(placed.find('[data-member-actions="member-owner"]').exists()).toBe(true);
        expect(placed.find('[data-member-actions="member-employee"]').exists()).toBe(true);

        const bare = mountMembers({ branches: [], departments: [] });

        expect(bare.find('[data-member-actions="member-owner"]').exists()).toBe(false);
        expect(bare.find('[data-member-actions="member-employee"]').exists()).toBe(true);
    });

    it('offers no menu to someone who only views the list', () => {
        allow('core.members.view');

        const wrapper = mountMembers({
            members: page([
                { ...owner, placeable: false },
                { ...employee, manageable: false, placeable: false },
            ]),
        });

        expect(wrapper.find('[data-member-actions]').exists()).toBe(false);
    });

    it('links to the organization page instead, for someone who may set it up', () => {
        allow('core.members.view', 'core.members.manage', 'core.organization.view', 'core.organization.manage');

        const bare = mountMembers({ branches: [], departments: [] });

        expect(bare.text()).toContain('This workspace has no branches or departments yet.');
        expect(bare.get('a[href="/core.organization.index"]').text()).toBe('Set up branches and departments');

        // Once there is something to place people in, the note is gone.
        expect(mountMembers().find('a[href="/core.organization.index"]').exists()).toBe(false);
    });

    it('does not point to a page the viewer cannot change', () => {
        allow('core.members.view', 'core.members.manage', 'core.organization.view');

        const wrapper = mountMembers({ branches: [], departments: [] });

        expect(wrapper.find('a[href="/core.organization.index"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('no branches or departments yet');
    });
});

/*
 * The organization page
 */
const dhakaBranch: BranchRow = {
    id: 'branch-dhaka',
    name: 'Dhaka',
    code: 'DHK',
    phone: '02-5550100',
    address: 'House 12, Road 5, Dhanmondi',
    members: 2,
};

const emptyBranch: BranchRow = { id: 'branch-ctg', name: 'Chattogram', code: null, phone: null, address: null, members: 0 };

const salesDepartment: DepartmentRow = { id: 'dept-sales', name: 'Sales', code: 'SAL', description: 'Finds and keeps customers.', members: 1 };

describe('the organization page', () => {
    function mountOrganization(props: Record<string, unknown> = {}): VueWrapper {
        return track(
            mount(Organization as Component, {
                attachTo: document.body,
                props: { branches: [emptyBranch, dhakaBranch], departments: [salesDepartment], ...props },
            }),
        );
    }

    function cards(wrapper: VueWrapper): { branches: DOMWrapper<Element>; departments: DOMWrapper<Element> } {
        const [branches, departments] = wrapper.findAll('section');

        return { branches: branches!, departments: departments! };
    }

    it('lists branches and departments with what is known about them and who sits there', () => {
        allow('core.organization.view');

        const { branches, departments } = cards(mountOrganization());
        const rows = branches.findAll('[data-unit]');

        expect(branches.get('h2').text()).toBe('Branches');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.text()).toContain('Chattogram');
        expect(rows[0]!.text()).toContain('No one yet');
        expect(rows[1]!.text()).toContain('Dhaka');
        expect(rows[1]!.text()).toContain('DHK');
        expect(rows[1]!.text()).toContain('02-5550100');
        expect(rows[1]!.text()).toContain('House 12, Road 5, Dhanmondi');
        expect(rows[1]!.text()).toContain('2 people');

        expect(departments.get('h2').text()).toBe('Departments');
        expect(departments.text()).toContain('Finds and keeps customers.');
        expect(departments.text()).toContain('1 person');
    });

    it('shows someone who may only view it no way to change it, and says so', () => {
        allow('core.organization.view');

        const wrapper = mountOrganization();

        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(wrapper.text()).toContain('You can see the branches and departments here, but not change them.');
    });

    it('offers adding, editing and deleting to someone who may manage it', () => {
        allow('core.organization.view', 'core.organization.manage');

        const wrapper = mountOrganization();
        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels).toEqual(expect.arrayContaining(['Add branch', 'Edit Dhaka', 'Delete Dhaka', 'Add department', 'Edit Sales', 'Delete Sales']));
        expect(wrapper.text()).not.toContain('but not change them');
    });

    it('says what belongs in an empty list, and offers the first step to someone who may take it', () => {
        allow('core.organization.view', 'core.organization.manage');

        const manager = mountOrganization({ branches: [], departments: [] });

        expect(manager.text()).toContain('No branches yet');
        expect(manager.text()).toContain('No departments yet');
        expect(manager.findAll('button').map((button) => button.text())).toEqual(['Add branch', 'Add department']);

        allow('core.organization.view');

        const viewer = mountOrganization({ branches: [], departments: [] });

        expect(viewer.text()).toContain('No branch has been added to this workspace.');
        expect(viewer.findAll('button')).toHaveLength(0);
    });

    it('tells someone who manages members where people are placed', () => {
        allow('core.organization.view', 'core.organization.manage', 'core.members.view', 'core.members.manage');

        expect(mountOrganization().get('a[href="/core.members.index"]').text()).toBe('Go to members');

        // Nobody to place anywhere yet.
        expect(mountOrganization({ branches: [], departments: [] }).find('a[href="/core.members.index"]').exists()).toBe(false);

        allow('core.organization.view', 'core.organization.manage');

        expect(mountOrganization().find('a[href="/core.members.index"]').exists()).toBe(false);
    });

    it('adds a branch', async () => {
        allow('core.organization.view', 'core.organization.manage');

        const wrapper = mountOrganization();

        await buttonNamed(wrapper, 'Add branch').trigger('click');
        await flushPromises();

        expect(dialogText()).toContain('Add a branch');

        await type('name', 'Sylhet');
        await type('code', 'SYL');
        await submitDialog();

        expect(inertia.sent).toEqual([
            { method: 'post', url: '/core.branches.store', data: { name: 'Sylhet', code: 'SYL', phone: '', address: '' } },
        ]);
    });

    it('edits a department from what it holds now, with nothing to save until it changes', async () => {
        allow('core.organization.view', 'core.organization.manage');

        const wrapper = mountOrganization();

        await buttonNamed(wrapper, 'Edit Sales').trigger('click');
        await flushPromises();

        expect(dialogText()).toContain('Edit Sales');
        expect(field('name').value).toBe('Sales');
        expect(field('code').value).toBe('SAL');
        expect(field<HTMLTextAreaElement>('description').value).toBe('Finds and keeps customers.');
        expect(dialogButton('Save changes').disabled).toBe(true);

        await type('name', 'Sales & Marketing');

        expect(dialogButton('Save changes').disabled).toBe(false);

        await submitDialog();

        expect(inertia.sent).toEqual([
            {
                method: 'put',
                url: '/core.departments.update?department=dept-sales',
                data: { name: 'Sales & Marketing', code: 'SAL', description: 'Finds and keeps customers.' },
            },
        ]);
    });

    it('asks before deleting, and says plainly what happens to the people and records', async () => {
        allow('core.organization.view', 'core.organization.manage');

        const wrapper = mountOrganization();

        await buttonNamed(wrapper, 'Delete Dhaka').trigger('click');
        await flushPromises();

        expect(dialogText()).toContain('Delete Dhaka?');
        expect(dialogText()).toContain('2 people are in this branch.');
        expect(dialogText()).toContain('they simply no longer belong to a branch');
        expect(dialogText()).toContain('Anyone who reaches records through this branch loses that access.');
        expect(inertia.visits).toHaveLength(0);

        dialogButton('Delete branch').click();
        await flushPromises();

        expect(inertia.visits).toEqual([{ method: 'delete', url: '/core.branches.destroy?branch=branch-dhaka' }]);
    });

    it('does not warn about access when nobody sits in what is deleted', async () => {
        allow('core.organization.view', 'core.organization.manage');

        const wrapper = mountOrganization();

        await buttonNamed(wrapper, 'Delete Chattogram').trigger('click');
        await flushPromises();

        expect(dialogText()).toContain('No one is in this branch.');
        expect(dialogText()).not.toContain('loses that access');
    });
});
