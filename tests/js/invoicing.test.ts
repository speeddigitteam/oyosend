import { mount, type VueWrapper } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Visit { method: string; url: string }
interface Submission extends Visit { data: Record<string, unknown> }
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['invoicing.invoices.view', 'invoicing.invoices.edit', 'invoicing.reports.view'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    visits: [] as Visit[],
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        let defaults = structuredClone(initial);
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.defaults = (values: Record<string, unknown>) => { defaults = { ...defaults, ...structuredClone(values) }; return form; };
        form.reset = () => { Object.assign(form, structuredClone(defaults)); return form; };
        form.clearErrors = () => {};
        form.transform = (callback: (data: Record<string, unknown>) => Record<string, unknown>) => { transform = callback; return form; };
        const submit = (method: string, url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, structuredClone(toRaw(form[key]))]));
            inertia.visits.push({ method, url });
            inertia.submissions.push({ method, url, data: transform(data) });
            options?.onSuccess?.();
        };
        form.post = (url: string, options?: { onSuccess?: () => void }) => submit('post', url, options);
        form.put = (url: string, options?: { onSuccess?: () => void }) => submit('put', url, options);
        form.patch = (url: string, options?: { onSuccess?: () => void }) => submit('patch', url, options);
        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

import { setLocale } from '@/Core/i18n';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import InvoiceForm from '@/Modules/Invoicing/Components/InvoiceForm.vue';
import {
    currencyDigits,
    fromInvoiceLine,
    lineTotals,
    newIdempotencyKey,
    parseDecimal,
    resolveAll,
    resolveLine,
    sumLines,
    toDecimal,
    blankLine,
} from '@/Modules/Invoicing/Components/invoiceMath';
import PaymentDialog from '@/Modules/Invoicing/Components/PaymentDialog.vue';
import type {
    InvoiceAbilities,
    InvoiceDetail,
    InvoiceFormOptions,
    InvoiceLine,
    ReceivablesReport,
    TaxRate,
} from '@/Modules/Invoicing/Components/types';
import InvoicesIndex from '@/Modules/Invoicing/Pages/Invoices/Index.vue';
import InvoicesShow from '@/Modules/Invoicing/Pages/Invoices/Show.vue';
import RecurringIndex from '@/Modules/Invoicing/Pages/Recurring/Index.vue';
import ReportsIndex from '@/Modules/Invoicing/Pages/Reports/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const vat: TaxRate = { id: 'rate-vat', name: 'VAT', rateBps: 1500, isDefault: true, isActive: true };
const options: InvoiceFormOptions = {
    contacts: [{ id: 'contact-1', name: 'Nadia Karim', email: 'nadia@example.test' }],
    companies: [{ id: 'company-1', name: 'Blue Fern Ltd', email: null }],
    taxRates: [vat],
    currencies: ['BDT', 'USD'],
    defaults: { currency: 'BDT', dueDays: 14, terms: 'Net 14', notes: null },
};

const noAbilities: InvoiceAbilities = {
    edit: false, delete: false, issue: false, send: false, void: false, recordPayment: false, reversePayment: false, issueCreditNote: false,
};

function line(overrides: Partial<InvoiceLine> = {}): InvoiceLine {
    return {
        id: 'line-1', position: 1, name: 'Consulting', description: null, unit: 'unit', quantityMilli: 1500, unitPriceAmount: 1000,
        grossAmount: 1500, discountBps: null, discountAmount: 0, netAmount: 1500, taxRateId: null, taxName: null, taxBps: 0, taxAmount: 0,
        lineTotalAmount: 1500, ...overrides,
    };
}

function invoice(overrides: Partial<InvoiceDetail> = {}): InvoiceDetail {
    return {
        id: 'inv-1', number: 'INV-0001', status: 'issued', title: null, reference: null, currency: 'BDT', issuedOn: '2026-10-01', dueOn: '2026-10-15',
        totalAmount: 12550, paidAmount: 0, creditedAmount: 0, balanceAmount: 12550,
        customer: { name: 'Blue Fern Ltd', contactId: null, companyId: 'company-1' }, owner: null, sentAt: null, source: null,
        createdAt: '2026-10-01T04:00:00Z', contactId: null, companyId: 'company-1', subtotalAmount: 12550, discountAmount: 0, taxAmount: 0,
        snapshot: { name: 'Blue Fern Ltd', contactName: null, email: 'billing@bluefern.test', phone: null, address: null, taxId: null },
        customerEmail: 'billing@bluefern.test', seller: { name: 'Acme Trading' }, terms: null, notes: null, issuedAt: '2026-10-01T04:00:00Z',
        paidAt: null, overdueAt: null, voidedAt: null, voidReason: null, recurringTemplateId: null,
        lines: [line({ unitPriceAmount: 12550, quantityMilli: 1000, grossAmount: 12550, netAmount: 12550, lineTotalAmount: 12550 })],
        payments: [], creditNotes: [], ...overrides,
    };
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['invoicing.invoices.view', 'invoicing.invoices.edit', 'invoicing.reports.view'];
});
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()));

describe('invoice line math', () => {
    it('rounds each product half-up in integer minor units', () => {
        // 10.00 x 1.5 = 15.00; 12.5% off = 1.875 -> 1.88; net 13.12; 15% tax = 1.968 -> 1.97
        expect(lineTotals({ quantityMilli: 1500, unitPrice: 1000, discountAmount: null, discountBps: 1250, taxBps: 1500 })).toEqual({
            gross: 1500, discount: 188, net: 1312, tax: 197, total: 1509,
        });
        // 0.01 x 0.5 = 0.005 rounds up to a whole minor unit
        expect(lineTotals({ quantityMilli: 500, unitPrice: 1, discountAmount: null, discountBps: null, taxBps: 0 })?.gross).toBe(1);
        expect(lineTotals({ quantityMilli: 499, unitPrice: 1, discountAmount: null, discountBps: null, taxBps: 0 })?.gross).toBe(0);
    });

    it('applies a fixed discount before tax and refuses a discount above the value', () => {
        expect(lineTotals({ quantityMilli: 2000, unitPrice: 5000, discountAmount: 1000, discountBps: null, taxBps: 500 })).toEqual({
            gross: 10000, discount: 1000, net: 9000, tax: 450, total: 9450,
        });
        expect(lineTotals({ quantityMilli: 1000, unitPrice: 500, discountAmount: 501, discountBps: null, taxBps: 0 })).toBeNull();
        expect(lineTotals({ quantityMilli: 1000, unitPrice: 500, discountAmount: 10, discountBps: 100, taxBps: 0 })).toBeNull();
    });

    it('sums rounded lines, not the unrounded document', () => {
        const one = lineTotals({ quantityMilli: 1000, unitPrice: 5, discountAmount: null, discountBps: null, taxBps: 1000 })!;
        // each line: tax 0.5 rounds up to 1
        expect(one.tax).toBe(1);
        expect(sumLines([one, one, one]).tax).toBe(3);
        expect(sumLines([one, one, one]).total).toBe(18);
    });

    it('stays exact beyond double precision', () => {
        const result = lineTotals({ quantityMilli: 1_000_001, unitPrice: 999_999_999_999, discountAmount: null, discountBps: null, taxBps: 0 });
        // 999999999999 x 1000.001 = 1000000999998999.999 -> 1000000999999000 (half-up)
        expect(result?.gross).toBe(1_000_000_999_999_000);
    });

    it('parses decimals without rounding silently', () => {
        expect(parseDecimal('12.5', 2)).toBe(1250);
        expect(parseDecimal('12', 2)).toBe(1200);
        expect(parseDecimal('12.505', 2)).toBeNull();
        expect(parseDecimal('12.500', 2)).toBe(1250);
        expect(parseDecimal('-1', 2)).toBeNull();
        expect(parseDecimal('1,5', 2)).toBeNull();
        expect(parseDecimal('', 2)).toBeNull();
        expect(parseDecimal('0.001', 3)).toBe(1);
        expect(toDecimal(1250, 2)).toBe('12.50');
        expect(toDecimal(5, 2)).toBe('0.05');
        expect(toDecimal(1500, 3, true)).toBe('1.5');
        expect(toDecimal(2000, 3, true)).toBe('2');
        expect(currencyDigits('BDT')).toBe(2);
        expect(currencyDigits('JPY')).toBe(0);
    });

    it('builds the server payload for a workspace rate and for a custom rate', () => {
        const withRate = resolveLine(blankLine({ name: 'Design', quantity: '2', unitPrice: '100.00', taxRateId: vat.id }), 2, [vat])!;
        expect(withRate.payload).toMatchObject({ name: 'Design', quantity_milli: 2000, unit_price_amount: 10000, tax_rate_id: 'rate-vat', tax_bps: null });
        expect(withRate.totals.total).toBe(23000);

        const custom = resolveLine(blankLine({ name: 'Delivery', unitPrice: '10', taxRateId: 'custom', taxPercent: '7.5', taxName: 'Local levy', discountMode: 'percent', discount: '10' }), 2, [])!;
        expect(custom.payload).toMatchObject({ tax_rate_id: null, tax_bps: 750, tax_name: 'Local levy', discount_bps: 1000, discount_amount: null });
        expect(custom.totals).toMatchObject({ gross: 1000, discount: 100, net: 900, tax: 68, total: 968 });
    });

    it('treats incomplete lines as invalid instead of guessing', () => {
        expect(resolveLine(blankLine({ name: '', unitPrice: '5' }), 2, [])).toBeNull();
        expect(resolveLine(blankLine({ name: 'x', unitPrice: '' }), 2, [])).toBeNull();
        expect(resolveAll([blankLine({ name: 'x', unitPrice: '5' }), blankLine()], 2, []).valid).toBe(false);
    });

    it('turns a stored line back into editable text, keeping a retired tax rate as a custom rate', () => {
        const edited = fromInvoiceLine(line({ quantityMilli: 1500, unitPriceAmount: 1250, taxRateId: 'gone', taxName: 'Old VAT', taxBps: 500, discountBps: 1000, discountAmount: 125 }), 2, [vat]);
        expect(edited).toMatchObject({ quantity: '1.5', unitPrice: '12.50', discountMode: 'percent', discount: '10', taxRateId: 'custom', taxPercent: '5', taxName: 'Old VAT' });
        const known = fromInvoiceLine(line({ taxRateId: vat.id, taxName: 'VAT', taxBps: 1500 }), 2, [vat]);
        expect(known.taxRateId).toBe('rate-vat');
    });

    it('generates idempotency keys the server accepts, one per call', () => {
        const first = newIdempotencyKey();
        expect(first).toMatch(/^[A-Za-z0-9._:-]+$/);
        expect(first.length).toBeLessThanOrEqual(120);
        expect(newIdempotencyKey()).not.toBe(first);
    });
});

describe('invoice form', () => {
    it('previews exact totals as lines are typed and posts server-shaped lines', async () => {
        const wrapper = track(mount(InvoiceForm, { props: { options, prefill: { contactId: null, companyId: 'company-1' } } }));
        const line = wrapper.get('[data-testid="invoice-line"]');
        const inputs = line.findAll('input');
        // name, quantity, unit, unit price, description (discount value and custom tax are hidden)
        await inputs[0]!.setValue('Consulting');
        await inputs[1]!.setValue('1.5');
        await inputs[3]!.setValue('10.00');

        // the default workspace rate (15%) is preselected: 15.00 + 2.25
        expect(wrapper.get('[data-total="subtotal"]').text()).toContain('15.00');
        expect(wrapper.get('[data-total="tax"]').text()).toContain('2.25');
        expect(wrapper.get('[data-total="total"]').text()).toContain('17.25');

        await wrapper.get('form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'post', url: '/invoicing.invoices.store' });
        expect(inertia.submissions.at(-1)?.data).toMatchObject({
            company_id: 'company-1',
            contact_id: null,
            currency: 'BDT',
            terms: 'Net 14',
            lines: [{ name: 'Consulting', quantity_milli: 1500, unit_price_amount: 1000, tax_rate_id: 'rate-vat' }],
        });
        expect(inertia.submissions.at(-1)?.data).not.toHaveProperty('total_amount');
    });

    it('does not submit without a customer or with an incomplete line', async () => {
        const wrapper = track(mount(InvoiceForm, { props: { options } }));
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions).toHaveLength(0);
        expect(wrapper.text()).toContain('Choose a company or a contact to continue.');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('updates a draft through the named route', async () => {
        const draft = invoice({ status: 'draft', number: null, snapshot: null });
        const wrapper = track(mount(InvoiceForm, { props: { options, invoice: draft } }));
        await wrapper.get('form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'put', url: '/invoicing.invoices.update?invoice=inv-1' });
    });
});

describe('payment dialog', () => {
    const mountDialog = () => track(mount(PaymentDialog, {
        props: { open: true, invoiceId: 'inv-1', currency: 'BDT', balance: 12550, methods: ['cash', 'bank_transfer', 'mobile_banking', 'cheque', 'card', 'other'] },
        global: { stubs },
    }));

    it('defaults the amount to the balance, today and a fresh idempotency key', async () => {
        const wrapper = mountDialog();
        const amount = wrapper.get('input[inputmode="decimal"]').element as HTMLInputElement;
        expect(amount.value).toBe('125.50');
        expect((wrapper.get('input[type="date"]').element as HTMLInputElement).value).toMatch(/^\d{4}-\d{2}-\d{2}$/);

        await wrapper.get('form').trigger('submit');
        const submitted = inertia.submissions.at(-1)!;
        expect(submitted.url).toBe('/invoicing.invoices.payments.store?invoice=inv-1');
        expect(submitted.data).toMatchObject({ amount: 12550, method: 'cash' });
        expect(String(submitted.data.idempotency_key)).toMatch(/^[A-Za-z0-9._:-]+$/);
    });

    it('uses a different key every time the dialog is opened', async () => {
        const first = mountDialog();
        await first.get('form').trigger('submit');
        const second = mountDialog();
        await second.get('form').trigger('submit');
        const [a, b] = inertia.submissions.map((item) => item.data.idempotency_key);
        expect(a).toBeTruthy();
        expect(a).not.toBe(b);
    });

    it('refuses an amount above the balance or an invalid one, locally', async () => {
        const wrapper = mountDialog();
        const amount = wrapper.get('input[inputmode="decimal"]');
        await amount.setValue('125.51');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions).toHaveLength(0);
        expect(wrapper.text()).toContain('cannot be more than the balance due');

        await amount.setValue('abc');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.text()).toContain('Enter an amount greater than zero.');
        expect(inertia.submissions).toHaveLength(0);

        await amount.setValue('50');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data.amount).toBe(5000);
    });
});

describe('invoice pages', () => {
    const buttonLabels = (wrapper: VueWrapper) => wrapper.findAll('button, a').map((item) => item.text());

    it('shows only the actions the server allows', () => {
        const none = track(mount(InvoicesShow, { props: { invoice: invoice(), abilities: noAbilities, paymentMethods: ['cash'], form: options }, global: { stubs } }));
        const labels = buttonLabels(none);
        expect(labels).not.toContain('Record payment');
        expect(labels).not.toContain('Send');
        expect(labels).not.toContain('Issue invoice');
        expect(none.find('[aria-label="More actions"]').exists()).toBe(false);
        // Viewing is always allowed: the document and the download link remain.
        expect(none.get('[data-testid="download-link"]').attributes('href')).toBe('/invoicing.invoices.document?invoice=inv-1&download=1');

        const all = track(mount(InvoicesShow, {
            props: {
                invoice: invoice(),
                abilities: { ...noAbilities, send: true, recordPayment: true, void: true, issueCreditNote: true, reversePayment: true },
                paymentMethods: ['cash'],
                form: options,
            },
            global: { stubs },
        }));
        const allLabels = buttonLabels(all);
        expect(allLabels).toContain('Record payment');
        expect(allLabels).toContain('Send');
        expect(all.find('[aria-label="More actions"]').exists()).toBe(true);
    });

    it('offers issue and edit on a draft, with no payment history or document', () => {
        const draft = invoice({ status: 'draft', number: null, issuedOn: null, dueOn: null, snapshot: null, balanceAmount: 0 });
        const wrapper = track(mount(InvoicesShow, {
            props: { invoice: draft, abilities: { ...noAbilities, edit: true, delete: true, issue: true }, paymentMethods: [], form: options },
            global: { stubs },
        }));
        const labels = buttonLabels(wrapper);
        expect(labels).toContain('Issue invoice');
        expect(labels).toContain('Edit');
        expect(wrapper.text()).toContain('Draft invoice');
        expect(wrapper.find('[data-testid="download-link"]').exists()).toBe(false);
    });

    it('lets a reversal be requested only where the server allows and shows reversed payments struck', () => {
        const paid = invoice({
            status: 'partially_paid', paidAmount: 5000, balanceAmount: 7550,
            payments: [
                { id: 'pay-1', amount: 5000, currency: 'BDT', method: 'cash', reference: 'R-1', paidOn: '2026-10-05', notes: null, recordedBy: 'Ayesha', reversedAt: null, reversalReason: null, createdAt: '2026-10-05T04:00:00Z' },
                { id: 'pay-2', amount: 700, currency: 'BDT', method: 'card', reference: null, paidOn: '2026-10-06', notes: null, recordedBy: 'Ayesha', reversedAt: '2026-10-07T04:00:00Z', reversalReason: 'Wrong invoice', createdAt: '2026-10-06T04:00:00Z' },
            ],
        });
        const denied = track(mount(InvoicesShow, { props: { invoice: paid, abilities: noAbilities, paymentMethods: ['cash'], form: options }, global: { stubs } }));
        expect(buttonLabels(denied)).not.toContain('Reverse payment');
        expect(denied.text()).toContain('Reversed');

        const allowed = track(mount(InvoicesShow, { props: { invoice: paid, abilities: { ...noAbilities, reversePayment: true }, paymentMethods: ['cash'], form: options }, global: { stubs } }));
        // One un-reversed payment can be reversed; the reversed one cannot.
        expect(buttonLabels(allowed).filter((label) => label === 'Reverse payment')).toHaveLength(1);
        expect(allowed.get('[data-testid="invoice-timeline"]').text()).toContain('Wrong invoice');
    });

    it('lists invoices with status badges in English and Bengali', () => {
        const summary = {
            id: 'inv-1', number: 'INV-0001', status: 'overdue' as const, title: null, reference: null, currency: 'BDT', issuedOn: '2026-09-01', dueOn: '2026-09-15',
            totalAmount: 250000, paidAmount: 0, creditedAmount: 0, balanceAmount: 250000, customer: { name: 'Blue Fern Ltd', contactId: null, companyId: 'c1' },
            owner: null, sentAt: null, source: null, createdAt: '2026-09-01T04:00:00Z',
        };
        const props = {
            invoices: { data: [summary], current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null },
            filters: { search: '', status: null, contactId: null, companyId: null, from: null, to: null },
            statuses: ['draft', 'issued', 'paid', 'overdue'] as ('draft' | 'issued' | 'paid' | 'overdue')[],
            canCreate: true,
        };
        const english = track(mount(InvoicesIndex, { props }));
        expect(english.text()).toContain('INV-0001');
        expect(english.text()).toContain('Overdue');
        expect(english.text()).toContain('New invoice');

        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const bengali = track(mount(InvoicesIndex, { props }));
        expect(bengali.text()).toContain('বকেয়া');
        expect(bengali.text()).toContain('নতুন ইনভয়েস');
        expect(bengali.text()).toContain('২,৫০০');
    });

    it('hides the create button and explains an empty list for view-only members', () => {
        const wrapper = track(mount(InvoicesIndex, {
            props: {
                invoices: { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null },
                filters: { search: '', status: null, contactId: null, companyId: null, from: null, to: null },
                statuses: [],
                canCreate: false,
            },
        }));
        expect(wrapper.text()).toContain('No invoices yet');
        expect(wrapper.text()).not.toContain('New invoice');
    });

    it('renders the receivables report with ageing buckets and an empty state', () => {
        const report: ReceivablesReport = {
            asOf: '2026-10-11',
            ageing: [{ currency: 'BDT', invoices: 2, outstanding: 300000, buckets: { current: 100000, days_1_30: 200000, days_31_60: 0, days_61_90: 0, days_over_90: 0 } }],
            byCustomer: [{ contactId: null, companyId: 'c1', name: 'Blue Fern Ltd', currency: 'BDT', invoices: 2, outstanding: 300000, oldestDueOn: '2026-09-20' }],
            collected: [{ currency: 'BDT', payments: 1, amount: 50000 }],
            collectedByMethod: [{ currency: 'BDT', method: 'cash', payments: 1, amount: 50000 }],
            invoiced: [{ currency: 'BDT', invoices: 3, total: 400000, tax: 20000, credited: 0 }],
        };
        const buckets = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'];
        const wrapper = track(mount(ReportsIndex, { props: { report, filters: { from: null, to: '2026-10-11', currency: null }, buckets } }));
        expect(wrapper.text()).toContain('Receivables ageing');
        expect(wrapper.text()).toContain('1 to 30 days');
        expect(wrapper.text()).toContain('Blue Fern Ltd');
        expect(wrapper.text()).toContain('Cash');

        const empty = track(mount(ReportsIndex, {
            props: { report: { asOf: '2026-10-11', ageing: [], byCustomer: [], collected: [], collectedByMethod: [], invoiced: [] }, filters: { from: null, to: null, currency: null }, buckets },
        }));
        expect(empty.text()).toContain('Nothing to report yet');
    });

    it('hides recurring management from members who may only view', () => {
        const template = {
            id: 't1', name: 'Retainer', status: 'active' as const, frequency: 'monthly' as const, every: 1, currency: 'BDT', startsOn: '2026-10-01', nextRunOn: '2026-11-01',
            endsOn: null, lastRunOn: null, runsCount: 0, lastError: null, dueDays: 14, autoIssue: false, autoSend: false, title: null, reference: null, terms: null, notes: null,
            contactId: null, companyId: 'c1', customer: { name: 'Blue Fern Ltd' }, totalAmount: 100000, lines: [], owner: null, editable: false,
        };
        const base = { templates: { data: [template], current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null }, frequencies: ['monthly' as const], form: options };
        const viewer = track(mount(RecurringIndex, { props: { ...base, canManage: false }, global: { stubs } }));
        expect(viewer.text()).toContain('Retainer');
        expect(viewer.text()).toContain('Every month');
        expect(viewer.text()).not.toContain('New recurring invoice');
        expect(viewer.text()).not.toContain('Pause');

        const manager = track(mount(RecurringIndex, { props: { ...base, templates: { ...base.templates, data: [{ ...template, editable: true }] }, canManage: true }, global: { stubs } }));
        expect(manager.text()).toContain('New recurring invoice');
        expect(manager.text()).toContain('Pause');
    });
});

describe('invoicing messages', () => {
    function files(directory: string): string[] {
        return readdirSync(directory).flatMap((name) => {
            const path = join(directory, name);

            return statSync(path).isDirectory() ? files(path) : [path];
        });
    }

    function lookup(tree: unknown, path: string): unknown {
        return path.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), tree);
    }

    it('defines every static key the pages and components use, in both languages', () => {
        const pattern = /\bt\(\s*(?:[\w.!]+\s*\?\s*)?'(invoicing\.[\w.]+)'(?:\s*:\s*'(invoicing\.[\w.]+)')?/g;
        const used = new Set<string>();

        for (const file of files(join(process.cwd(), 'resources/js/Modules/Invoicing')).filter((path) => path.endsWith('.vue'))) {
            for (const match of readFileSync(file, 'utf8').matchAll(pattern)) {
                used.add(match[1]!);
                if (match[2]) used.add(match[2]);
            }
        }

        expect(used.size).toBeGreaterThan(100);

        const missing = [...used].filter((key) => typeof lookup(en, key) !== 'string' || typeof lookup(bn, key) !== 'string');
        expect(missing).toEqual([]);
    });

    it('defines every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'invoicing.status': ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'active', 'paused', 'ended'],
            'invoicing.methods': ['cash', 'bank_transfer', 'mobile_banking', 'cheque', 'card', 'other'],
            'invoicing.frequencies': ['weekly', 'monthly', 'quarterly', 'yearly'],
            'invoicing.sources': ['crm_quote', 'project', 'automation', 'recurring'],
            'invoicing.reports.buckets': ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'],
            'invoicing.nav': ['invoices', 'billable', 'recurring', 'reports', 'settings'],
            'invoicing.schedule': ['weeklyOne', 'weeklyMany', 'monthlyOne', 'monthlyMany', 'quarterlyOne', 'quarterlyMany', 'yearlyOne', 'yearlyMany'],
        };

        for (const [family, keys] of Object.entries(families)) {
            for (const key of keys) {
                expect(typeof lookup(en, `${family}.${key}`), `en ${family}.${key}`).toBe('string');
                expect(typeof lookup(bn, `${family}.${key}`), `bn ${family}.${key}`).toBe('string');
            }
        }
    });

    it('labels the module, permissions, limit and sidebar entries the server announces', () => {
        const permissions = [
            'invoicing.invoices.view', 'invoicing.invoices.edit', 'invoicing.invoices.issue', 'invoicing.invoices.send', 'invoicing.invoices.void',
            'invoicing.payments.record', 'invoicing.payments.reverse', 'invoicing.credit_notes.issue',
            'invoicing.recurring.view', 'invoicing.recurring.manage', 'invoicing.reports.view', 'invoicing.settings.manage',
        ];

        for (const messages of [en, bn]) {
            expect(typeof messages.catalogue.modules.invoicing).toBe('string');
            expect(typeof (messages.catalogue.limits as Record<string, { label: string }>)['invoicing.invoices_per_month']?.label).toBe('string');
            expect(typeof messages.appStore.catalog.invoicing.name).toBe('string');
            expect(messages.appStore.catalog.invoicing.features).toHaveLength(4);

            for (const permission of permissions) {
                expect((messages.catalogue.permissions as Record<string, { label: string }>)[permission]?.label, permission).toBeTruthy();
            }

            for (const group of ['invoices', 'payments', 'credit_notes', 'recurring', 'reports', 'settings']) {
                expect((messages.catalogue.permissionGroups as Record<string, string>)[`invoicing_${group}`], group).toBeTruthy();
            }

            for (const key of ['invoicingInvoices', 'invoicingRecurring', 'invoicingReports', 'invoicingSettings']) {
                expect((messages.shell.nav as unknown as Record<string, string>)[key], key).toBeTruthy();
            }
        }
    });
});
