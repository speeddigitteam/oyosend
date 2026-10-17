import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, type Component } from 'vue';

/*
 * Typed addresses of companies and contacts (ADR 0039): the card, the form, and the "Addresses" section of a company's
 * page. Inertia and the route table are stood in for: visits and submitted forms are recorded instead of sent.
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
    sent: [] as Array<{ method: string; url: string; data?: Record<string, unknown> }>,
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
        get: () => {},
        post: (url: string, data: Record<string, unknown>) => inertia.sent.push({ method: 'post', url, data }),
        delete: (url: string, options: { onSuccess?: () => void; onFinish?: () => void } = {}) => {
            inertia.sent.push({ method: 'delete', url });
            options.onSuccess?.();
            options.onFinish?.();
        },
    },
    useForm: (initial: Record<string, unknown>) => {
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

import AddressCard from '@/Core/Components/Contacts/AddressCard.vue';
import AddressFormDialog from '@/Core/Components/Contacts/AddressFormDialog.vue';
import { cityLine, groupAddresses } from '@/Core/Components/Contacts/addresses';
import type { AddressRow, CompanyRow } from '@/Core/Components/Contacts/types';
import { setLocale } from '@/Core/i18n';
import CompanyShow from '@/Core/Pages/Contacts/CompanyShow.vue';

const headOffice: AddressRow = {
    id: 'address-1',
    type: 'invoice',
    label: 'Head office',
    name: 'Accounts payable',
    email: 'ap@northwind.test',
    phone: '+880 1711-000001',
    address: 'House 12, Road 5\nGulshan',
    city: 'Dhaka',
    postalCode: '1212',
    country: 'BD',
    isDefault: true,
    editable: true,
};

const warehouse: AddressRow = {
    ...headOffice,
    id: 'address-2',
    type: 'delivery',
    label: null,
    name: null,
    email: null,
    phone: null,
    address: 'Plot 4, BSCIC',
    city: 'Gazipur',
    postalCode: null,
    country: null,
    isDefault: false,
};

const company: CompanyRow = {
    id: 'company-1',
    name: 'Northwind Traders',
    email: null,
    phone: null,
    website: null,
    industry: 'Wholesale',
    taxId: null,
    address: null,
    city: 'Dhaka',
    country: 'BD',
    notes: null,
    contacts: 0,
    owner: null,
    editable: true,
    deletable: true,
    createdAt: '2026-03-10T09:00:00+06:00',
    addresses: [warehouse, headOffice],
};

const mounted: VueWrapper[] = [];

function track(wrapper: VueWrapper): VueWrapper {
    mounted.push(wrapper);

    return wrapper;
}

function field<T extends HTMLElement = HTMLInputElement>(name: string): T {
    const element = document.body.querySelector<T>(`[role="dialog"] [name="${name}"]`);

    if (!element) {
        throw new Error(`The open dialog has no field named [${name}].`);
    }

    return element;
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

function menuItems(): string[] {
    return [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')].map((item) => item.textContent?.trim() ?? '');
}

async function choose(label: string): Promise<void> {
    const item = [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')].find((candidate) => candidate.textContent?.trim() === label);

    if (!item) {
        throw new Error(`The open menu has no entry [${label}].`);
    }

    item.click();
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
}

beforeEach(() => {
    setLocale('en');
    inertia.sent.length = 0;
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

describe('address helpers', () => {
    it('puts city and postal code on one line, and lists the types in order', () => {
        expect(cityLine(headOffice)).toBe('Dhaka 1212');
        expect(cityLine(warehouse)).toBe('Gazipur');
        expect(groupAddresses([warehouse, headOffice]).map((group) => group.type)).toEqual(['invoice', 'delivery']);
    });
});

describe('the address card', () => {
    it('shows what the address is for, the default, the place and the person, phone and email', () => {
        const card = track(mount(AddressCard, { props: { address: headOffice } }));

        expect(card.text()).toContain('Invoice address');
        expect(card.text()).toContain('Default');
        expect(card.get('h4').text()).toBe('Head office');
        expect(card.text()).toContain('House 12, Road 5');
        expect(card.text()).toContain('Dhaka 1212');
        expect(card.text()).toContain('Bangladesh');
        expect(card.text()).toContain('Attention: Accounts payable');
        expect(card.get('a[href="tel:+8801711000001"]').text()).toBe('+880 1711-000001');
        expect(card.get('a[href="mailto:ap@northwind.test"]').text()).toBe('ap@northwind.test');
    });

    it('is called by its type when it has no label, and offers no menu when the viewer may not change it', () => {
        const card = track(mount(AddressCard, { props: { address: { ...warehouse, editable: false } } }));

        expect(card.get('h4').text()).toBe('Delivery address');
        expect(card.text()).not.toContain('Default');
        expect(card.find('[data-address-actions]').exists()).toBe(false);
    });

    it('offers making it the default only when it is not', async () => {
        const card = track(mount(AddressCard, { attachTo: document.body, props: { address: warehouse } }));

        await card.get('[data-address-actions="address-2"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(menuItems()).toEqual(['Edit', 'Make default', 'Delete']);

        await choose('Make default');

        expect(card.emitted('makeDefault')).toHaveLength(1);
    });
});

describe('the address form', () => {
    async function open(props: Record<string, unknown>): Promise<VueWrapper> {
        const wrapper = track(mount(AddressFormDialog as Component, { attachTo: document.body, props: { open: false, address: null, ...props } }));

        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    it('adds an address to a company with every field the server accepts', async () => {
        await open({ parent: { kind: 'company', id: 'company-1', name: 'Northwind Traders' }, startType: 'delivery' });

        expect(document.body.textContent).toContain('Add an address for Northwind Traders');
        expect(field<HTMLSelectElement>('type').value).toBe('delivery');

        await type('label', 'Warehouse');
        await type('city', 'Gazipur');
        await type('postal_code', '1700');
        await type('country', 'BD');
        await submit();

        expect(inertia.sent).toEqual([
            {
                method: 'post',
                url: '/core.contacts.addresses.company.store?company=company-1',
                data: {
                    type: 'delivery',
                    label: 'Warehouse',
                    name: '',
                    email: '',
                    phone: '',
                    address: '',
                    city: 'Gazipur',
                    postal_code: '1700',
                    country: 'BD',
                },
            },
        ]);
    });

    it('adds to a contact, and asks to take the default over only when ticked', async () => {
        await open({ parent: { kind: 'contact', id: 'contact-1', name: 'Imran Hossain' } });

        await type('city', 'Sylhet');
        const box = document.body.querySelector<HTMLInputElement>('[role="dialog"] input[type="checkbox"]')!;
        box.click();
        await flushPromises();
        await submit();

        expect(inertia.sent[0]).toMatchObject({ method: 'post', url: '/core.contacts.addresses.contact.store?contact=contact-1' });
        expect(inertia.sent[0]!.data).toMatchObject({ type: 'invoice', city: 'Sylhet', is_default: true });
    });

    it('changes an address, and says it already is the default instead of offering the box', async () => {
        await open({ parent: { kind: 'company', id: 'company-1', name: 'Northwind Traders' }, address: headOffice });

        expect(field('label').value).toBe('Head office');
        expect(field('postal_code').value).toBe('1212');
        expect(document.body.querySelector('[data-address-default-note]')?.textContent).toContain('This is the default invoice address.');
        expect(document.body.querySelector('[role="dialog"] input[type="checkbox"]')).toBeNull();

        await type('city', 'Narayanganj');
        await submit();

        expect(inertia.sent[0]).toMatchObject({ method: 'put', url: '/core.contacts.addresses.update?address=address-1' });
        expect(inertia.sent[0]!.data).toMatchObject({ city: 'Narayanganj', label: 'Head office' });
        expect(inertia.sent[0]!.data).not.toHaveProperty('is_default');
    });
});

describe('the addresses of a company\'s page', () => {
    function mountPage(props: Record<string, unknown> = {}): VueWrapper {
        return track(
            mount(CompanyShow as Component, {
                attachTo: document.body,
                props: { company, people: [], companies: [], owners: [], canCreate: true, ...props },
            }),
        );
    }

    it('groups them by type, invoice first, with a way to add one', () => {
        const page = mountPage();
        const section = page.get('[data-company-addresses]');

        expect(section.get('h2').text()).toBe('Addresses');
        expect(section.findAll('[data-address-group]').map((group) => group.attributes('data-address-group'))).toEqual(['invoice', 'delivery']);
        expect(section.findAll('[data-address-card]').map((card) => card.attributes('data-address-card'))).toEqual(['address-1', 'address-2']);
        expect(section.find('[data-company-add-address]').exists()).toBe(true);
    });

    it('opens the form for the company, and asks before deleting', async () => {
        const page = mountPage();

        await page.get('[data-company-add-address]').trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Add an address for Northwind Traders');
        document.body.querySelector<HTMLButtonElement>('[role="dialog"] button[aria-label="Close"]')?.click();
        await flushPromises();

        await page.get('[data-address-actions="address-2"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();
        await choose('Delete');

        expect(document.body.textContent).toContain('Delete Delivery address?');
        const confirm = [...document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button, [role="dialog"] button')].find(
            (button) => button.textContent?.trim() === 'Delete address',
        );
        confirm?.click();
        await flushPromises();

        expect(inertia.sent).toContainEqual({ method: 'delete', url: '/core.contacts.addresses.destroy?address=address-2' });
    });

    it('shows them read only, and says so when there are none, to someone who may only look', () => {
        const readOnly = mountPage({
            company: { ...company, editable: false, deletable: false, addresses: [{ ...headOffice, editable: false }] },
            canCreate: false,
        });

        expect(readOnly.find('[data-company-add-address]').exists()).toBe(false);
        expect(readOnly.find('[data-address-actions]').exists()).toBe(false);
        readOnly.unmount();
        mounted.pop();

        const empty = mountPage({ company: { ...company, editable: false, addresses: [] }, canCreate: false });

        expect(empty.get('[data-company-addresses]').text()).toContain('No addresses yet');
        expect(empty.get('[data-company-addresses]').text()).toContain('The addresses recorded for this company will appear here.');
    });

    it('speaks Bengali', () => {
        setLocale('bn');
        const page = mountPage();

        expect(page.get('[data-company-addresses] h2').text()).toBe('ঠিকানা');
        expect(page.get('[data-company-addresses]').text()).toContain('ইনভয়েস');
    });
});
