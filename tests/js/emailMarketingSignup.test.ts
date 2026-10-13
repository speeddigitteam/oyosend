import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['email_marketing.lists.manage'] as string[] },
            tenant: { name: 'Acme' },
            errors: {} as Record<string, string>,
        },
    },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => `/${name}${Object.keys(params).length ? `?${new URLSearchParams(params)}` : ''}`,
}));

import { setLocale } from '@/Core/i18n';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import SignupPanel from '@/Modules/EmailMarketing/Components/SignupPanel.vue';
import type { SignupSummary } from '@/Modules/EmailMarketing/Components/types';

const mounted: VueWrapper[] = [];
const panel = (signup: Partial<SignupSummary> = {}, listActive = true): VueWrapper => {
    const wrapper = mount(SignupPanel, {
        props: {
            listId: 'list-1',
            listActive,
            signup: { enabled: false, embed: false, url: null, iframe: null, confirmed: 0, unconfirmed: 0, ...signup },
        },
    });
    mounted.push(wrapper);

    return wrapper;
};

beforeEach(() => {
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('public sign-up form panel', () => {
    it('shows no link, embed code or rotate control while the form is off', () => {
        const wrapper = panel();

        expect(wrapper.find('[data-testid="signup-url"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="signup-iframe"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Replace the link');
        expect(wrapper.get('[data-testid="signup-stats"]').text()).toContain('Waiting for confirmation');
    });

    it('turns the form on without framing and sends exactly the three fields', async () => {
        const wrapper = panel();

        await wrapper.get('input[type="checkbox"]').setValue(true);

        expect(inertia.router.put).toHaveBeenCalledTimes(1);
        const [url, data] = inertia.router.put.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/email_marketing.lists.signup?list=list-1');
        expect(data).toEqual({ rotate: false, enabled: true, embed: false });
    });

    it('shows the link, and the iframe code only when embedding is allowed', () => {
        const off = panel({ enabled: true, url: 'https://acme.example/email-marketing/join/abc', iframe: null });

        expect(off.get('[data-testid="signup-url"]').text()).toBe('https://acme.example/email-marketing/join/abc');
        expect(off.find('[data-testid="signup-iframe"]').exists()).toBe(false);

        const on = panel({ enabled: true, embed: true, url: 'https://acme.example/email-marketing/join/abc', iframe: '<iframe src="https://acme.example/email-marketing/join/abc"></iframe>' });
        expect(on.get('[data-testid="signup-iframe"]').text()).toContain('<iframe');
    });

    it('does not offer to enable the form on an archived list', () => {
        const wrapper = panel({}, false);

        expect(wrapper.get('input[type="checkbox"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('An archived list cannot take sign-ups');
    });

    it('has every sign-up string in both locales', () => {
        const english = (en as unknown as { emailMarketing: { signup: Record<string, string> } }).emailMarketing.signup;
        const bengali = (bn as unknown as { emailMarketing: { signup: Record<string, string> } }).emailMarketing.signup;

        expect(Object.keys(bengali).sort()).toEqual(Object.keys(english).sort());
        expect(Object.values(bengali).every((value) => value.trim() !== '')).toBe(true);
    });
});
