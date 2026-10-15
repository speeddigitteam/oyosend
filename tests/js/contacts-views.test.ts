import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
    usePage: () => ({ props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' } } }),
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), on: vi.fn(() => () => undefined) },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => `/${name}?${new URLSearchParams(params).toString()}`,
    isCurrentRoute: () => false,
}));

import ContactCard from '@/Core/Components/Contacts/ContactCard.vue';
import type { ContactRow } from '@/Core/Components/Contacts/types';
import { initialsOf, sortKeyOf, storedView, storeView } from '@/Core/Components/Contacts/view';
import { setLocale } from '@/Core/i18n';

const contact = (overrides: Partial<ContactRow> = {}): ContactRow =>
    ({
        id: '01JABCDEFGHJKMNPQRSTVWXYZ0',
        name: 'Kamrul Hasan',
        firstName: 'Kamrul',
        lastName: 'Hasan',
        email: 'kamrul@padmatextiles.example',
        phone: '+8801811000201',
        jobTitle: 'Managing Director',
        address: null,
        city: 'Dhaka',
        country: 'BD',
        notes: null,
        company: { id: '01JCOMPANY000000000000000A', name: 'Padma Textiles Ltd' },
        owner: { id: 'm1', name: 'Rafiqul Islam' },
        editable: true,
        deletable: true,
        createdAt: '2026-10-01T00:00:00Z',
        ...overrides,
    }) as ContactRow;

beforeEach(() => setLocale('en'));
afterEach(() => window.localStorage.clear());

describe('the people list views', () => {
    it('makes avatar initials from the first and last word, in any script', () => {
        expect(initialsOf('Kamrul Hasan')).toBe('KH');
        expect(initialsOf('Nilufar')).toBe('N');
        expect(initialsOf('  shirin   akter chowdhury ')).toBe('SC');
        expect(initialsOf('রফিকুল ইসলাম')).toBe('রই');
        expect(initialsOf('')).toBe('?');
    });

    it('remembers list or cards in this browser, and starts from the list', () => {
        expect(storedView()).toBe('list');
        storeView('grid');
        expect(storedView()).toBe('grid');
    });

    it('maps the list columns to the sort keys the server accepts', () => {
        expect(sortKeyOf('name')).toBe('name');
        expect(sortKeyOf('location')).toBe('city');
        expect(sortKeyOf('createdAt')).toBe('created');
        expect(sortKeyOf('phone')).toBeNull();
    });

    it('shows a person as a card with their work, ways to reach them, place and owner', async () => {
        const card = mount(ContactCard, { props: { contact: contact(), countryLabel: 'Bangladesh' } });

        expect(card.text()).toContain('Kamrul Hasan');
        expect(card.text()).toContain('Managing Director');
        expect(card.get('a[href*="core.contacts.index"]').text()).toBe('Padma Textiles Ltd');
        expect(card.get('a[href^="mailto:"]').text()).toBe('kamrul@padmatextiles.example');
        expect(card.get('a[href^="tel:"]').text()).toBe('+8801811000201');
        expect(card.text()).toContain('Dhaka, Bangladesh');
        expect(card.text()).toContain('Rafiqul Islam');

        await card.get('h3 button').trigger('click');
        expect(card.emitted('view')).toHaveLength(1);
    });

    it('leaves the company out on that company\'s own page', () => {
        const card = mount(ContactCard, { props: { contact: contact(), countryLabel: 'Bangladesh', hideCompany: true } });

        expect(card.text()).toContain('Managing Director');
        expect(card.text()).not.toContain('Padma Textiles Ltd');
        expect(card.text()).not.toContain('·');
    });

    it('leaves out what is not recorded and says when nobody owns the contact', () => {
        const card = mount(ContactCard, {
            props: { contact: contact({ email: null, phone: null, city: null, country: null, company: null, jobTitle: null, owner: null }), countryLabel: null },
        });

        expect(card.find('a[href^="mailto:"]').exists()).toBe(false);
        expect(card.find('a[href^="tel:"]').exists()).toBe(false);
        expect(card.text()).not.toContain('·');
    });
});
