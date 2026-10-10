import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

/*
 * The billing pages of a workspace and the invoice pages of the Super Admin
 * panel, mounted with the real catalogue and components. Inertia and the
 * route table are stood in for: visits and submitted forms are recorded
 * instead of sent.
 */
interface Visit {
    method: string;
    url: string;
    data?: unknown;
}

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: [] as string[], abilities: [] as string[] },
            tenant: { id: 'tenant-1', name: 'Acme Trading', slug: 'acme', logoUrl: null },
        },
        flash: {},
    },
    visits: [] as Visit[],
    // Whether a visit answers at once. Off: it stays in flight, as on the way to a payment provider.
    answers: false,
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
        post: (url: string, data: unknown, options?: { onSuccess?: () => void; onFinish?: () => void }) => {
            inertia.visits.push({ method: 'post', url, data });

            if (inertia.answers) {
                options?.onSuccess?.();
                options?.onFinish?.();
            }
        },
        reload: () => inertia.visits.push({ method: 'reload', url: '' }),
    },
    // Enough of a form to see what would be sent.
    useForm: (initial: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {},
            processing: false,
            clearErrors: () => {},
            reset: () => Object.assign(form, initial),
            post: (url: string) => {
                const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));

                inertia.sent.push({ method: 'post', url, data });
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

import { planChangeOutcome, planInterval, type OfferedPlan } from '@/Core/Components/Billing/planChange';
import type {
    InvoiceDocumentData,
    InvoiceSummary,
    PaymentSummary,
    SubscriptionSummary,
    UsageEntry,
} from '@/Core/Components/Billing/types';
import { setLocale } from '@/Core/i18n';
import { formatMoney } from '@/Core/lib/format';
import BillingIndex from '@/Core/Pages/Billing/Index.vue';
import WorkspaceInvoice from '@/Core/Pages/Billing/Invoice.vue';
import PanelInvoice from '@/Platform/Pages/Billing/Invoice.vue';
import PanelInvoices from '@/Platform/Pages/Billing/Invoices.vue';

const mounted: VueWrapper[] = [];

function track<T extends VueWrapper>(wrapper: T): T {
    mounted.push(wrapper);

    return wrapper;
}

/** Money as the pages write it; the exact characters are the formatter's business. */
function money(amount: number, currency = 'BDT'): string {
    return formatMoney(amount, { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, currency);
}

function buttonNamed(wrapper: VueWrapper, label: string): DOMWrapper<HTMLButtonElement> | undefined {
    return wrapper.findAll<HTMLButtonElement>('button').find((candidate) => candidate.text() === label);
}

function dialog(): Element | null {
    return document.body.querySelector('[role="dialog"]');
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

async function fill(name: string, value: string): Promise<void> {
    const element = document.body.querySelector<HTMLInputElement>(`[role="dialog"] [name="${name}"]`);

    if (!element) {
        throw new Error(`The open dialog has no field named [${name}].`);
    }

    element.value = value;
    element.dispatchEvent(new Event(element.tagName === 'SELECT' ? 'change' : 'input'));

    await flushPromises();
}

beforeEach(() => {
    // Inside the paid period of the subscription below: what a change of plan does depends on the day.
    vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-03-12T09:00:00Z') });
    inertia.visits.length = 0;
    inertia.answers = false;
    inertia.sent.length = 0;
    inertia.page.props.auth.abilities = [];
    setLocale('en');
});

afterEach(() => {
    vi.useRealTimers();
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

/*
 * Records as App\Core\Billing\Support\BillingPresenter sends them.
 */
function plan(overrides: Partial<OfferedPlan> = {}): OfferedPlan {
    return {
        id: '01HPLANSTARTER000000000000',
        key: 'starter',
        name: 'Starter',
        description: null,
        currency: 'BDT',
        monthlyAmount: 150000,
        yearlyAmount: 1500000,
        trialDays: 0,
        modules: [],
        limits: {},
        isFree: false,
        isPublic: true,
        isActive: true,
        sortOrder: 0,
        current: false,
        ...overrides,
    };
}

const free = plan({ id: '01HPLANFREE000000000000000', key: 'free', name: 'Free', monthlyAmount: 0, yearlyAmount: null, isFree: true });
const starter = plan({ limits: { 'core.members': 5 } });
const business = plan({ id: '01HPLANBUSINESS00000000000', key: 'business', name: 'Business', monthlyAmount: 450000, yearlyAmount: 4500000 });

function subscription(overrides: Partial<SubscriptionSummary> = {}): SubscriptionSummary {
    return {
        status: 'active',
        plan: { id: starter.id, key: 'starter', name: 'Starter' },
        interval: 'monthly',
        amount: 150000,
        currency: 'BDT',
        isFree: false,
        locked: false,
        trialEndsAt: null,
        periodStart: '2026-03-10T09:00:00+00:00',
        periodEnd: '2026-04-10T09:00:00+00:00',
        graceEndsAt: null,
        cancelAtPeriodEnd: false,
        endedAt: null,
        pending: null,
        ...overrides,
    };
}

function invoice(overrides: Partial<InvoiceSummary> = {}): InvoiceSummary {
    return {
        id: '01HINVOICE0000000000000001',
        number: 'INV-2026-000001',
        kind: 'subscription',
        status: 'open',
        overdue: false,
        currency: 'BDT',
        subtotalAmount: 150000,
        creditAmount: 0,
        totalAmount: 150000,
        interval: 'monthly',
        periodStart: '2026-03-10T09:00:00+00:00',
        periodEnd: '2026-04-10T09:00:00+00:00',
        issuedAt: '2026-03-10T09:00:00+00:00',
        dueAt: '2026-03-17T09:00:00+00:00',
        paidAt: null,
        voidedAt: null,
        ...overrides,
    };
}

function invoiceDocument(overrides: Partial<InvoiceDocumentData> = {}): InvoiceDocumentData {
    return {
        ...invoice(),
        lines: [{ type: 'plan', plan: 'Starter', interval: 'monthly', amount: 150000 }],
        billedTo: {
            name: 'Acme Trading Limited',
            email: 'accounts@acme.test',
            phone: null,
            address: '12 Lake Road',
            city: 'Dhaka',
            postal_code: '1212',
            country: 'BD',
            tax_id: null,
        },
        issuer: { name: 'Oyosend Ltd', address: 'House 1\nDhaka', email: 'billing@oyosend.test', tax_id: null },
        ...overrides,
    };
}

function payment(overrides: Partial<PaymentSummary> = {}): PaymentSummary {
    return {
        id: '01HPAYMENT0000000000000001',
        reference: '01HREFERENCE00000000000001',
        gateway: 'testpay',
        method: null,
        status: 'pending',
        amount: 150000,
        currency: 'BDT',
        paidAt: null,
        failureReason: null,
        createdAt: '2026-03-10T09:00:00+00:00',
        ...overrides,
    };
}

const seats: UsageEntry = { key: 'core.members', kind: 'gauge', limit: null, used: 3 };

/*
 * What choosing a plan will do: one case per path through
 * SubscriptionManager::changePlan().
 */
describe('planChangeOutcome', () => {
    const now = new Date('2026-03-21T09:00:00+00:00');
    const on = (target: OfferedPlan): OfferedPlan => ({ ...target, current: true });
    const outcome = (current: SubscriptionSummary | null, target: OfferedPlan, interval: 'monthly' | 'yearly' = 'monthly') =>
        planChangeOutcome(current, target, interval, now);

    it('puts a workspace without a subscription on a free plan at once, and on a paid one with an invoice to pay', () => {
        expect(outcome(null, free)).toEqual({ kind: 'freeNow' });
        expect(outcome(null, starter)).toEqual({ kind: 'first' });
    });

    it('has nothing to do for the plan and interval the workspace is already on', () => {
        expect(outcome(subscription(), on(starter))).toEqual({ kind: 'current' });
        expect(outcome(subscription({ status: 'past_due' }), on(starter))).toEqual({ kind: 'current' });
        expect(outcome(subscription({ isFree: true, amount: 0, periodEnd: null }), on(free))).toEqual({ kind: 'current' });
    });

    it('cannot offer a plan that is not sold for the interval, or not sold any more', () => {
        expect(outcome(null, plan({ yearlyAmount: null }), 'yearly')).toEqual({ kind: 'unavailable' });
        expect(outcome(subscription(), on({ ...starter, isActive: false }), 'yearly')).toEqual({ kind: 'unavailable' });
    });

    it('moves up with an invoice and down at the end of the paid period', () => {
        expect(outcome(subscription(), business)).toEqual({ kind: 'upgrade' });
        expect(outcome(subscription({ plan: { id: business.id, key: 'business', name: 'Business' }, amount: 450000 }), starter)).toEqual({
            kind: 'later',
            at: '2026-04-10T09:00:00+00:00',
        });
    });

    it('compares prices per month when the interval changes', () => {
        // 15,000 a year is less per month than 1,500 a month; 4,500 a month is more than 15,000 a year.
        expect(outcome(subscription(), on(starter), 'yearly')).toEqual({ kind: 'later', at: '2026-04-10T09:00:00+00:00' });
        expect(outcome(subscription({ interval: 'yearly', amount: 1500000, periodEnd: '2027-03-10T09:00:00+00:00' }), business)).toEqual({
            kind: 'upgrade',
        });
    });

    it('lets a paid period run out before a free plan starts, and switches at once otherwise', () => {
        expect(outcome(subscription(), free)).toEqual({ kind: 'freeLater', at: '2026-04-10T09:00:00+00:00' });
        expect(outcome(subscription({ status: 'trialing', periodEnd: null, trialEndsAt: '2026-03-24T09:00:00+00:00' }), free)).toEqual({
            kind: 'freeNow',
        });
        expect(outcome(subscription({ status: 'past_due' }), free)).toEqual({ kind: 'freeNow' });
        expect(outcome(subscription({ status: 'expired', locked: true }), free)).toEqual({ kind: 'freeNow' });
    });

    it('changes plan freely during a trial', () => {
        const trial = subscription({ status: 'trialing', periodStart: null, periodEnd: null, trialEndsAt: '2026-03-24T09:00:00+00:00' });

        expect(outcome(trial, business)).toEqual({ kind: 'trial', at: '2026-03-24T09:00:00+00:00' });
        expect(outcome(trial, on(starter))).toEqual({ kind: 'current' });
        expect(outcome({ ...trial, cancelAtPeriodEnd: true }, on(starter))).toEqual({ kind: 'keepEnding' });
    });

    it('undoes a cancellation or a scheduled change when the current plan is chosen again', () => {
        expect(outcome(subscription({ cancelAtPeriodEnd: true }), on(starter))).toEqual({ kind: 'keepEnding' });
        expect(outcome(subscription({ pending: { plan: 'Free', interval: 'monthly' } }), on(starter))).toEqual({ kind: 'keepPending' });
    });

    it('starts with a paid invoice when a payment is overdue, the subscription has ended, or the plan was free', () => {
        expect(outcome(subscription({ status: 'past_due' }), business)).toEqual({ kind: 'replace' });
        expect(outcome(subscription({ status: 'canceled', locked: true }), business)).toEqual({ kind: 'reopen' });
        // The plan the workspace was on can be taken again once it has ended.
        expect(outcome(subscription({ status: 'expired', locked: true }), on(starter))).toEqual({ kind: 'reopen' });
        expect(outcome(subscription({ isFree: true, amount: 0, periodEnd: null }), starter)).toEqual({ kind: 'invoice' });
        // A paid period that is over, before the billing run has noticed.
        expect(outcome(subscription({ periodEnd: '2026-03-20T09:00:00+00:00' }), business)).toEqual({ kind: 'invoice' });
    });

    it('asks for a free plan under the interval it is listed for', () => {
        expect(planInterval(free, 'yearly')).toBe('monthly');
        expect(planInterval(free, 'monthly')).toBe('monthly');
        expect(planInterval(starter, 'yearly')).toBe('yearly');
        // Not sold yearly, and not free: shown as such rather than quietly billed monthly.
        expect(planInterval(plan({ yearlyAmount: null }), 'yearly')).toBe('yearly');
    });
});

/*
 * The billing page of a workspace.
 */
describe('billing page', () => {
    const props = (overrides: Record<string, unknown> = {}) => ({
        subscription: null,
        plans: [free, starter, business],
        usage: [seats],
        invoices: [],
        openInvoice: null,
        gateways: [],
        offlineInstructions: null,
        returnedPayment: null,
        canManage: true,
        ...overrides,
    });

    const page = (overrides: Record<string, unknown> = {}) => track(mount(BillingIndex, { props: props(overrides), attachTo: document.body }));

    it('says a workspace has no plan and lets whoever manages billing choose one', () => {
        const wrapper = page();

        expect(wrapper.text()).toContain('No plan');
        expect(wrapper.text()).toContain('This workspace is not on a plan. Choose one below.');
        expect(wrapper.text()).toContain('No invoices yet');
        expect(wrapper.findAll('button').filter((button) => button.text() === 'Choose plan')).toHaveLength(3);
        // Each of them says which plan it chooses.
        expect(wrapper.find('button[aria-label="Choose the Business plan"]').exists()).toBe(true);
        expect(buttonNamed(wrapper, 'Cancel subscription')).toBeUndefined();
    });

    it('shows someone who may only look everything but the controls', () => {
        const wrapper = page({ canManage: false, subscription: subscription(), plans: [free, { ...starter, current: true }, business] });

        expect(wrapper.text()).toContain('Starter');
        expect(wrapper.text()).toContain('Renews on Apr 10, 2026.');
        expect(wrapper.text()).toContain('You can see billing but not change it.');
        expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Monthly', 'Yearly']);
    });

    it('writes the price per interval and each limit of a plan', () => {
        const wrapper = page();
        const cards = wrapper.findAll('article');

        expect(cards.map((card) => card.find('h3').text())).toEqual(['Free', 'Starter', 'Business']);
        expect(cards[0]!.text()).toContain('Free');
        // Price and interval are two elements: no space between them in the text.
        expect(cards[1]!.text()).toContain(`${money(150000)}per month`);
        expect(cards[1]!.text()).toMatch(/Members\s*5/);
        // A limit the plan does not name is not a limit.
        expect(cards[2]!.text()).toMatch(/Members\s*Unlimited/);
    });

    it('says so when a plan is not sold for the interval shown', async () => {
        const monthlyOnly = plan({ id: '01HPLANLITE000000000000000', key: 'lite', name: 'Lite', yearlyAmount: null });
        const wrapper = page({ plans: [monthlyOnly, business] });

        await wrapper.findAll('[role="radio"]').find((option) => option.text() === 'Yearly')!.trigger('click');

        const [lite, other] = wrapper.findAll('article');

        expect(lite!.text()).toContain('Not sold yearly');
        expect(lite!.text()).toContain('This plan is billed monthly only.');
        expect(lite!.find('button').exists()).toBe(false);
        expect(other!.text()).toContain(`${money(4500000)}per year`);
    });

    it('has no interval to switch when every plan is sold for one only', () => {
        const wrapper = page({ plans: [free, plan({ monthlyAmount: null })] });

        expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false);
        // The only interval there is, whatever the page would have started on.
        expect(wrapper.text()).toContain(`${money(1500000)}per year`);
    });

    it('explains what will happen before it changes the plan', async () => {
        const wrapper = page({
            subscription: subscription(),
            plans: [free, { ...starter, current: true }, business],
            openInvoice: invoice({ kind: 'renewal', number: 'INV-2026-000007' }),
        });

        await wrapper.find('button[aria-label="Choose the Business plan"]').trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain('Change to the Business plan?');
        expect(dialog()?.textContent).toContain(`${money(450000)} per month`);
        expect(dialog()?.textContent).toContain(`You get an invoice for ${money(450000)}, less a credit for the unused time`);
        expect(dialog()?.textContent).toContain('Invoice INV-2026-000007, which is still unpaid, is withdrawn.');
        // Nothing is sent until it is confirmed.
        expect(inertia.visits).toEqual([]);

        dialogButton('Change plan').click();
        await flushPromises();

        expect(inertia.visits).toEqual([
            { method: 'post', url: '/core.billing.subscription.change', data: { plan_id: business.id, interval: 'monthly' } },
        ]);
    });

    it('asks for the interval that is shown, and for a free plan under the one it has', async () => {
        // Two changes in a row: the first has to be answered before the second can be asked for.
        inertia.answers = true;

        const wrapper = page();

        await wrapper.findAll('[role="radio"]').find((option) => option.text() === 'Yearly')!.trigger('click');
        await wrapper.find('button[aria-label="Choose the Starter plan"]').trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain(`${money(1500000)} per year`);
        expect(dialog()?.textContent).toContain('If that invoice stays unpaid past the grace period, the workspace is locked');

        dialogButton('Change plan').click();
        await flushPromises();

        await wrapper.find('button[aria-label="Choose the Free plan"]').trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain('There is nothing to pay.');

        dialogButton('Change plan').click();
        await flushPromises();

        expect(inertia.visits.map((visit) => visit.data)).toEqual([
            { plan_id: starter.id, interval: 'yearly' },
            { plan_id: free.id, interval: 'monthly' },
        ]);
    });

    it('marks the current plan and offers the other way of billing it', () => {
        const wrapper = page({ subscription: subscription(), plans: [{ ...starter, current: true }, business] });
        const current = wrapper.findAll('article')[0]!;

        expect(current.text()).toContain('Current plan');
        expect(current.text()).toContain('The workspace is on this plan.');
        expect(current.find('button').exists()).toBe(false);
    });

    it('shows a plan that is no longer sold without a way to choose it', async () => {
        const retired = { ...starter, current: true, isActive: false };
        const wrapper = page({ subscription: subscription(), plans: [retired, business] });

        await wrapper.findAll('[role="radio"]').find((option) => option.text() === 'Yearly')!.trigger('click');

        const card = wrapper.findAll('article')[0]!;

        expect(card.text()).toContain('No longer sold');
        expect(card.find('button').exists()).toBe(false);
    });

    it('offers to cancel only what can be cancelled, and to resume only what is set to end', () => {
        const actions = (current: SubscriptionSummary | null): string[] =>
            page({ subscription: current })
                .findAll('button')
                .map((button) => button.text())
                .filter((label) => ['Cancel subscription', 'Keep the subscription'].includes(label));

        expect(actions(subscription())).toEqual(['Cancel subscription']);
        expect(actions(subscription({ status: 'trialing', periodEnd: null, trialEndsAt: '2026-03-24T09:00:00+00:00' }))).toEqual(['Cancel subscription']);
        expect(actions(subscription({ status: 'past_due', graceEndsAt: '2026-04-17T09:00:00+00:00' }))).toEqual(['Cancel subscription']);
        expect(actions(subscription({ cancelAtPeriodEnd: true }))).toEqual(['Keep the subscription']);
        // Nothing to cancel on a free plan, and nothing left to cancel once it has ended.
        expect(actions(subscription({ isFree: true, amount: 0, periodEnd: null }))).toEqual([]);
        expect(actions(subscription({ status: 'canceled', locked: true, endedAt: '2026-04-10T09:00:00+00:00' }))).toEqual([]);
        expect(actions(null)).toEqual([]);
    });

    it('says that cancelling runs to the end of the paid period, and asks before doing it', async () => {
        const wrapper = page({ subscription: subscription() });

        await buttonNamed(wrapper, 'Cancel subscription')!.trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain('The workspace keeps working until Apr 10, 2026, the end of the period you have paid for.');
        expect(inertia.visits).toEqual([]);

        dialogButton('End subscription').click();
        await flushPromises();

        expect(inertia.visits).toEqual([{ method: 'post', url: '/core.billing.subscription.cancel', data: {} }]);
    });

    it('says that cancelling ends the subscription at once when a payment is overdue', async () => {
        const wrapper = page({ subscription: subscription({ status: 'past_due', graceEndsAt: '2026-04-17T09:00:00+00:00' }) });

        await buttonNamed(wrapper, 'Cancel subscription')!.trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain('The subscription ends now and the workspace is locked');
    });

    it('resumes without asking', async () => {
        const wrapper = page({ subscription: subscription({ cancelAtPeriodEnd: true }) });

        expect(wrapper.text()).toContain('Ends on Apr 10, 2026.');

        await buttonNamed(wrapper, 'Keep the subscription')!.trigger('click');

        expect(inertia.visits).toEqual([{ method: 'post', url: '/core.billing.subscription.resume', data: {} }]);
    });

    it('names the date that matters for each state', () => {
        const text = (current: SubscriptionSummary): string => page({ subscription: current }).text();

        expect(text(subscription({ status: 'trialing', periodEnd: null, trialEndsAt: '2026-03-24T09:00:00+00:00' }))).toContain('On trial until Mar 24, 2026.');
        expect(text(subscription({ status: 'past_due', graceEndsAt: '2026-04-17T09:00:00+00:00' }))).toContain(
            'Unless the unpaid invoice is paid by Apr 17, 2026, the workspace is locked.',
        );
        expect(text(subscription({ status: 'expired', locked: true, endedAt: '2026-04-17T09:00:00+00:00' }))).toContain(
            'Ended on Apr 17, 2026 because an invoice stayed unpaid.',
        );
        expect(text(subscription({ pending: { plan: 'Free', interval: 'monthly' } }))).toContain('Changes to the Free plan (Monthly) on Apr 10, 2026.');
        expect(text(subscription({ isFree: true, amount: 0, periodStart: null, periodEnd: null }))).toContain('Nothing to pay and nothing to renew.');
    });

    it('calls for the unpaid invoice to be paid, with a link to it', () => {
        const wrapper = page({ subscription: subscription({ status: 'past_due' }), openInvoice: invoice({ overdue: true }) });
        const link = wrapper.findAll('a').find((candidate) => candidate.text() === 'Pay invoice');

        expect(wrapper.text()).toContain('Invoice INV-2026-000001 is overdue');
        expect(wrapper.text()).toContain(`${money(150000)} was due on Mar 17, 2026.`);
        expect(link?.attributes('href')).toBe('/core.billing.invoices.show?invoice=01HINVOICE0000000000000001');
    });

    it('only offers someone who cannot pay a look at the invoice', () => {
        const wrapper = page({ canManage: false, openInvoice: invoice() });

        expect(wrapper.findAll('a').map((link) => link.text())).toContain('View invoice');
        expect(wrapper.findAll('a').map((link) => link.text())).not.toContain('Pay invoice');
    });

    it('does not ask for an offer to change plan that has run out to be paid', () => {
        const wrapper = page({ subscription: subscription(), openInvoice: invoice({ kind: 'change', overdue: true }) });

        expect(wrapper.text()).toContain('can no longer be paid. Choose the plan again');
        expect(wrapper.findAll('a').map((link) => link.text())).not.toContain('Pay invoice');
    });

    it('tells the payer who came back from a gateway what became of the payment', async () => {
        const text = (returned: PaymentSummary): string => page({ returnedPayment: returned }).text();

        expect(text(payment({ status: 'succeeded', paidAt: '2026-03-10T09:10:00+00:00' }))).toContain(`Payment receivedWe received ${money(150000)}.`);
        expect(text(payment({ status: 'failed', failureReason: 'Declined by the bank.' }))).toContain(
            'The payment did not go throughNothing was paid.The payment provider said: Declined by the bank.',
        );
        expect(text(payment({ status: 'canceled' }))).toContain('The payment was canceled');

        const waiting = page({ returnedPayment: payment() });

        expect(waiting.text()).toContain('We are waiting for the payment provider to confirm this payment.');
        expect(waiting.text()).toContain('Payment reference 01HREFERENCE00000000000001');

        await buttonNamed(waiting, 'Check again')!.trigger('click');

        expect(inertia.visits).toEqual([{ method: 'reload', url: '' }]);
    });

    it('lists invoices with what they are for and whether they are overdue', () => {
        const wrapper = page({
            invoices: [
                invoice({ id: 'b', number: 'INV-2026-000002', kind: 'renewal', overdue: true }),
                invoice({ id: 'a', status: 'paid', paidAt: '2026-03-10T09:00:00+00:00' }),
            ],
        });
        const rows = wrapper.findAll('tbody tr').map((row) => row.text());

        expect(rows[0]).toContain('INV-2026-000002');
        expect(rows[0]).toContain('Renewal');
        expect(rows[0]).toContain('Overdue');
        expect(rows[0]).toContain('Mar 10, 2026 to Apr 10, 2026');
        expect(rows[1]).toContain('New plan');
        expect(rows[1]).toContain('Paid');
        expect(wrapper.find('tbody a').attributes('href')).toBe('/core.billing.invoices.show?invoice=b');
    });

    it('shows usage against the limit, and says when it is reached', () => {
        const wrapper = page({
            usage: [
                { key: 'core.members', kind: 'gauge', limit: 5, used: 5 },
                { key: 'sms.messages', kind: 'metered', limit: null, used: 120 },
            ],
        });

        expect(wrapper.text()).toContain('Members');
        expect(wrapper.text()).toContain('5 of 5');
        expect(wrapper.text()).toContain('The limit is reached.');
        expect(wrapper.text()).toContain('120, no limit');
        expect(wrapper.text()).toContain('Counted since the start of the billing period.');
    });

    it('reads in Bengali as well', () => {
        setLocale('bn');

        const wrapper = page({ subscription: subscription(), plans: [{ ...starter, current: true }] });

        expect(wrapper.text()).toContain('বর্তমান প্ল্যান');
        expect(wrapper.text()).not.toMatch(/billing\.[a-z]/);
    });
});

/*
 * The invoice page of a workspace.
 */
describe('invoice page', () => {
    const page = (overrides: Record<string, unknown> = {}) =>
        track(
            mount(WorkspaceInvoice, {
                props: {
                    invoice: invoiceDocument(),
                    payments: [],
                    gateways: [{ key: 'testpay', name: 'TestPay' }],
                    offlineInstructions: null,
                    canPay: true,
                    ...overrides,
                },
            }),
        );

    it('prints the invoice as a document', () => {
        const wrapper = page({
            invoice: invoiceDocument({
                kind: 'change',
                subtotalAmount: 450000,
                creditAmount: 96774,
                totalAmount: 353226,
                lines: [
                    { type: 'plan', plan: 'Business', interval: 'monthly', amount: 450000 },
                    { type: 'credit', amount: -96774 },
                ],
            }),
        });
        const document = wrapper.find('[data-invoice-document]').text();

        expect(document).toContain('Oyosend Ltd');
        expect(document).toContain('Acme Trading Limited');
        expect(document).toContain('12 Lake Road');
        expect(document).toContain('Dhaka 1212');
        expect(document).toContain('Bangladesh');
        expect(document).toContain('INV-2026-000001');
        expect(document).toContain('Change of plan');
        expect(document).toContain('Business plan, Monthly');
        expect(document).toContain('Credit for unused time on the previous plan');
        expect(document).toContain(money(-96774));
        expect(document).toContain(`Amount due${money(353226)}`);
        expect(document).toContain('Unpaid');
    });

    it('offers one button per gateway and starts the payment with the one chosen', async () => {
        const wrapper = page({
            gateways: [
                { key: 'testpay', name: 'TestPay' },
                { key: 'sslcommerz', name: 'SSLCommerz' },
            ],
        });

        expect(buttonNamed(wrapper, 'Pay with TestPay')).toBeDefined();

        await buttonNamed(wrapper, 'Pay with SSLCommerz')!.trigger('click');

        expect(inertia.visits).toEqual([
            { method: 'post', url: '/core.billing.invoices.pay?invoice=01HINVOICE0000000000000001', data: { gateway: 'sslcommerz' } },
        ]);

        // While the browser is on its way to the provider, nothing else can be started.
        await buttonNamed(wrapper, 'Pay with TestPay')?.trigger('click');

        expect(inertia.visits).toHaveLength(1);
    });

    it('says honestly that no online payment method is set up', () => {
        const wrapper = page({ gateways: [] });

        expect(wrapper.text()).toContain('No online payment method is set up');
        expect(wrapper.text()).toContain('No other way to pay has been published yet either.');
        expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Print']);
    });

    it('shows how to pay another way as text, line breaks kept, never as markup', () => {
        const wrapper = page({ gateways: [], offlineInstructions: 'Bank: <b>Example Bank</b>\nAccount: 0001' });
        const instructions = wrapper.findAll('p').find((paragraph) => paragraph.text().startsWith('Bank:'));

        expect(instructions?.text()).toBe('Bank: <b>Example Bank</b>\nAccount: 0001');
        expect(instructions?.classes()).toContain('whitespace-pre-line');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('No other way to pay has been published');
    });

    it('does not offer to pay to someone who may only look', () => {
        const wrapper = page({ canPay: false, offlineInstructions: 'Pay at the office.' });

        expect(wrapper.text()).toContain('Someone whose role manages billing can pay it.');
        expect(wrapper.text()).toContain('Pay at the office.');
        expect(buttonNamed(wrapper, 'Pay with TestPay')).toBeUndefined();
    });

    it('does not offer to pay an offer to change plan that has run out', () => {
        const wrapper = page({ invoice: invoiceDocument({ kind: 'change', overdue: true }) });

        expect(wrapper.text()).toContain('This offer has run out');
        expect(wrapper.text()).toContain('it could only be paid until Mar 17, 2026');
        expect(buttonNamed(wrapper, 'Pay with TestPay')).toBeUndefined();
    });

    it('has nothing to pay on an invoice that is paid, and lists what paid it', () => {
        const wrapper = page({
            invoice: invoiceDocument({ status: 'paid', paidAt: '2026-03-12T09:00:00+00:00' }),
            gateways: [],
            canPay: false,
            payments: [
                payment({ status: 'succeeded', gateway: 'offline', method: 'bank_transfer', paidAt: '2026-03-12T09:00:00+00:00' }),
                payment({ id: 'failed', status: 'failed', failureReason: 'Declined by the bank.' }),
            ],
        });

        expect(wrapper.text()).not.toContain('Pay this invoice');
        expect(wrapper.text()).toContain(`Amount paid${money(150000)}`);

        const entries = wrapper.findAll('li').map((entry) => entry.text());

        expect(entries[0]).toContain('Recorded by support');
        expect(entries[0]).toContain('Bank transfer');
        expect(entries[1]).toContain('testpay');
        expect(entries[1]).toContain('Failed');
        expect(entries[1]).toContain('Reason given: Declined by the bank.');
    });
});

/*
 * The invoice pages of the Super Admin panel.
 */
describe('panel invoices', () => {
    const row = (overrides: Record<string, unknown> = {}) => ({
        ...invoice(),
        workspace: { id: 'tenant-1', name: 'Acme Trading', slug: 'acme' },
        ...overrides,
    });

    const list = (overrides: Record<string, unknown> = {}) =>
        track(
            mount(PanelInvoices, {
                props: {
                    invoices: {
                        data: [row()],
                        current_page: 1,
                        last_page: 1,
                        per_page: 25,
                        total: 1,
                        from: 1,
                        to: 1,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                    filters: { search: '', status: '' },
                    totals: { open: [], paidThisMonth: [] },
                    offlineMethods: ['bank_transfer', 'cash'],
                    canManage: true,
                    ...overrides,
                },
            }),
        );

    it('adds up what is owed and what came in per currency, never across them', () => {
        const wrapper = list({
            totals: {
                open: [
                    { currency: 'BDT', amount: 450000, count: 2 },
                    { currency: 'USD', amount: 2900, count: 1 },
                ],
                paidThisMonth: [],
            },
        });
        const [owed, received] = wrapper.findAll('section').map((section) => section.text());

        expect(owed).toContain(money(450000));
        expect(owed).toContain('2 invoices waiting for payment');
        expect(owed).toContain(money(2900, 'USD'));
        expect(owed).toContain('1 invoice waiting for payment');
        expect(received).toContain('Nothing has been paid this month yet.');
    });

    it('links each row to its invoice and, for those who may open them, to its workspace', () => {
        inertia.page.props.auth.abilities = ['platform.billing.view', 'platform.tenants.view'];

        const links = list()
            .findAll('tbody a')
            .map((link) => link.attributes('href'));

        expect(links).toEqual(['/platform.invoices.show?invoice=01HINVOICE0000000000000001', '/platform.tenants.show?workspace=tenant-1']);
    });

    it('names the workspace without a link for a role that cannot open workspaces, and says when it is gone', () => {
        const wrapper = list({
            invoices: {
                data: [row(), row({ id: 'gone', number: 'INV-2026-000002', workspace: null })],
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: 2,
                from: 1,
                to: 2,
                prev_page_url: null,
                next_page_url: null,
            },
        });
        const rows = wrapper.findAll('tbody tr');

        expect(rows[0]!.findAll('a')).toHaveLength(1);
        expect(rows[0]!.text()).toContain('Acme Trading');
        expect(rows[1]!.text()).toContain('Deleted workspace');
        expect(wrapper.text()).toContain('2 invoices');
    });

    it('says there are no invoices yet, as opposed to none that match', () => {
        const none = { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null };

        expect(list({ invoices: none }).text()).toContain('No invoices yet');

        const filtered = list({ invoices: none, filters: { search: 'zzz', status: '' } });

        expect(filtered.text()).toContain('Nothing matches your filters');
        expect(buttonNamed(filtered, 'Clear filters')).toBeDefined();
    });

    const detail = (overrides: Record<string, unknown> = {}) =>
        track(
            mount(PanelInvoice, {
                props: {
                    invoice: invoiceDocument(),
                    workspace: { id: 'tenant-1', name: 'Acme Trading', slug: 'acme' },
                    payments: [],
                    offlineMethods: ['bank_transfer', 'cash', 'cheque', 'mobile_banking', 'other'],
                    canManage: true,
                    ...overrides,
                },
                attachTo: document.body,
            }),
        );

    it('offers to record a payment and to withdraw only on an unpaid invoice, and only to a role that may', () => {
        const actions = (overrides: Record<string, unknown>): string[] =>
            detail(overrides)
                .findAll('button')
                .map((button) => button.text())
                .filter((label) => ['Record payment', 'Withdraw invoice'].includes(label));

        expect(actions({})).toEqual(['Record payment', 'Withdraw invoice']);
        expect(actions({ canManage: false })).toEqual([]);
        expect(actions({ invoice: invoiceDocument({ status: 'paid', paidAt: '2026-03-12T09:00:00+00:00' }) })).toEqual([]);
        expect(actions({ invoice: invoiceDocument({ status: 'void', voidedAt: '2026-03-12T09:00:00+00:00' }) })).toEqual([]);
        // Nothing can be paid on an offer that has run out; it can still be withdrawn.
        expect(actions({ invoice: invoiceDocument({ kind: 'change', overdue: true }) })).toEqual(['Withdraw invoice']);
    });

    it('tells a role that may only look why there are no controls', () => {
        expect(detail({ canManage: false }).text()).toContain('Your role can see invoices but not record payments or withdraw them.');
    });

    it('records a payment with its method, date, reference and note', async () => {
        const wrapper = detail();

        await buttonNamed(wrapper, 'Record payment')!.trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain('Record a payment');
        expect(dialog()?.textContent).toContain(money(150000));
        // Every method the server accepts, by name.
        expect([...document.body.querySelectorAll('[role="dialog"] select[name="method"] option')].map((option) => option.textContent?.trim())).toEqual([
            'Choose a method',
            'Bank transfer',
            'Cash',
            'Cheque',
            'Mobile banking',
            'Other',
        ]);
        // Not before a method is chosen.
        expect(dialogButton('Record payment').disabled).toBe(true);

        await fill('method', 'cheque');
        await fill('paid_at', '2026-03-09');
        await fill('reference', 'CHQ-7781');
        await fill('note', 'Handed in at the office.');

        document.body.querySelector('[role="dialog"] form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        expect(inertia.sent).toEqual([
            {
                method: 'post',
                url: '/platform.invoices.payments.store?invoice=01HINVOICE0000000000000001',
                data: { method: 'cheque', reference: 'CHQ-7781', note: 'Handed in at the office.', paid_at: '2026-03-09' },
            },
        ]);
    });

    it('asks for a reason before it withdraws an invoice', async () => {
        const wrapper = detail();

        await buttonNamed(wrapper, 'Withdraw invoice')!.trigger('click');
        await flushPromises();

        expect(dialog()?.textContent).toContain('Withdraw invoice INV-2026-000001?');
        expect(dialog()?.textContent).toContain('This cannot be undone.');

        await fill('reason', 'Issued twice by mistake.');
        dialogButton('Withdraw invoice').click();
        await flushPromises();

        expect(inertia.visits).toEqual([
            {
                method: 'post',
                url: '/platform.invoices.void?invoice=01HINVOICE0000000000000001',
                data: { reason: 'Issued twice by mistake.' },
            },
        ]);
    });

    it('shows what the operator noted with a recorded payment', () => {
        const wrapper = detail({
            invoice: invoiceDocument({ status: 'paid', paidAt: '2026-03-12T09:00:00+00:00' }),
            payments: [
                payment({
                    status: 'succeeded',
                    gateway: 'offline',
                    method: 'cheque',
                    paidAt: '2026-03-12T09:00:00+00:00',
                    note: 'Handed in at the office.',
                    bankReference: 'CHQ-7781',
                }),
            ],
        });

        expect(wrapper.text()).toContain('Reference given: CHQ-7781');
        expect(wrapper.text()).toContain('Note: Handed in at the office.');
    });

    it('says when the workspace of an invoice no longer exists', () => {
        expect(detail({ workspace: null }).text()).toContain('no longer exists');
    });
});
