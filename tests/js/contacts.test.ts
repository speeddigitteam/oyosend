import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, type Component } from 'vue';

/*
 * The contact directory pages, mounted with the real catalogue and components.
 * Inertia and the route table are stood in for: visits and submitted forms are
 * recorded instead of sent.
 */
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { membership: { id: 'member-self', isOwner: false }, permissions: [] as string[] },
            tenant: { id: 'tenant-1', name: 'Acme', slug: 'acme', logoUrl: null },
        },
        flash: {},
    },
    visits: [] as Array<{ method: string; url: string; data?: unknown }>,
    sent: [] as Array<{ method: string; url: string; data: Record<string, unknown> }>,
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
        delete: (url: string) => inertia.visits.push({ method: 'delete', url }),
    },
    // Enough of a form to see what would be sent, after `transform`.
    useForm: (initial: Record<string, string>) => {
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;

        const send = (method: string, url: string): void => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, (form as Record<string, unknown>)[key]]));

            inertia.sent.push({ method, url, data: transform(data) });
        };

        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {},
            processing: false,
            clearErrors: () => {},
            transform: (callback: typeof transform) => {
                transform = callback;

                return form;
            },
            post: (url: string) => send('post', url),
            put: (url: string) => send('put', url),
        });

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

import CompanyFormDialog from '@/Core/Components/Contacts/CompanyFormDialog.vue';
import ContactFormDialog from '@/Core/Components/Contacts/ContactFormDialog.vue';
import { mailHref, telHref } from '@/Core/Components/Contacts/links';
import type { CompanyRow, ContactRow } from '@/Core/Components/Contacts/types';
import Companies from '@/Core/Pages/Contacts/Companies.vue';
import People from '@/Core/Pages/Contacts/Index.vue';

const northwind = { id: 'company-1', name: 'Northwind Traders' };
const self = { id: 'member-self', name: 'Rina Manager' };
const bob = { id: 'member-bob', name: 'Bob Biswas' };

const imran: ContactRow = {
    id: 'contact-1',
    name: 'Imran Hossain',
    firstName: 'Imran',
    lastName: 'Hossain',
    email: 'imran@northwind.test',
    phone: '+880 1711-000001',
    jobTitle: 'Purchasing lead',
    address: 'House 12, Road 5',
    city: 'Dhaka',
    country: 'BD',
    notes: 'Prefers a call before noon.',
    company: northwind,
    owner: bob,
    editable: true,
    deletable: true,
    createdAt: '2026-03-10T09:00:00+06:00',
};

const solo: ContactRow = {
    ...imran,
    id: 'contact-2',
    name: 'Solo',
    firstName: 'Solo',
    lastName: null,
    email: null,
    phone: null,
    jobTitle: null,
    address: null,
    city: null,
    country: null,
    notes: null,
    company: null,
    owner: null,
    editable: false,
    deletable: false,
};

const company: CompanyRow = {
    id: 'company-1',
    name: 'Northwind Traders',
    email: 'hello@northwind.test',
    phone: '+880255500001',
    website: 'https://northwind.test',
    industry: 'Wholesale',
    taxId: 'BIN-000123456',
    address: '45 Kawran Bazar',
    city: 'Dhaka',
    country: 'BD',
    notes: null,
    contacts: 3,
    owner: bob,
    editable: true,
    deletable: true,
    createdAt: '2026-03-10T09:00:00+06:00',
};

function paginated<T>(data: T[], total = data.length): Record<string, unknown> {
    return {
        data,
        total,
        current_page: 1,
        last_page: 1,
        per_page: 25,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
        prev_page_url: null,
        next_page_url: null,
    };
}

const mounted: VueWrapper[] = [];

function mountPeople(props: Record<string, unknown> = {}): VueWrapper {
    const wrapper = mount(People as Component, {
        attachTo: document.body,
        props: {
            contacts: paginated([imran, solo]),
            filters: { search: '', company: null },
            companies: [northwind],
            owners: [self, bob],
            canCreate: true,
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

function mountCompanies(props: Record<string, unknown> = {}): VueWrapper {
    const wrapper = mount(Companies as Component, {
        attachTo: document.body,
        props: {
            companies: paginated([company]),
            filters: { search: '' },
            owners: [self, bob],
            canCreate: true,
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

/** A field of the dialog that is open; dialogs render outside the component, in the body. */
function field<T extends HTMLElement = HTMLInputElement>(name: string): T {
    const element = document.body.querySelector<T>(`[role="dialog"] [name="${name}"]`);

    if (!element) {
        throw new Error(`The open dialog has no field named [${name}].`);
    }

    return element;
}

function optionsOf(name: string): string[] {
    return [...field<HTMLSelectElement>(name).options].map((option) => option.textContent?.trim() ?? '');
}

async function type(name: string, value: string): Promise<void> {
    const element = field(name);

    element.value = value;
    element.dispatchEvent(new Event(element.tagName === 'SELECT' ? 'change' : 'input'));

    await flushPromises();
}

async function submit(): Promise<void> {
    document.body.querySelector('[role="dialog"] form')?.dispatchEvent(new Event('submit', { cancelable: true }));

    await flushPromises();
}

/** The pages ask the window how wide it is to decide which columns start out visible. */
function onScreen(size: 'phone' | 'wide'): void {
    vi.spyOn(window, 'matchMedia').mockImplementation(
        (query: string) =>
            ({
                matches: size === 'wide',
                media: query,
                onchange: null,
                addEventListener: () => {},
                removeEventListener: () => {},
                addListener: () => {},
                removeListener: () => {},
                dispatchEvent: () => false,
            }) as MediaQueryList,
    );
}

function headings(wrapper: VueWrapper): string[] {
    return wrapper.findAll('thead th').map((cell) => cell.text());
}

/** Opens the menu at the end of a row, by keyboard, and returns the names of its entries. */
async function openMenu(wrapper: VueWrapper, recordId: string): Promise<string[]> {
    await wrapper.get(`button[data-record-actions="${recordId}"]`).trigger('keydown', { key: 'Enter' });
    await flushPromises();

    return menuItems().map((item) => item.textContent?.trim() ?? '');
}

function menuItems(): HTMLElement[] {
    return [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')];
}

async function choose(label: string): Promise<void> {
    const item = menuItems().find((candidate) => candidate.textContent?.trim() === label);

    if (!item) {
        throw new Error(`The open menu has no entry [${label}].`);
    }

    item.click();

    await flushPromises();
    // Dialogs let the menu close first.
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
}

function dialog(): HTMLElement {
    const element = document.body.querySelector<HTMLElement>('[role="dialog"]');

    if (!element) {
        throw new Error('No dialog is open.');
    }

    return element;
}

async function press(label: string): Promise<void> {
    const button = [...dialog().querySelectorAll('button')].find((candidate) => candidate.textContent?.trim() === label);

    if (!button) {
        throw new Error(`The open dialog has no button [${label}].`);
    }

    button.click();

    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.sent.length = 0;
    window.localStorage.clear();
    onScreen('phone');
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('contact links', () => {
    it('dials a phone number that is one plain number', () => {
        expect(telHref('+880 1711-000001')).toBe('tel:+8801711000001');
        expect(telHref('(02) 555.0001')).toBe('tel:025550001');
    });

    it('leaves anything else as text rather than dialling a guess', () => {
        expect(telHref('01711000001 ext. 5')).toBeUndefined();
        expect(telHref('01711000001 / 01811000002')).toBeUndefined();
        expect(telHref('ask at the front desk')).toBeUndefined();
        expect(telHref('12')).toBeUndefined();
        expect(telHref('')).toBeUndefined();
        expect(telHref(null)).toBeUndefined();
    });

    it('writes to an email address, when there is one', () => {
        expect(mailHref('imran@northwind.test')).toBe('mailto:imran@northwind.test');
        expect(mailHref(null)).toBeUndefined();
    });
});

describe('the people list', () => {
    it('shows every column on a wide screen, the company as a link that narrows the list', () => {
        onScreen('wide');

        const wrapper = mountPeople();
        const rows = wrapper.findAll('tbody tr');

        expect(headings(wrapper)).toEqual(['Name', 'Company', 'Email', 'Phone', 'Job title', 'Owner', 'Actions']);
        expect(rows).toHaveLength(2);

        const cells = rows[0]!.findAll('td');

        expect(cells[0]!.text()).toContain('Imran Hossain');
        expect(cells[1]!.get('a').attributes('href')).toBe('/core.contacts.index?company=company-1');
        expect(cells[1]!.text()).toBe('Northwind Traders');
        expect(cells[2]!.get('a').attributes('href')).toBe('mailto:imran@northwind.test');
        expect(cells[3]!.get('a').attributes('href')).toBe('tel:+8801711000001');
        expect(cells[3]!.text()).toBe('+880 1711-000001');
        expect(cells[4]!.text()).toBe('Purchasing lead');
        expect(cells[5]!.text()).toBe('Bob Biswas');
        expect(wrapper.get('[role="status"]').text()).toBe('2 contacts');

        // Nothing recorded is a dash, never a dead link; a contact of nobody says so.
        expect(rows[1]!.findAll('a')).toHaveLength(0);
        expect(rows[1]!.findAll('td')[5]!.text()).toBe('No one');
    });

    it('fits a phone: the name alone, with the ways to reach someone under it', () => {
        const wrapper = mountPeople();
        const rows = wrapper.findAll('tbody tr');

        expect(headings(wrapper)).toEqual(['Name', 'Actions']);

        const name = rows[0]!.findAll('td')[0]!;

        expect(name.findAll('a').map((link) => link.attributes('href'))).toEqual([
            '/core.contacts.index?company=company-1',
            'mailto:imran@northwind.test',
            'tel:+8801711000001',
        ]);
        expect(rows[1]!.findAll('td')[0]!.find('ul').exists()).toBe(false);

        // Every row still has its menu.
        expect(rows.map((row) => row.get('button[data-record-actions]').text())).toEqual(['Actions for Imran Hossain', 'Actions for Solo']);
    });

    it('offers on each row only what the server said this person may do with it', async () => {
        const wrapper = mountPeople({
            contacts: paginated([imran, { ...solo, id: 'contact-3', name: 'Kept', deletable: true }, solo]),
        });

        expect(await openMenu(wrapper, 'contact-1')).toEqual(['View details', 'Edit', 'Delete']);

        await choose('View details');
        await press('Close');

        expect(await openMenu(wrapper, 'contact-3')).toEqual(['View details', 'Delete']);

        await choose('View details');
        await press('Close');

        expect(await openMenu(wrapper, 'contact-2')).toEqual(['View details']);
    });

    it('shows everything recorded about a contact, and the way to the form when it may be changed', async () => {
        const wrapper = mountPeople();

        await openMenu(wrapper, 'contact-1');
        await choose('View details');

        const text = dialog().textContent ?? '';

        expect(text).toContain('Imran Hossain');

        for (const value of ['Purchasing lead', 'Northwind Traders', 'imran@northwind.test', 'House 12, Road 5', 'Dhaka', 'Bangladesh', 'Prefers a call before noon.', 'Bob Biswas']) {
            expect(text).toContain(value);
        }

        expect(dialog().querySelector('a[href="/core.contacts.index?company=company-1"]')).not.toBeNull();
        expect(dialog().querySelector('a[href="tel:+8801711000001"]')).not.toBeNull();

        await press('Edit');

        expect(dialog().textContent).toContain('Edit Imran Hossain');
        expect(field('first_name').value).toBe('Imran');
    });

    it('lets someone who may not change a contact read it all the same', async () => {
        const wrapper = mountPeople({ canCreate: false, companies: [], owners: [] });

        await openMenu(wrapper, 'contact-2');
        await choose('View details');

        expect(dialog().textContent).toContain('Solo');
        expect([...dialog().querySelectorAll('button')].map((button) => button.textContent?.trim())).not.toContain('Edit');
        // Nothing recorded is said, not left blank.
        expect(dialog().textContent).toContain('Not recorded');
        expect(dialog().textContent).toContain('No one');
    });

    it('deletes a contact only after it has been confirmed', async () => {
        const wrapper = mountPeople();

        await openMenu(wrapper, 'contact-1');
        await choose('Delete');

        expect(dialog().textContent).toContain('Delete Imran Hossain?');
        expect(dialog().textContent).toContain('Imran Hossain is removed from the contacts of Acme for everyone.');
        expect(inertia.visits).toEqual([]);

        await press('Delete contact');

        expect(inertia.visits).toEqual([{ method: 'delete', url: '/core.contacts.destroy?contact=contact-1' }]);
    });

    it('links to the companies list and marks itself as the current one', () => {
        const tabs = mountPeople().get('nav[aria-label="Contacts and companies"]');

        expect(tabs.get('a[aria-current="page"]').text()).toBe('People');
        expect(tabs.get('a[href="/core.contacts.companies.index"]').text()).toBe('Companies');
    });

    it('offers to add a contact only to someone who may', () => {
        expect(mountPeople().find('[data-directory-add]').text()).toBe('Add contact');

        const viewer = mountPeople({ canCreate: false, companies: [], owners: [] });

        expect(viewer.find('[data-directory-add]').exists()).toBe(false);
        expect(viewer.text()).toContain('Adding and changing them is not part of your role.');
    });

    it('tells someone who manages only some contacts why the rest cannot be changed', () => {
        expect(mountPeople({ owners: [] }).text()).toContain('change the ones that are yours to manage');
        expect(mountPeople().text()).not.toContain('yours to manage');
    });

    it('names the company the list is narrowed to and clears it on request', async () => {
        const wrapper = mountPeople({ contacts: paginated([imran]), filters: { search: '', company: northwind } });

        expect(wrapper.get('[role="status"]').text()).toBe('1 match');

        const clear = wrapper.findAll('button').find((button) => button.text() === 'Show contacts from every company');

        expect(clear).toBeDefined();

        await clear!.trigger('click');

        expect(inertia.visits).toEqual([{ method: 'get', url: '/core.contacts.index', data: {} }]);
    });

    it('searches on the server a moment after typing stops, keeping the company filter', async () => {
        vi.useFakeTimers();

        const wrapper = mountPeople({ filters: { search: '', company: northwind } });

        await wrapper.get('input[role="searchbox"]').setValue('  nadia ');

        vi.advanceTimersByTime(200);
        expect(inertia.visits).toHaveLength(0);

        vi.advanceTimersByTime(150);
        expect(inertia.visits).toEqual([{ method: 'get', url: '/core.contacts.index', data: { search: 'nadia', company: 'company-1' } }]);

        // The same search is not asked for twice.
        await wrapper.get('form[role="search"]').trigger('submit');
        expect(inertia.visits).toHaveLength(1);
    });

    it('says so when the directory is empty, and offers the first step to someone who may take it', () => {
        const empty = mountPeople({ contacts: paginated([]) });

        expect(empty.text()).toContain('No contacts yet');
        expect(empty.find('input[role="searchbox"]').exists()).toBe(false);
        expect(empty.findAll('button').filter((button) => button.text() === 'Add contact')).toHaveLength(2);

        const viewer = mountPeople({ contacts: paginated([]), canCreate: false, companies: [], owners: [] });

        expect(viewer.text()).toContain('Contacts you are allowed to see will appear here.');
        expect(viewer.findAll('button').filter((button) => button.text() === 'Add contact')).toHaveLength(0);
    });

    it('tells a search that found nothing apart from an empty company', () => {
        const noMatch = mountPeople({ contacts: paginated([]), filters: { search: 'zzz', company: null } });

        expect(noMatch.text()).toContain('Nothing matches “zzz”');
        expect(noMatch.get('[role="status"]').text()).toBe('0 matches');

        const emptyCompany = mountPeople({ contacts: paginated([]), filters: { search: '', company: northwind } });

        expect(emptyCompany.text()).toContain('No contacts at Northwind Traders');
        expect(emptyCompany.findAll('button').some((button) => button.text() === 'Show all contacts')).toBe(true);
    });
});

describe('the companies list', () => {
    it('shows every column on a wide screen, the people count as a link to those people', () => {
        onScreen('wide');

        const wrapper = mountCompanies();
        const cells = wrapper.get('tbody tr').findAll('td');

        expect(headings(wrapper)).toEqual(['Name', 'People', 'Email', 'Phone', 'Location', 'Owner', 'Actions']);
        expect(cells[0]!.text()).toContain('Northwind Traders');
        expect(cells[1]!.text()).toBe('3');
        expect(cells[1]!.get('a').attributes()).toMatchObject({
            href: '/core.contacts.index?company=company-1',
            'aria-label': 'Show the contacts at Northwind Traders (3 people)',
        });
        expect(cells[2]!.get('a').attributes('href')).toBe('mailto:hello@northwind.test');
        expect(cells[4]!.text()).toBe('Dhaka, Bangladesh');
        expect(cells[5]!.text()).toBe('Bob Biswas');
        expect(wrapper.get('[role="status"]').text()).toBe('1 company');
        expect(wrapper.get('nav[aria-label="Contacts and companies"] a[aria-current="page"]').text()).toBe('Companies');
    });

    it('fits a phone: the name alone, with its people, email and phone under it', () => {
        const wrapper = mountCompanies();
        const name = wrapper.get('tbody tr').findAll('td')[0]!;

        expect(headings(wrapper)).toEqual(['Name', 'Actions']);
        expect(name.findAll('a').map((link) => [link.text(), link.attributes('href')])).toEqual([
            ['3 people', '/core.contacts.index?company=company-1'],
            ['hello@northwind.test', 'mailto:hello@northwind.test'],
            ['+880255500001', 'tel:+880255500001'],
        ]);
    });

    it('offers to add a company only to someone who may', () => {
        expect(mountCompanies().find('[data-directory-add]').text()).toBe('Add company');

        const viewer = mountCompanies({ canCreate: false, owners: [] });

        expect(viewer.find('[data-directory-add]').exists()).toBe(false);
        expect(viewer.text()).toContain('Adding and changing them is not part of your role.');
    });

    it('says before deleting a company that its people stay, without a company', async () => {
        const wrapper = mountCompanies();

        expect(await openMenu(wrapper, 'company-1')).toEqual(['View details', 'Edit', 'Delete']);

        await choose('Delete');

        expect(dialog().textContent).toContain('Delete Northwind Traders?');
        expect(dialog().textContent).toContain('Its people stay in the directory, without a company.');
        expect(inertia.visits).toEqual([]);

        await press('Delete company');

        expect(inertia.visits).toEqual([{ method: 'delete', url: '/core.contacts.companies.destroy?company=company-1' }]);
    });

    it('shows everything recorded about a company, its website opening in a new tab', async () => {
        const wrapper = mountCompanies();

        await openMenu(wrapper, 'company-1');
        await choose('View details');

        const text = dialog().textContent ?? '';

        for (const value of ['Northwind Traders', 'Wholesale', 'BIN-000123456', 'hello@northwind.test', '45 Kawran Bazar', 'Bangladesh', '3 people', 'Bob Biswas']) {
            expect(text).toContain(value);
        }

        const website = dialog().querySelector('a[href="https://northwind.test"]');

        expect(website?.getAttribute('target')).toBe('_blank');
        expect(website?.getAttribute('rel')).toBe('noopener noreferrer');
        expect(dialog().querySelector('a[href="/core.contacts.index?company=company-1"]')?.textContent).toBe('3 people');
    });

    it('says so when there are no companies, or none that match', () => {
        expect(mountCompanies({ companies: paginated([]) }).text()).toContain('No companies yet');
        expect(mountCompanies({ companies: paginated([]), filters: { search: 'zzz' } }).text()).toContain('Nothing matches “zzz”');
    });
});

describe('the contact form', () => {
    async function open(props: Record<string, unknown> = {}): Promise<VueWrapper> {
        const wrapper = mount(ContactFormDialog as Component, {
            attachTo: document.body,
            props: { open: false, contact: null, companies: [northwind], owners: [self, bob], ...props },
        });

        mounted.push(wrapper);

        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    it('starts a new contact with the person adding it as owner, in the company the list is narrowed to', async () => {
        await open({ startCompany: northwind });

        expect(field('first_name').value).toBe('');
        expect(field<HTMLSelectElement>('company_id').value).toBe('company-1');
        expect(field<HTMLSelectElement>('owner_id').value).toBe('member-self');
        // A new contact always belongs to someone.
        expect(optionsOf('owner_id')).toEqual(['Rina Manager', 'Bob Biswas']);

        await type('first_name', 'Nadia');
        await submit();

        expect(inertia.sent).toEqual([
            {
                method: 'post',
                url: '/core.contacts.store',
                data: {
                    first_name: 'Nadia',
                    last_name: '',
                    email: '',
                    phone: '',
                    job_title: '',
                    company_id: 'company-1',
                    address: '',
                    city: '',
                    country: '',
                    notes: '',
                    owner_id: 'member-self',
                },
            },
        ]);
    });

    it('neither shows nor sends an owner when there is no one to hand a contact to', async () => {
        await open({ owners: [] });

        expect(document.body.querySelector('[role="dialog"] [name="owner_id"]')).toBeNull();

        await type('first_name', 'Nadia');
        await submit();

        expect(inertia.sent).toHaveLength(1);
        expect(inertia.sent[0]!.data).not.toHaveProperty('owner_id');
        expect(inertia.sent[0]!.data).toHaveProperty('first_name', 'Nadia');
    });

    it('fills in the contact being changed and sends every field back', async () => {
        await open({ contact: imran });

        expect(field('first_name').value).toBe('Imran');
        expect(field('email').value).toBe('imran@northwind.test');
        expect(field<HTMLTextAreaElement>('notes').value).toBe('Prefers a call before noon.');
        expect(field<HTMLSelectElement>('country').value).toBe('BD');
        expect(field<HTMLSelectElement>('owner_id').value).toBe('member-bob');
        // An existing contact can be handed to no one.
        expect(optionsOf('owner_id')).toEqual(['No one', 'Rina Manager', 'Bob Biswas']);

        await type('job_title', '');
        await submit();

        expect(inertia.sent).toEqual([
            {
                method: 'put',
                url: '/core.contacts.update?contact=contact-1',
                data: {
                    first_name: 'Imran',
                    last_name: 'Hossain',
                    email: 'imran@northwind.test',
                    phone: '+880 1711-000001',
                    job_title: '',
                    company_id: 'company-1',
                    address: 'House 12, Road 5',
                    city: 'Dhaka',
                    country: 'BD',
                    notes: 'Prefers a call before noon.',
                    owner_id: 'member-bob',
                },
            },
        ]);
    });

    it('keeps a company and an owner the lists no longer offer', async () => {
        const gone = { id: 'member-gone', name: 'Sam Suspended' };
        const hidden = { id: 'company-9', name: 'Hidden Holdings' };

        await open({ contact: { ...imran, company: hidden, owner: gone } });

        expect(field<HTMLSelectElement>('company_id').value).toBe('company-9');
        expect(optionsOf('company_id')).toEqual(['No company', 'Northwind Traders', 'Hidden Holdings']);
        expect(field<HTMLSelectElement>('owner_id').value).toBe('member-gone');
        expect(optionsOf('owner_id')).toEqual(['No one', 'Rina Manager', 'Bob Biswas', 'Sam Suspended']);
    });

    it('says where companies come from when there are none to choose', async () => {
        await open({ companies: [] });

        expect(optionsOf('company_id')).toEqual(['No company']);
        expect(document.body.textContent).toContain('Add them under Companies.');
    });
});

describe('the company form', () => {
    async function open(props: Record<string, unknown> = {}): Promise<VueWrapper> {
        const wrapper = mount(CompanyFormDialog as Component, {
            attachTo: document.body,
            props: { open: false, company: null, owners: [self, bob], ...props },
        });

        mounted.push(wrapper);

        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    it('adds a company with every field the server accepts', async () => {
        await open();

        await type('name', 'Fabrikam');
        await type('website', 'https://fabrikam.test');
        await type('country', 'BD');
        await submit();

        expect(inertia.sent).toEqual([
            {
                method: 'post',
                url: '/core.contacts.companies.store',
                data: {
                    name: 'Fabrikam',
                    email: '',
                    phone: '',
                    website: 'https://fabrikam.test',
                    industry: '',
                    tax_id: '',
                    address: '',
                    city: '',
                    country: 'BD',
                    notes: '',
                    owner_id: 'member-self',
                },
            },
        ]);
    });

    it('changes a company, and sends no owner when there is no one to hand it to', async () => {
        await open({ company, owners: [] });

        expect(field('name').value).toBe('Northwind Traders');
        expect(field('tax_id').value).toBe('BIN-000123456');
        expect(document.body.querySelector('[role="dialog"] [name="owner_id"]')).toBeNull();

        await type('industry', 'Retail');
        await submit();

        expect(inertia.sent).toHaveLength(1);
        expect(inertia.sent[0]).toMatchObject({ method: 'put', url: '/core.contacts.companies.update?company=company-1' });
        expect(inertia.sent[0]!.data).toMatchObject({ name: 'Northwind Traders', industry: 'Retail', country: 'BD' });
        expect(inertia.sent[0]!.data).not.toHaveProperty('owner_id');
    });
});
