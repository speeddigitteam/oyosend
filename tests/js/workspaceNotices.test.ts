import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { SharedProps, SubscriptionNotice as Subscription } from '@/Core/types';

/*
 * The two notices the workspace layout carries above every page: the
 * subscription notice, which must stay quiet unless something needs
 * attention, and the support-session banner, which must never be quiet.
 */
const { page, post, onBillingPage } = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown>, url: '/' },
    post: vi.fn(),
    onBillingPage: { value: false },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    usePage: () => page,
    router: { post },
}));

// The real helper needs the route table the server prints into the page.
vi.mock('@/Core/lib/route', () => ({
    route: (name: string) => `/${name}`,
    isCurrentRoute: (pattern: string) => pattern === 'core.billing.*' && onBillingPage.value,
}));

import SubscriptionNotice from '@/Core/Components/Shell/SubscriptionNotice.vue';
import SupportSessionBanner from '@/Core/Components/Shell/SupportSessionBanner.vue';
import { formatDate, type FormatContext } from '@/Core/lib/format';

const NOW = new Date('2026-03-18T03:00:00Z');
const context: FormatContext = { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' };

function share(props: Partial<SharedProps>, permissions: string[] = []): void {
    Object.assign(
        page,
        reactive({
            url: '/',
            props: {
                app: { name: 'Oyosend', area: 'tenant', fallbackLocale: 'en', locales: { en: 'English' }, ...context },
                auth: { user: null, permissions },
                subscription: null,
                supportSession: null,
                ...props,
            },
        }),
    );
}

function subscription(override: Partial<Subscription> = {}): Subscription {
    return {
        status: 'active',
        plan: 'Starter',
        locked: false,
        trialEndsAt: null,
        graceEndsAt: null,
        cancelAtPeriodEnd: false,
        periodEnd: '2026-04-10T09:00:00+00:00',
        ...override,
    };
}

beforeEach(() => {
    // Only the clock: the components do not wait on timers.
    vi.useFakeTimers({ toFake: ['Date'], now: NOW });
    window.sessionStorage.clear();
    onBillingPage.value = false;
    post.mockReset();
});

afterEach(() => vi.useRealTimers());

describe('SubscriptionNotice', () => {
    const BILLING = ['core.billing.view'];

    it('says nothing when nothing needs attention', () => {
        const quiet: Array<Subscription | null> = [
            null,
            subscription(),
            // A free plan: no period at all.
            subscription({ periodEnd: null }),
            // A trial with more than a week to go.
            subscription({ status: 'trialing', periodEnd: null, trialEndsAt: '2026-03-25T03:00:01Z' }),
        ];

        for (const state of quiet) {
            share({ subscription: state }, BILLING);

            expect(mount(SubscriptionNotice).find('section').exists()).toBe(false);
        }
    });

    it('announces a trial in its last week, and lets it be put away for the session', async () => {
        const trialEndsAt = '2026-03-24T09:00:00+00:00';

        share({ subscription: subscription({ status: 'trialing', plan: 'Pro', periodEnd: null, trialEndsAt }) }, BILLING);

        const wrapper = mount(SubscriptionNotice);

        expect(wrapper.text()).toContain(`The trial of the Pro plan ends on ${formatDate(trialEndsAt, context)}.`);
        expect(wrapper.get('a').attributes('href')).toBe('/core.billing.index');

        await wrapper.get('button').trigger('click');

        expect(wrapper.find('section').exists()).toBe(false);
        // Still put away on the next page of the same browser session ...
        expect(mount(SubscriptionNotice).find('section').exists()).toBe(false);

        // ... but a different notice is not covered by that.
        share({ subscription: subscription({ status: 'trialing', plan: 'Pro', periodEnd: null, trialEndsAt: '2026-03-20T09:00:00+00:00' }) }, BILLING);

        expect(mount(SubscriptionNotice).find('section').exists()).toBe(true);
    });

    it('says so when a trial is over but nothing has happened to it yet', () => {
        share({ subscription: subscription({ status: 'trialing', plan: 'Pro', periodEnd: null, trialEndsAt: '2026-03-18T02:00:00Z' }) });

        expect(mount(SubscriptionNotice).text()).toContain('The trial of the Pro plan has ended.');
    });

    it('says when an overdue payment will lock the workspace, and cannot be put away', () => {
        const graceEndsAt = '2026-03-24T09:00:00+00:00';
        const pastDue = subscription({ status: 'past_due', graceEndsAt });

        share({ subscription: pastDue }, BILLING);

        const forPayer = mount(SubscriptionNotice);

        expect(forPayer.text()).toContain(`the workspace will be locked from ${formatDate(graceEndsAt, context, 'datetime')}`);
        expect(forPayer.get('a').attributes('href')).toBe('/core.billing.index');
        expect(forPayer.find('button').exists()).toBe(false);

        // Someone who cannot open billing is told whom to tell, and is given no link.
        share({ subscription: pastDue });

        const forMember = mount(SubscriptionNotice);

        expect(forMember.text()).toContain('Let the workspace owner know.');
        expect(forMember.find('a').exists()).toBe(false);
        expect(forMember.find('button').exists()).toBe(false);
    });

    it('still warns about an overdue payment that carries no date', () => {
        share({ subscription: subscription({ status: 'past_due' }) });

        expect(mount(SubscriptionNotice).text()).toContain('A payment for the Starter plan is overdue.');
    });

    it('announces a plan that is set to end', () => {
        share({ subscription: subscription({ cancelAtPeriodEnd: true }) }, BILLING);

        expect(mount(SubscriptionNotice).text()).toContain(
            `The Starter plan is set to end on ${formatDate('2026-04-10T09:00:00+00:00', context)}.`,
        );

        // A canceled trial has no paid period: it ends with the trial, however far off that is.
        const trialEndsAt = '2026-04-20T09:00:00+00:00';

        share({ subscription: subscription({ status: 'trialing', cancelAtPeriodEnd: true, periodEnd: null, trialEndsAt }) });

        expect(mount(SubscriptionNotice).text()).toContain(`set to end on ${formatDate(trialEndsAt, context)}`);
    });

    it('says the workspace is locked, to each reader in their own terms', () => {
        const locked = subscription({ status: 'expired', locked: true, periodEnd: '2026-03-10T09:00:00+00:00' });

        share({ subscription: locked }, BILLING);

        const forPayer = mount(SubscriptionNotice);

        expect(forPayer.text()).toContain('This workspace is locked because its Starter plan has ended.');
        expect(forPayer.text()).toContain('Choose a plan in billing to open it again.');
        expect(forPayer.find('a').exists()).toBe(true);
        expect(forPayer.find('button').exists()).toBe(false);

        share({ subscription: locked });

        const forMember = mount(SubscriptionNotice);

        expect(forMember.text()).toContain('The workspace owner can restore it from billing.');
        expect(forMember.find('a').exists()).toBe(false);
    });

    it('puts being locked before anything else it could say', () => {
        share({
            subscription: subscription({
                status: 'canceled',
                locked: true,
                cancelAtPeriodEnd: true,
                trialEndsAt: '2026-03-19T09:00:00+00:00',
                graceEndsAt: '2026-03-24T09:00:00+00:00',
            }),
        });

        const text = mount(SubscriptionNotice).text();

        expect(text).toContain('is locked');
        expect(text).not.toContain('set to end');
        expect(text).not.toContain('trial');
    });

    it('does not link to billing from billing', () => {
        onBillingPage.value = true;

        share({ subscription: subscription({ status: 'past_due', graceEndsAt: '2026-03-24T09:00:00+00:00' }) }, BILLING);

        const wrapper = mount(SubscriptionNotice);

        expect(wrapper.find('section').exists()).toBe(true);
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('is written in Bengali too, with the plan and the date in place', async () => {
        const { i18n } = await import('@/Core/i18n');
        const trialEndsAt = '2026-03-24T09:00:00+00:00';

        share({ subscription: subscription({ status: 'trialing', plan: 'Pro', periodEnd: null, trialEndsAt }) });
        i18n.global.locale.value = 'bn';

        try {
            const text = mount(SubscriptionNotice).text();

            expect(text).toContain('Pro');
            expect(text).toContain(formatDate(trialEndsAt, context));
            expect(text).not.toContain('{');
        } finally {
            i18n.global.locale.value = 'en';
        }
    });
});

describe('SupportSessionBanner', () => {
    const session = { by: 'operator@oyosend.example', expiresAt: '2026-03-18T03:30:00+00:00' };

    it('is not there in an ordinary session', () => {
        share({ supportSession: null });

        expect(mount(SupportSessionBanner).find('section').exists()).toBe(false);
    });

    it('says who opened the session and when it ends', () => {
        share({ supportSession: session });

        const wrapper = mount(SupportSessionBanner);

        expect(wrapper.get('section').attributes('aria-label')).toBe('Support session');
        expect(wrapper.text()).toContain('Opened by operator@oyosend.example');
        expect(wrapper.text()).toContain(`Ends at ${formatDate(session.expiresAt, context, 'time')}`);
        expect(wrapper.get('time').attributes('datetime')).toBe(session.expiresAt);
    });

    it('offers one thing only: ending the session', async () => {
        share({ supportSession: session });

        const wrapper = mount(SupportSessionBanner);
        const buttons = wrapper.findAll('button');

        // No way to put the banner away.
        expect(buttons).toHaveLength(1);
        expect(buttons[0]!.text()).toBe('End session');

        await buttons[0]!.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0]![0]).toBe('/auth.impersonate.stop');
        // Busy until the server has answered, so it cannot be sent twice.
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    });
});
