import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

/*
 * The Super Admin panel's Website pages (docs/WEBSITE.md), mounted with the real catalogue and components. Inertia
 * and the route table are stood in for: forms record what they would send.
 */
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { abilities: ['platform.website.view', 'platform.website.manage'] as string[] },
            errors: {},
        },
        url: '/website/messages',
    },
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
    router: { on: () => () => {}, get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...JSON.parse(JSON.stringify(initial)),
            errors: {},
            processing: false,
            put: (url: string) => {
                const data = Object.fromEntries(Object.keys(initial).map((key) => [key, JSON.parse(JSON.stringify(form[key]))]));

                inertia.sent.push({ method: 'put', url, data: data as Record<string, unknown> });
            },
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

import { router } from '@inertiajs/vue3';
import { setLocale } from '@/Core/i18n';
import Company from '@/Platform/Pages/Website/Company.vue';
import Messages from '@/Platform/Pages/Website/Messages.vue';
import Texts from '@/Platform/Pages/Website/Texts.vue';

const RAW_KEY = /platformWebsite\.[a-z]/;
const noBrand = { logo: null, logo_dark: null, favicon: null };

afterEach(() => {
    setLocale('en');
    inertia.sent = [];
    inertia.page.props.auth.abilities = ['platform.website.view', 'platform.website.manage'];
});

const enquiry = {
    id: 'e1',
    name: 'Rahim Uddin',
    email: 'rahim@example.com',
    phone: '+880 1700-000000',
    company: 'Rahim Traders',
    topic: 'demo',
    message: 'We want payroll in Bengali.',
    locale: 'bn',
    status: 'new' as const,
    createdAt: '2026-10-14T10:00:00+00:00',
    handledAt: null,
    handledBy: null,
};

function mountMessages() {
    return mount(Messages, {
        props: {
            enquiries: { data: [enquiry], current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null },
            counts: { new: 1, handled: 0 },
            filters: { status: 'new', search: '' },
            website: 'http://localhost',
        },
    });
}

describe('the messages page', () => {
    it('shows each message with ways to answer it', () => {
        const wrapper = mountMessages();
        const card = wrapper.get('[data-testid="enquiry-e1"]');

        expect(card.text()).toContain('Rahim Uddin');
        expect(card.text()).toContain('Book a demo');
        expect(card.text()).toContain('Sent from the Bengali page');
        expect(card.get('a[href^="mailto:"]').attributes('href')).toBe('mailto:rahim@example.com?subject=Re%3A%20Book%20a%20demo');
        expect(card.get('a[href^="tel:"]').attributes('href')).toBe('tel:+8801700000000');
        expect(card.text()).toContain('Mark as answered');
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('offers no changes to someone who may only read', () => {
        inertia.page.props.auth.abilities = ['platform.website.view'];

        const wrapper = mountMessages();

        expect(wrapper.text()).not.toContain('Mark as answered');
        expect(wrapper.text()).not.toContain('Delete');
        // Only the tab they can open.
        expect(wrapper.findAll('nav a').map((link) => link.text())).toEqual(['Messages']);
    });

    it('is complete in Bengali', () => {
        setLocale('bn');

        expect(mountMessages().text()).not.toMatch(RAW_KEY);
    });
});

describe('the company page', () => {
    const company = {
        name: 'Oyosend',
        email: 'info@oyosend.com',
        phone_1: '+88 0179 22 88 555',
        phone_2: null,
        street: '1st Floor, House 08, Road 13/A, Sector 06',
        locality: 'Uttara',
        city: 'Dhaka',
        postal_code: '1230',
        address_bn: 'উত্তরা, ঢাকা',
        map_url: null,
        contact_recipient: 'info@oyosend.com',
        social: { facebook: 'https://www.facebook.com/oyosend', linkedin: null, youtube: null, instagram: null, x: null },
    };

    it('starts from the saved details and sends them back as typed', async () => {
        const wrapper = mount(Company, { props: { company, networks: ['facebook', 'linkedin', 'youtube', 'instagram', 'x'], brand: noBrand, website: 'https://oyosend.com' } });

        expect((wrapper.get('input[name="phone_1"]').element as HTMLInputElement).value).toBe('+88 0179 22 88 555');

        await wrapper.get('input[name="social.linkedin"]').setValue('https://www.linkedin.com/company/oyosend');
        await wrapper.get('form').trigger('submit');

        expect(inertia.sent).toHaveLength(1);
        expect(inertia.sent[0]!.url).toBe('/platform.website.company.update');
        expect(inertia.sent[0]!.data).toMatchObject({
            phone_1: '+88 0179 22 88 555',
            phone_2: '',
            social: { facebook: 'https://www.facebook.com/oyosend', linkedin: 'https://www.linkedin.com/company/oyosend' },
        });
        expect(wrapper.text()).not.toMatch(RAW_KEY);

        setLocale('bn');
        expect(mount(Company, { props: { company, networks: ['facebook'], brand: noBrand, website: 'https://oyosend.com' } }).text()).not.toMatch(RAW_KEY);
    });

    it('shows each brand image where it will sit, and uploads a chosen file at once', async () => {
        const brand = { logo: 'https://oyosend.com/brand/logo-0123456789abcdef.png', logo_dark: null, favicon: null };
        const wrapper = mount(Company, { props: { company, networks: ['facebook'], brand, website: 'https://oyosend.com' } });
        const tiles = wrapper.findAll('[data-asset]');

        expect(tiles.map((tile) => tile.attributes('data-asset'))).toEqual(['logo', 'logo_dark', 'favicon']);
        expect(tiles[0]!.text()).toContain('Uploaded');
        expect(tiles[0]!.get('img').attributes('src')).toBe(brand.logo);
        // Without its own, the dark-background logo previews the logo on the dark canvas.
        expect(tiles[1]!.get('[data-theme="dark"] img').attributes('src')).toBe(brand.logo);
        expect(tiles[2]!.text()).toContain('Built-in');
        expect(tiles[2]!.get('img').attributes('src')).toBe('/favicon.svg');

        const file = new File(['png'], 'icon.png', { type: 'image/png' });
        const input = tiles[2]!.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');

        expect(router.post).toHaveBeenCalledWith('/platform.website.brand.store?asset=favicon', { file }, expect.objectContaining({ forceFormData: true }));
    });
});

describe('the words page', () => {
    const props = {
        sections: [
            { key: 'home', kind: 'page' as const, name: null },
            { key: 'app.crm', kind: 'app' as const, name: 'CRM & Sales' },
            { key: 'privacy', kind: 'legal' as const, name: null },
        ],
        section: 'home',
        fields: [
            {
                key: 'site.home.title',
                path: 'title',
                default: { en: 'Run your whole company from one workspace.', bn: 'পুরো প্রতিষ্ঠান চালান একটি ওয়ার্কস্পেস থেকে।' },
                value: { en: 'Your business, in one place.', bn: null },
                placeholders: [],
                long: false,
            },
            {
                key: 'site.home.apps_eyebrow',
                path: 'apps_eyebrow',
                default: { en: 'One workspace, :count apps', bn: 'এক ওয়ার্কস্পেসে :countটি অ্যাপ' },
                value: { en: null, bn: null },
                placeholders: [':count'],
                long: false,
            },
        ],
        preview: 'https://oyosend.com',
    };

    it('shows every word as the website shows it now, and what changed', () => {
        const wrapper = mount(Texts, { props });
        const words = wrapper.findAll('[data-key]');

        expect(wrapper.findAll('nav a').map((link) => link.text())).toEqual(['Messages', 'Company', 'Words', 'Blog', 'Home page', 'CRM & Sales', 'Privacy policy']);
        expect(words).toHaveLength(2);
        expect((words[0]!.findAll('input')[0]!.element as HTMLInputElement).value).toBe('Your business, in one place.');
        expect((words[0]!.findAll('input')[1]!.element as HTMLInputElement).value).toBe('পুরো প্রতিষ্ঠান চালান একটি ওয়ার্কস্পেস থেকে।');
        expect(words[0]!.text()).toContain('Changed');
        expect(words[1]!.text()).toContain('Keep :count');
        expect(wrapper.text()).toContain('1 changed');
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('narrows the list to the words that match', async () => {
        const wrapper = mount(Texts, { props });

        await wrapper.get('input[type="search"]').setValue('apps');

        expect(wrapper.findAll('[data-key]').map((word) => word.attributes('data-key'))).toEqual(['site.home.apps_eyebrow']);
    });

    it('sends both languages of every word of the part', async () => {
        const wrapper = mount(Texts, { props });

        await wrapper.get('form').trigger('submit');

        expect(inertia.sent[0]!.url).toBe('/platform.website.texts.update?section=home');
        expect(inertia.sent[0]!.data.values).toEqual({
            en: { 'site.home.title': 'Your business, in one place.', 'site.home.apps_eyebrow': 'One workspace, :count apps' },
            bn: { 'site.home.title': 'পুরো প্রতিষ্ঠান চালান একটি ওয়ার্কস্পেস থেকে।', 'site.home.apps_eyebrow': 'এক ওয়ার্কস্পেসে :countটি অ্যাপ' },
        });
    });

    it('is complete in Bengali', () => {
        setLocale('bn');

        expect(mount(Texts, { props }).text()).not.toMatch(RAW_KEY);
    });
});
