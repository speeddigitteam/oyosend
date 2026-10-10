import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

/*
 * The central site's sign-up pages, mounted without Inertia: a form that only
 * records what was posted, and the shared props the pages read.
 */
const inertia = vi.hoisted(() => ({
    posted: [] as Array<{ url: string; data: Record<string, unknown> }>,
    app: {
        name: 'Oyosend',
        area: 'central',
        locale: 'en',
        fallbackLocale: 'en',
        locales: { en: 'English', bn: 'বাংলা' } as Record<string, string>,
        timezone: 'Asia/Dhaka',
        currency: 'BDT',
        numerals: 'latn' as 'latn' | 'beng',
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ name: 'InertiaHeadStub', setup: () => () => null }),
    usePage: () => ({ props: { app: inertia.app, errors: {} }, flash: {} }),
    router: { on: () => () => {}, visit: vi.fn(), post: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;

        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            transform(callback: typeof transform) {
                transform = callback;

                return form;
            },
            post(url: string) {
                const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));

                inertia.posted.push({ url, data: transform(data) });
            },
            reset(...fields: string[]) {
                fields.forEach((field) => {
                    form[field] = initial[field];
                });
            },
        });

        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();

        return `/${name}${query === '' ? '' : `?${query}`}`;
    },
    isCurrentRoute: () => false,
}));

import {
    billedInterval,
    cleanAddress,
    finishAddress,
    intervalsCharged,
    priceFor,
    pricesOf,
    suggestAddress,
    trialDaysFor,
    type PlanOffer,
} from '@/Core/Components/Central/signup';
import { setLocale } from '@/Core/i18n';
import { formatMoney } from '@/Core/lib/format';
import ConfirmRegistration from '@/Core/Pages/Central/ConfirmRegistration.vue';
import Register from '@/Core/Pages/Central/Register.vue';
import RegistrationSent from '@/Core/Pages/Central/RegistrationSent.vue';
import Welcome from '@/Core/Pages/Central/Welcome.vue';

function plan(overrides: Partial<PlanOffer> = {}): PlanOffer {
    return {
        id: '01hplan',
        key: 'starter',
        name: 'Starter',
        description: null,
        currency: 'BDT',
        monthlyAmount: 150000,
        yearlyAmount: 1500000,
        trialDays: 0,
        isFree: false,
        ...overrides,
    };
}

const free = plan({ key: 'free', name: 'Free', monthlyAmount: 0, yearlyAmount: null, isFree: true });
const starter = plan({ description: 'For a small team.' });
const pro = plan({ key: 'pro', name: 'Pro', monthlyAmount: 450000, yearlyAmount: 4500000, trialDays: 14 });
const yearlyOnly = plan({ key: 'annual', name: 'Annual', monthlyAmount: null, yearlyAmount: 990000 });

const money = (minorUnits: number): string => formatMoney(minorUnits, inertia.app, 'BDT');

/** A message key that reached the screen instead of its text. */
const RAW_KEY = /\b(register|central|billing|auth|common|errors)\.[A-Za-z]+\.[A-Za-z]+/;

const shell = { Backdrop: true, LocaleSwitch: true, ThemeToggle: true, Toaster: true };

beforeEach(() => {
    inertia.posted.length = 0;
    inertia.app.locale = 'en';
    setLocale('en');
});

afterEach(() => setLocale('en'));

describe('plans and prices', () => {
    it('prices a plan at the interval asked for, or the first one it is sold for', () => {
        expect(priceFor(starter, 'monthly')).toEqual({ amount: 150000, interval: 'monthly' });
        expect(priceFor(starter, 'yearly')).toEqual({ amount: 1500000, interval: 'yearly' });
        expect(priceFor(yearlyOnly, 'monthly')).toEqual({ amount: 990000, interval: 'yearly' });
        expect(billedInterval(free, 'yearly')).toBe('monthly');
    });

    it('has no price for a plan that costs nothing', () => {
        expect(priceFor(free, 'monthly')).toBeNull();
        expect(priceFor(free, 'yearly')).toBeNull();
        expect(pricesOf(free)).toEqual([]);
        expect(priceFor(plan({ monthlyAmount: null, yearlyAmount: null }), 'monthly')).toBeNull();
    });

    it('lists every price of a plan, monthly first', () => {
        expect(pricesOf(starter)).toEqual([
            { amount: 150000, interval: 'monthly' },
            { amount: 1500000, interval: 'yearly' },
        ]);
        expect(pricesOf(yearlyOnly)).toEqual([{ amount: 990000, interval: 'yearly' }]);
    });

    it('only offers a choice of interval where more than one is charged for', () => {
        expect(intervalsCharged([free, starter])).toEqual(['monthly', 'yearly']);
        expect(intervalsCharged([free, yearlyOnly])).toEqual(['yearly']);
        expect(intervalsCharged([free])).toEqual([]);
        expect(intervalsCharged([])).toEqual([]);
    });

    it('promises a trial only where something is paid afterwards', () => {
        expect(trialDaysFor(pro, 'monthly')).toBe(14);
        expect(trialDaysFor(plan({ ...free, trialDays: 14 }), 'monthly')).toBe(0);
        expect(trialDaysFor(starter, 'monthly')).toBe(0);
    });
});

describe('the workspace address', () => {
    it('is suggested from the company name', () => {
        expect(suggestAddress('Acme Trading Ltd.')).toBe('acme-trading-ltd');
        expect(suggestAddress('  Café & Crème  ')).toBe('cafe-creme');
        expect(suggestAddress('3M')).toBe('3m');
        expect(suggestAddress('a'.repeat(80))).toBe('a'.repeat(63));
        expect(suggestAddress(`${'a'.repeat(62)} b`)).toBe('a'.repeat(62));
    });

    it('is left to the person when the name has nothing an address can hold', () => {
        expect(suggestAddress('ঢাকা ট্রেডার্স')).toBe('');
        expect(suggestAddress('---')).toBe('');
        expect(suggestAddress('')).toBe('');
    });

    it('is kept to lower-case letters, digits and hyphens while it is typed', () => {
        expect(cleanAddress('Acme')).toBe('acme');
        expect(cleanAddress('acme trading')).toBe('acme-trading');
        expect(cleanAddress('acme_trading.co')).toBe('acme-trading-co');
        expect(cleanAddress('acme!@#')).toBe('acme');
        expect(cleanAddress('-acme')).toBe('acme');
        // More is probably coming.
        expect(cleanAddress('acme-')).toBe('acme-');
        expect(cleanAddress('a'.repeat(70))).toHaveLength(63);
    });

    it('cleans one character at a time, so the caret can be put back', () => {
        const typed = 'Acme  Trading!Co';

        for (let caret = 0; caret <= typed.length; caret++) {
            expect(cleanAddress(typed).startsWith(cleanAddress(typed.slice(0, caret)))).toBe(true);
        }
    });

    it('is sent without a hyphen left hanging', () => {
        expect(finishAddress('acme-')).toBe('acme');
        expect(finishAddress('Acme Trading--')).toBe('acme-trading');
    });
});

describe('the sign-up form', () => {
    const mountForm = (props: Partial<InstanceType<typeof Register>['$props']> = {}): VueWrapper =>
        mount(Register, {
            props: { domain: 'oyosend.test', plans: [free, starter, pro], selectedPlan: 'starter', needsApproval: false, ...props },
        });

    it('shows each plan with its price for the chosen interval', async () => {
        const wrapper = mountForm();
        const rows = wrapper.findAll('[role="radiogroup"][aria-labelledby] label');

        expect(rows).toHaveLength(3);
        expect(rows[0]!.text()).toContain('Free');
        expect(rows[1]!.text()).toContain(`${money(150000)} per month`);
        expect(rows[1]!.text()).toContain('For a small team.');
        expect(rows[2]!.text()).toContain(`${money(450000)} per month`);
        expect(rows[2]!.text()).toContain('14-day free trial');
        expect(rows[0]!.text()).not.toContain('trial');

        // The plan picked on the landing page is the one selected.
        expect(wrapper.find<HTMLInputElement>('input[type="radio"][value="starter"]').element.checked).toBe(true);

        await wrapper.findAll('[role="radio"]').find((button) => button.text() === 'Yearly')!.trigger('click');

        expect(rows[1]!.text()).toContain(`${money(1500000)} per year`);
        expect(rows[0]!.text()).toContain('Free');
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('says what happens about payment for the plan that is selected', async () => {
        const wrapper = mountForm();

        expect(wrapper.text()).toContain('The first invoice is issued when the workspace is created.');

        await wrapper.find('input[type="radio"][value="pro"]').setValue(true);
        expect(wrapper.text()).toContain('The trial starts when the workspace is created.');

        await wrapper.find('input[type="radio"][value="free"]').setValue(true);
        expect(wrapper.text()).not.toContain('No payment now.');
    });

    it('offers no choice of interval when only one is charged for', () => {
        const wrapper = mountForm({ plans: [free, yearlyOnly], selectedPlan: 'free' });

        expect(wrapper.findAll('[role="radio"]')).toHaveLength(0);
        expect(wrapper.text()).toContain(`${money(990000)} per year`);
    });

    it('has no plans to choose from when none are on offer, and posts none', async () => {
        const wrapper = mountForm({ plans: [], selectedPlan: null });

        expect(wrapper.find('input[type="radio"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Plan');

        await wrapper.find('form').trigger('submit');

        expect(inertia.posted[0]!.data).toMatchObject({ plan: null, interval: null });
    });

    it('suggests the address from the company name until the person types their own', async () => {
        const wrapper = mountForm();
        const company = wrapper.find<HTMLInputElement>('input[name="company"]');
        const address = wrapper.find<HTMLInputElement>('input[name="slug"]');

        await company.setValue('Acme Trading Ltd.');
        expect(address.element.value).toBe('acme-trading-ltd');

        await company.setValue('Acme Trading');
        expect(address.element.value).toBe('acme-trading');

        // Typed by hand: tidied as it goes, and no longer overwritten.
        address.element.value = 'My Shop_';
        await address.trigger('input');
        expect(address.element.value).toBe('my-shop-');

        await company.setValue('Something Else');
        expect(address.element.value).toBe('my-shop-');

        // Leaving the field drops the hyphen left hanging.
        await address.trigger('change');
        expect(address.element.value).toBe('my-shop');

        // Emptied: the suggestion takes over again.
        await address.setValue('');
        await company.setValue('Globex');
        expect(address.element.value).toBe('globex');
    });

    it('shows the domain the address belongs to', () => {
        expect(mountForm().find('label[for]').exists()).toBe(true);
        expect(mountForm().text()).toContain('.oyosend.test');
    });

    it('posts what was filled in, in the language of the interface by default', async () => {
        inertia.app.locale = 'bn';

        const wrapper = mountForm({ selectedPlan: 'pro' });

        await wrapper.find('input[name="name"]').setValue('Nadia Islam');
        await wrapper.find('input[name="email"]').setValue('nadia@example.test');
        await wrapper.find('input[name="password"]').setValue('a-good-password');
        await wrapper.find('input[name="password_confirmation"]').setValue('a-good-password');
        await wrapper.find('input[name="company"]').setValue('Acme Trading-');
        await wrapper.find('form').trigger('submit');

        expect(inertia.posted).toEqual([
            {
                url: '/central.register.store',
                data: {
                    name: 'Nadia Islam',
                    email: 'nadia@example.test',
                    password: 'a-good-password',
                    password_confirmation: 'a-good-password',
                    company: 'Acme Trading-',
                    slug: 'acme-trading',
                    locale: 'bn',
                    plan: 'pro',
                    interval: 'monthly',
                },
            },
        ]);
    });

    it('starts on the interval the selected plan is sold for', async () => {
        const wrapper = mountForm({ plans: [starter, yearlyOnly], selectedPlan: 'annual' });

        await wrapper.find('form').trigger('submit');

        expect(inertia.posted[0]!.data).toMatchObject({ plan: 'annual', interval: 'yearly' });
    });

    it('says that a workspace is reviewed first, only when it is', () => {
        const notice = 'New workspaces are reviewed before they can be used.';

        expect(mountForm({ needsApproval: true }).text()).toContain(notice);
        expect(mountForm().text()).not.toContain(notice);
    });

    it('links back to where an existing workspace can be found', () => {
        const link = mountForm()
            .findAll('a')
            .find((anchor) => anchor.text() === 'Find it and sign in');

        expect(link?.attributes('href')).toBe('/central.home');
    });

    it('is complete in Bengali', () => {
        setLocale('bn');

        const text = mountForm({ needsApproval: true }).text();

        expect(text).toContain('ওয়ার্কস্পেস তৈরি করুন'.normalize('NFC'));
        expect(text).toContain('প্রতি মাসে');
        expect(text).not.toMatch(RAW_KEY);
    });
});

describe('the "check your email" page', () => {
    it('says where the link went and how long it is valid', () => {
        const text = mount(RegistrationSent, { props: { email: 'nadia@example.test', expiresInHours: 24 } }).text();

        expect(text).toContain('nadia@example.test');
        expect(text).toContain('valid for 24 hours');
        expect(text).toContain('Nothing has been created yet.');
        expect(text).not.toMatch(RAW_KEY);
    });

    it('counts a single hour as one', () => {
        expect(mount(RegistrationSent, { props: { email: 'nadia@example.test', expiresInHours: 1 } }).text()).toContain('valid for 1 hour.');
    });

    it('offers to sign up again', () => {
        const hrefs = mount(RegistrationSent, { props: { email: 'nadia@example.test', expiresInHours: 24 } })
            .findAll('a')
            .map((anchor) => anchor.attributes('href'));

        expect(hrefs).toEqual(['/central.register', '/central.home']);
    });

    it('is complete in Bengali', () => {
        setLocale('bn');

        expect(mount(RegistrationSent, { props: { email: 'nadia@example.test', expiresInHours: 24 } }).text()).not.toMatch(RAW_KEY);
    });
});

describe('the page the emailed link opens', () => {
    const outcome = { token: null, company: null, email: null, address: null, plan: null, loginUrl: null, registrationOpen: true };

    it('shows what is about to be created and posts the token back', async () => {
        const wrapper = mount(ConfirmRegistration, {
            props: {
                ...outcome,
                state: 'confirm',
                token: 't'.repeat(64),
                company: 'Acme Trading',
                email: 'nadia@example.test',
                address: 'acme-trading.oyosend.test',
                plan: 'Pro',
            },
        });

        expect(wrapper.find('h1').text()).toBe('Create Acme Trading');
        expect(wrapper.findAll('dt').map((term) => term.text())).toEqual(['Company', 'Workspace address', 'Owner', 'Plan']);
        expect(wrapper.findAll('dd').map((value) => value.text())).toEqual(['Acme Trading', 'acme-trading.oyosend.test', 'nadia@example.test', 'Pro']);
        expect(wrapper.findAll('button')).toHaveLength(1);
        expect(wrapper.find('a').exists()).toBe(false);

        await wrapper.find('form').trigger('submit');

        expect(inertia.posted).toEqual([{ url: `/central.register.complete?token=${'t'.repeat(64)}`, data: {} }]);
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('leaves the plan out when there is none', () => {
        const wrapper = mount(ConfirmRegistration, {
            props: { ...outcome, state: 'confirm', token: 't'.repeat(64), company: 'Acme Trading', email: 'nadia@example.test', address: 'acme.oyosend.test' },
        });

        expect(wrapper.findAll('dt').map((term) => term.text())).toEqual(['Company', 'Workspace address', 'Owner']);
    });

    it('points at the sign-in page of a workspace that is ready', () => {
        const wrapper = mount(ConfirmRegistration, {
            props: {
                ...outcome,
                state: 'ready',
                company: 'Acme Trading',
                address: 'acme-trading.oyosend.test',
                loginUrl: 'https://acme-trading.oyosend.test/login',
            },
        });

        expect(wrapper.find('h1').text()).toBe('Acme Trading is ready');
        expect(wrapper.text()).toContain('its password has not changed');
        expect(wrapper.findAll('a').map((anchor) => [anchor.text(), anchor.attributes('href')])).toEqual([
            ['Go to sign in', 'https://acme-trading.oyosend.test/login'],
        ]);
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('offers no way in to a workspace that waits for approval', () => {
        const wrapper = mount(ConfirmRegistration, {
            props: { ...outcome, state: 'pending', company: 'Acme Trading', address: 'acme-trading.oyosend.test' },
        });

        expect(wrapper.find('h1').text()).toBe('Acme Trading is waiting for approval');
        expect(wrapper.text()).toContain('We will email you when it is approved.');
        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('offers to sign up again when the link cannot be used', () => {
        const wrapper = mount(ConfirmRegistration, { props: { ...outcome, state: 'invalid' } });

        expect(wrapper.find('h1').text()).toBe('This link can no longer be used');
        expect(wrapper.findAll('a').map((anchor) => anchor.attributes('href'))).toEqual(['/central.register', '/central.home']);
        expect(wrapper.find('dl').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('does not offer a sign-up that is closed', () => {
        const wrapper = mount(ConfirmRegistration, { props: { ...outcome, state: 'invalid', registrationOpen: false } });

        expect(wrapper.findAll('a').map((anchor) => anchor.attributes('href'))).toEqual(['/central.home']);
    });

    it('is complete in Bengali in every state', () => {
        setLocale('bn');

        for (const state of ['confirm', 'ready', 'pending', 'invalid'] as const) {
            const wrapper = mount(ConfirmRegistration, {
                props: {
                    ...outcome,
                    state,
                    token: state === 'confirm' ? 't'.repeat(64) : null,
                    company: state === 'invalid' ? null : 'Acme Trading',
                    address: state === 'invalid' ? null : 'acme-trading.oyosend.test',
                    email: state === 'confirm' ? 'nadia@example.test' : null,
                    plan: state === 'confirm' ? 'Pro' : null,
                    loginUrl: state === 'ready' ? 'https://acme-trading.oyosend.test/login' : null,
                },
            });

            expect(wrapper.text()).not.toMatch(RAW_KEY);
            expect(wrapper.find('h1').text()).not.toBe('');
        }
    });
});

describe('the landing page', () => {
    const mountPage = (props: Partial<InstanceType<typeof Welcome>['$props']> = {}): VueWrapper =>
        mount(Welcome, {
            props: { domain: 'oyosend.test', registrationOpen: true, plans: [free, starter, pro, yearlyOnly], ...props },
            global: { stubs: shell },
        });

    it('looks as it always did while sign-up is closed', () => {
        const wrapper = mountPage({ registrationOpen: false, plans: [] });

        expect(wrapper.findAll('a')).toHaveLength(0);
        expect(wrapper.findAll('section')).toHaveLength(1);
        expect(wrapper.text()).not.toContain('Create a workspace');
        expect(wrapper.text()).not.toContain('Plans');

        // Finding a workspace is then the one thing to do here.
        expect(wrapper.find('button[type="submit"]').classes()).toContain('bg-brand');
    });

    it('offers to create a workspace while sign-up is open', () => {
        const wrapper = mountPage({ plans: [] });
        const create = wrapper.findAll('a').find((anchor) => anchor.text() === 'Create a workspace');

        expect(create?.attributes('href')).toBe('/central.register');
        expect(create?.classes()).toContain('bg-brand');

        // The finder stays, as the quieter of the two actions.
        expect(wrapper.find('input[name="workspace"]').exists()).toBe(true);
        expect(wrapper.find('button[type="submit"]').classes()).not.toContain('bg-brand');
        expect(wrapper.findAll('section')).toHaveLength(1);
    });

    it('prices every plan on offer and sends each to the sign-up form', () => {
        const wrapper = mountPage();
        const plans = wrapper.findAll('section')[1]!.findAll('li');

        expect(plans.map((item) => item.find('h3').text())).toEqual(['Free', 'Starter', 'Pro', 'Annual']);

        expect(plans[0]!.text()).toContain('Free');
        expect(plans[0]!.text()).not.toContain('per month');

        // The monthly price is the figure; the yearly one follows it.
        expect(plans[1]!.findAll('p')[0]!.findAll('span').map((part) => part.text())).toEqual([money(150000), 'per month']);
        expect(plans[1]!.findAll('p')[1]!.text()).toBe(`${money(1500000)} per year`);
        expect(plans[1]!.text()).toContain('For a small team.');
        expect(plans[1]!.text()).not.toContain('trial');

        expect(plans[2]!.text()).toContain('14-day free trial');

        expect(plans[3]!.findAll('p')[0]!.findAll('span').map((part) => part.text())).toEqual([money(990000), 'per year']);
        expect(plans[3]!.text()).not.toContain('per month');

        expect(plans.map((item) => item.find('a').attributes('href'))).toEqual([
            '/central.register?plan=free',
            '/central.register?plan=starter',
            '/central.register?plan=pro',
            '/central.register?plan=annual',
        ]);
        expect(plans[1]!.find('a').attributes('aria-label')).toBe('Choose the Starter plan');
        expect(wrapper.text()).not.toMatch(RAW_KEY);
    });

    it('is complete in Bengali', () => {
        setLocale('bn');

        expect(mountPage().text()).not.toMatch(RAW_KEY);
    });
});
