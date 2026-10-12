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
            auth: { permissions: [] as string[] },
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
import MoneyInput from '@/Modules/Payroll/Components/MoneyInput.vue';
import PaymentDialog from '@/Modules/Payroll/Components/PaymentDialog.vue';
import PayrollOverviewWidget from '@/Modules/Payroll/Components/PayrollOverviewWidget.vue';
import PayrollSelfWidget from '@/Modules/Payroll/Components/PayrollSelfWidget.vue';
import PercentInput from '@/Modules/Payroll/Components/PercentInput.vue';
import RuleSetDialog from '@/Modules/Payroll/Components/RuleSetDialog.vue';
import { issueMessage, ERROR_CODES, WARNING_CODES } from '@/Modules/Payroll/Components/payrollIssues';
import {
    bpsText,
    displayAmount,
    moneyText,
    newIdempotencyKey,
    parseBps,
    parseDecimal,
    parseMoney,
    todayIn,
} from '@/Modules/Payroll/Components/payrollMoney';
import { slabPayload, slabProblem } from '@/Modules/Payroll/Components/slabs';
import type { Account, PayslipDetail, PayslipRow, RunDetail } from '@/Modules/Payroll/Components/types';
import PayslipsShow from '@/Modules/Payroll/Pages/Payslips/Show.vue';
import RunsShow from '@/Modules/Payroll/Pages/Runs/Show.vue';
import SalariesShow from '@/Modules/Payroll/Pages/Salaries/Show.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const page = <T>(rows: T[]) => ({ data: rows, current_page: 1, last_page: 1, per_page: 25, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null, prev_page_url: null, next_page_url: null });

const period = { id: 'per-1', name: 'September 2026', kind: 'monthly' as const, startsOn: '2026-09-01', endsOn: '2026-09-30', payDate: '2026-10-02', status: 'processing' as const, closedAt: null };

function run(overrides: Partial<RunDetail> = {}): RunDetail {
    return {
        id: 'run-1', number: null, kind: 'regular', status: 'draft', periodId: period.id, periodName: period.name, reversesRunId: null,
        currency: 'BDT', employeeCount: 2, grossAmount: 12_000_000, deductionAmount: 1_000_000, reimbursementAmount: 0, netAmount: 11_000_000,
        employerAmount: 500_000, preparedById: 'mem-1', submittedById: null, approvedById: null, submittedAt: null, approvedAt: null,
        isLocked: false, note: null, createdAt: '2026-10-01T04:00:00Z', preparedByName: 'Rafi Ahmed', approvedByName: null,
        errors: [], warnings: [], reversesRunNumber: null, isReversed: false, ...overrides,
    };
}

const payslip = (overrides: Partial<PayslipRow> = {}): PayslipRow => ({
    id: 'slip-1', number: 'PSL-000001', runId: 'run-1', periodId: period.id, periodName: period.name, employeeId: 'emp-1', employeeNumber: 'EMP-0001',
    employeeName: 'Nadia Karim', departmentName: 'Finance', designationName: 'Accountant', currency: 'BDT', basicAmount: 5_000_000, grossAmount: 6_000_000,
    deductionAmount: 500_000, reimbursementAmount: 0, netAmount: 5_500_000, employerAmount: 250_000, isReversal: false, isLocked: true,
    paymentStatus: 'unpaid', paymentMethod: null, paymentReference: null, paidOn: null, warnings: [], ...overrides,
});

const none = { recalculate: false, submit: false, sendBack: false, approve: false, discard: false, reverse: false, recordPayments: false, exportPaymentSheet: false };

const runProps = (overrides: Record<string, unknown> = {}) => ({
    run: run(),
    period,
    payslips: page([payslip({ number: null, isLocked: false })]),
    filters: { q: '', payment_status: '' },
    paymentStatuses: ['unpaid', 'paid', 'failed'],
    approval: { requiresSeparateApprover: true, youPreparedOrSubmitted: false },
    abilities: { ...none },
    ...overrides,
});

const buttonLabels = (wrapper: VueWrapper) => wrapper.findAll('button, a').map((item) => item.text());

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = [];
});
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()));

describe('payroll money text', () => {
    it('converts money text to exact minor units with no float', () => {
        expect(parseMoney('1250.50', 'BDT')).toBe(125_050);
        expect(parseMoney('1250', 'BDT')).toBe(125_000);
        expect(parseMoney('0.10', 'BDT')).toBe(10);
        expect(parseMoney('0.07', 'BDT')).toBe(7);
        // 19.99 x 100 is 1998.9999999999998 in a float; the text path is exact.
        expect(parseMoney('19.99', 'BDT')).toBe(1999);
        expect(parseMoney('4503599627370.49', 'BDT')).toBe(450_359_962_737_049);
    });

    it('refuses extra decimals, signs, separators and anything unsafe', () => {
        expect(parseMoney('12.505', 'BDT')).toBeNull();
        expect(parseMoney('12.500', 'BDT')).toBe(1250);
        expect(parseMoney('-1', 'BDT')).toBeNull();
        expect(parseMoney('1,250', 'BDT')).toBeNull();
        expect(parseMoney('abc', 'BDT')).toBeNull();
        expect(parseMoney('', 'BDT')).toBeNull();
        expect(parseMoney('1000.5', 'JPY')).toBeNull();
        expect(parseMoney('1000', 'JPY')).toBe(1000);
        expect(parseMoney('99999999999999999999', 'BDT')).toBeNull();
    });

    it('accepts Bengali digits and shows minor units back as decimal text', () => {
        expect(parseMoney('১২৫০.৫০', 'BDT')).toBe(125_050);
        expect(moneyText(125_050, 'BDT')).toBe('1250.50');
        expect(moneyText(5, 'BDT')).toBe('0.05');
        expect(moneyText(null, 'BDT')).toBe('');
    });

    it('handles rates as basis points with at most two decimals', () => {
        expect(parseBps('7.5')).toBe(750);
        expect(parseBps('100')).toBe(10_000);
        expect(parseBps('0.01')).toBe(1);
        expect(parseBps('7.555')).toBeNull();
        expect(bpsText(750)).toBe('7.5');
        expect(bpsText(10_000)).toBe('100');
        expect(bpsText(1)).toBe('0.01');
        expect(parseDecimal('1.50', 1)).toBe(15);
    });

    it('makes distinct idempotency keys and a zone-correct today', () => {
        const keys = new Set(Array.from({ length: 20 }, () => newIdempotencyKey()));
        expect(keys.size).toBe(20);
        for (const key of keys) {
            expect(key.length).toBeGreaterThanOrEqual(8);
            expect(key.length).toBeLessThanOrEqual(90);
        }
        // 20:00 UTC is already the next day in Dhaka.
        expect(todayIn('Asia/Dhaka', new Date('2026-10-11T20:00:00Z'))).toBe('2026-10-12');
        expect(todayIn('UTC', new Date('2026-10-11T20:00:00Z'))).toBe('2026-10-11');
    });

    it('negates reversal amounts for display only', () => {
        expect(displayAmount(5000, true)).toBe(-5000);
        expect(displayAmount(5000, false)).toBe(5000);
        expect(displayAmount(0, true)).toBe(0);
    });
});

describe('money and percent inputs', () => {
    it('emits the exact minor-unit integer and refuses an inexact amount', async () => {
        const wrapper = track(mount(MoneyInput, { props: { modelValue: null, currency: 'BDT', label: 'Amount' } }));
        const input = wrapper.get('input');

        await input.setValue('1250.50');
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([125_050]);
        expect(wrapper.text()).not.toContain('Enter an exact amount');

        await input.setValue('12.345');
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([null]);
        expect(wrapper.text()).toContain('at most 2 decimal places');

        await input.setValue('');
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([null]);
        expect(wrapper.text()).not.toContain('Enter an exact amount');
    });

    it('shows the stored amount as typed text and follows an outside change', async () => {
        const wrapper = track(mount(MoneyInput, { props: { modelValue: 125_050, currency: 'BDT' } }));
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe('1250.50');

        await wrapper.setProps({ modelValue: 900 });
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe('9.00');
    });

    it('turns a percentage into basis points and enforces its maximum', async () => {
        const wrapper = track(mount(PercentInput, { props: { modelValue: null, label: 'Rate', max: 10_000 } }));
        const input = wrapper.get('input');

        await input.setValue('7.5');
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([750]);

        await input.setValue('100.01');
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([null]);
        expect(wrapper.text()).toContain('from 0 to 100');
    });
});

describe('error and warning codes', () => {
    const fmt = { number: (value: number) => String(value), money: (amount: number) => `BDT ${amount}` };

    it('renders every known code from the interface language with its figures', () => {
        for (const code of ERROR_CODES) {
            const { key, params } = issueMessage('errors', { code, employee_id: 'e', employee_name: 'Nadia Karim' }, fmt);
            expect(key).toBe(`payroll.codes.errors.${code}`);
            expect(params.employee).toBe('Nadia Karim');
        }

        expect(issueMessage('warnings', { code: 'partial_period', payable_days: 20, period_days: 30 }, fmt)).toEqual({
            key: 'payroll.codes.warnings.partial_period',
            params: expect.objectContaining({ payable: '20', period: '30' }),
        });
        expect(issueMessage('warnings', { code: 'installment_deferred', amount: 250_000 }, fmt).params.amount).toBe('BDT 250000');
        expect(WARNING_CODES).toContain('open_attendance_days');
        expect(ERROR_CODES).toContain('amount_out_of_range');
        expect(en.payroll.codes.errors.amount_out_of_range).toContain('{employee}');
        expect(bn.payroll.codes.errors.amount_out_of_range).toContain('{employee}');
    });

    it('falls back to a generic message that names an unknown code', () => {
        const { key, params } = issueMessage('errors', { code: 'something_new' }, fmt);
        expect(key).toBe('payroll.codes.unknown');
        expect(params.code).toBe('something_new');
    });

    it('has a message for every code in both languages', () => {
        for (const code of ERROR_CODES) {
            expect(typeof (en.payroll.codes.errors as Record<string, string>)[code]).toBe('string');
            expect(typeof (bn.payroll.codes.errors as Record<string, string>)[code]).toBe('string');
        }
        for (const code of WARNING_CODES) {
            expect(typeof (en.payroll.codes.warnings as Record<string, string>)[code]).toBe('string');
            expect(typeof (bn.payroll.codes.warnings as Record<string, string>)[code]).toBe('string');
        }
    });

    it('lists a run\'s errors and warnings as sentences on the run page', () => {
        const wrapper = track(mount(RunsShow, {
            props: runProps({
                run: run({
                    errors: [{ code: 'negative_net', employee_id: 'e1', employee_name: 'Nadia Karim' }],
                    warnings: [{ code: 'no_salary_assignment', employee_id: 'e2', employee_name: 'Imran Hossain' }, { code: 'mystery', employee_id: 'e3' }],
                }),
            }),
            global: { stubs },
        }));

        const errors = wrapper.get('[data-testid="run-errors"]').text();
        expect(errors).toContain('Fix these before submitting');
        expect(errors).toContain('Nadia Karim would be paid less than zero');

        const warnings = wrapper.get('[data-testid="run-warnings"]').text();
        expect(warnings).toContain('Imran Hossain was left out');
        expect(warnings).toContain('code mystery');
        expect(wrapper.get('[data-testid="submit-blocked"]').text()).toContain('has errors');
    });
});

describe('run page', () => {
    it('shows only the actions the server allows for a draft run', () => {
        const preparer = track(mount(RunsShow, {
            props: runProps({ abilities: { ...none, recalculate: true, submit: true, discard: true } }),
            global: { stubs },
        }));
        const labels = buttonLabels(preparer);
        expect(labels).toContain('Recalculate');
        expect(labels).toContain('Submit for approval');
        expect(labels).toContain('Discard');
        expect(labels).not.toContain('Approve');
        expect(labels).not.toContain('Send back');
        expect(labels).not.toContain('Reverse run');

        const reader = track(mount(RunsShow, { props: runProps(), global: { stubs } }));
        expect(buttonLabels(reader)).not.toContain('Recalculate');
        expect(buttonLabels(reader)).not.toContain('Submit for approval');
    });

    it('lets an approver approve or send back, and explains the four-eyes rule', () => {
        const approver = track(mount(RunsShow, {
            props: runProps({
                run: run({ status: 'submitted', preparedById: 'mem-1', submittedById: 'mem-1' }),
                abilities: { ...none, approve: true, sendBack: true },
            }),
            global: { stubs },
        }));
        const labels = buttonLabels(approver);
        expect(labels).toContain('Approve');
        expect(labels).toContain('Send back');
        expect(approver.get('[data-testid="approval-note"]').text()).toContain('Someone else prepared or submitted');
        expect(approver.get('[data-testid="run-stepper"] [aria-current="step"]').text()).toContain('Awaiting approval');

        // The maker may hold the permission, but the server withholds `approve` from them.
        const maker = track(mount(RunsShow, {
            props: runProps({
                run: run({ status: 'submitted' }),
                approval: { requiresSeparateApprover: true, youPreparedOrSubmitted: true },
                abilities: { ...none, sendBack: true },
            }),
            global: { stubs },
        }));
        expect(buttonLabels(maker)).not.toContain('Approve');
        expect(maker.get('[data-testid="approval-note"]').text()).toContain('a different member must approve it');
    });

    it('offers reverse, payments and the payment sheet only on an approved run with the ability', () => {
        const approved = track(mount(RunsShow, {
            props: runProps({
                run: run({ status: 'approved', number: 'RUN-000001', isLocked: true, approvedByName: 'Sadia Rahman', approvedAt: '2026-10-05T04:00:00Z' }),
                abilities: { ...none, reverse: true, recordPayments: true, exportPaymentSheet: true },
            }),
            global: { stubs },
        }));
        const labels = buttonLabels(approved);
        expect(labels).toContain('Reverse run');
        expect(labels).toContain('Record payments for the run');
        expect(approved.get('[data-testid="payment-sheet-link"]').attributes('href')).toBe('/payroll.runs.payment-sheet?run=run-1');
        expect(approved.text()).toContain('Sadia Rahman');

        const plain = track(mount(RunsShow, { props: runProps({ run: run({ status: 'approved', number: 'RUN-000001' }) }), global: { stubs } }));
        expect(plain.find('[data-testid="payment-sheet-link"]').exists()).toBe(false);
        expect(buttonLabels(plain)).not.toContain('Reverse run');
    });

    it('requires a reason before a reversal is sent', async () => {
        const wrapper = track(mount(RunsShow, {
            props: runProps({
                run: run({ status: 'approved', number: 'RUN-000001' }),
                abilities: { ...none, reverse: true },
            }),
            global: { stubs },
        }));

        await wrapper.findAll('button').find((item) => item.text() === 'Reverse run')!.trigger('click');
        const submit = () => wrapper.findAll('button[type="submit"]').find((item) => item.text() === 'Reverse run')!;
        expect(submit().attributes('disabled')).toBeDefined();

        await wrapper.get('textarea').setValue('Wrong period was paid');
        expect(submit().attributes('disabled')).toBeUndefined();
        await wrapper.findAll('form').at(-1)!.trigger('submit');
        expect(inertia.submissions.at(-1)).toMatchObject({ url: '/payroll.runs.reverse?run=run-1', data: { note: 'Wrong period was paid' } });
    });

    it('marks a reversal run, shows its payslips negated and the run it undoes', () => {
        const wrapper = track(mount(RunsShow, {
            props: runProps({
                run: run({ kind: 'reversal', status: 'submitted', number: null, reversesRunId: 'run-0', reversesRunNumber: 'RUN-000001', netAmount: 5_500_000 }),
                payslips: page([payslip({ isReversal: true })]),
            }),
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="reversal-note"]').text()).toContain('RUN-000001');
        expect(wrapper.get('[data-testid="total-net"]').text()).toMatch(/^[-−]/);
        expect(wrapper.text()).toContain('Reversal');
        const net = wrapper.findAll('td').map((cell) => cell.text()).find((text) => text.includes('55,000.00'));
        expect(net).toMatch(/[-−]/);
    });
});

describe('payslip page', () => {
    const detail = (overrides: Partial<PayslipDetail> = {}): PayslipDetail => ({
        ...payslip(), runNumber: 'RUN-000001', runKind: 'regular', reversesPayslipId: null, payableDays: 30, periodDays: 30, taxableAmount: 5_800_000,
        structureName: 'Grade A', attendance: { summary: { days_present: 22, days_absent: 1, overtime_minutes: 90 } }, joinedOn: '2024-01-05', employmentType: 'full_time',
        ...overrides,
    });
    const lines = [
        { id: 'l1', kind: 'earning' as const, code: 'BASIC', name: 'Basic salary', source: 'system', amount: 5_000_000, isTaxable: true, detail: null },
        { id: 'l2', kind: 'deduction' as const, code: 'INCOME_TAX', name: 'Income tax', source: 'rule', amount: 300_000, isTaxable: false, detail: { version: 3, base: 5_800_000 } },
        { id: 'l3', kind: 'employer' as const, code: 'PF', name: 'Provident fund', source: 'rule', amount: 250_000, isTaxable: false, detail: null },
    ];
    const base = {
        period,
        lines,
        payments: [{ id: 'pay-1', status: 'failed' as const, method: 'bank' as const, reference: 'TXN-1', paidOn: null, amount: 5_500_000, currency: 'BDT', note: 'Account closed', createdAt: '2026-10-06T04:00:00Z' }],
        paymentMethods: ['bank', 'cash', 'mobile_wallet', 'cheque', 'other'] as unknown as Array<'bank' | 'cash' | 'mobile_wallet' | 'cheque' | 'other'>,
    };

    it('groups lines, lists the payment timeline and opens the printable document', () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const wrapper = track(mount(PayslipsShow, {
            props: { ...base, payslip: detail(), abilities: { recordPayment: true } },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="lines-earning"]').text()).toContain('Basic salary');
        expect(wrapper.get('[data-testid="lines-deduction"]').text()).toContain('Income tax');
        expect(wrapper.get('[data-testid="lines-deduction"]').text()).toContain('Rule version 3');
        expect(wrapper.get('[data-testid="lines-employer"]').text()).toContain('Provident fund');
        expect(wrapper.find('[data-testid="lines-reimbursement"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="payment-timeline"]').text()).toContain('Account closed');
        expect(wrapper.get('[data-testid="download-link"]').attributes('href')).toBe('/payroll.payslips.document?payslip=slip-1&download=1');
        expect(wrapper.text()).toContain('Record payment');

        const openButton = wrapper.findAll('button').find((item) => item.text() === 'Open printable page')!;
        openButton.trigger('click');
        expect(open).toHaveBeenCalledWith('/payroll.payslips.document?payslip=slip-1', '_blank', 'noopener');
        open.mockRestore();
    });

    it('hides the payment control when the server does not allow it', () => {
        const wrapper = track(mount(PayslipsShow, {
            props: { ...base, payslip: detail({ paymentStatus: 'paid', paidOn: '2026-10-06' }), abilities: { recordPayment: false } },
            global: { stubs },
        }));
        expect(buttonLabels(wrapper)).not.toContain('Record payment');
    });

    it('shows a reversal payslip negated and labelled', () => {
        const wrapper = track(mount(PayslipsShow, {
            props: { ...base, payslip: detail({ isReversal: true }), abilities: { recordPayment: false } },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="reversal-banner"]').text()).toContain('reverses a payslip of run RUN-000001');
        expect(wrapper.get('[data-testid="summary-net"]').text()).toMatch(/[-−].*55,000\.00/);
        expect(wrapper.get('[data-testid="lines-earning"]').text()).toMatch(/[-−].*50,000\.00/);
    });
});

describe('payment dialog', () => {
    const open = (extra: Record<string, unknown> = {}) => track(mount(PaymentDialog, {
        props: { open: true, url: '/pay', currency: 'BDT', methods: ['bank', 'cash'], amount: 5_500_000, ...extra },
        global: { stubs },
    }));

    it('sends a paid outcome with a method, a date and an idempotency key', async () => {
        const wrapper = open();
        await wrapper.get('input[type="date"]').setValue('2026-10-06');
        await wrapper.get('input:not([type="date"]):not([type="radio"])').setValue('TXN-77');
        await wrapper.get('form').trigger('submit');

        const sent = inertia.submissions.at(-1)!;
        expect(sent.url).toBe('/pay');
        expect(sent.data).toMatchObject({ status: 'paid', method: 'bank', paid_on: '2026-10-06', reference: 'TXN-77', note: null });
        expect(String(sent.data.idempotency_key).length).toBeGreaterThanOrEqual(8);
    });

    it('makes a fresh key each time it opens, and keeps one within an opening', async () => {
        const wrapper = open({ open: false });

        await wrapper.setProps({ open: true });
        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');
        const [first, again] = inertia.submissions.slice(-2).map((item) => item.data.idempotency_key);
        expect(first).toBe(again);

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)!.data.idempotency_key).not.toBe(first);
    });

    it('sends a failed outcome without a method or a paid date', async () => {
        const wrapper = open({ bulk: true, amount: undefined });
        expect(String(wrapper.findComponent({ name: 'PayrollFormDialog' }).props('description'))).toContain('every unpaid payslip of this run');

        const failed = wrapper.findAll('button').find((item) => item.text() === 'Failed')!;
        await failed.trigger('click');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions.at(-1)!.data).toMatchObject({ status: 'failed', method: null, paid_on: null });
    });
});

describe('salary page', () => {
    const account = (overrides: Partial<Account> = {}): Account => ({
        paymentMethod: 'bank', accountHolder: 'Nadia Karim', bankName: 'City Bank', bankBranch: 'Gulshan', routingNumber: '123456789',
        accountNumberMasked: '********1234', hasAccountNumber: true, ...overrides,
    });
    const base = {
        profile: { id: 'prof-1', employeeId: 'emp-1' },
        employee: { id: 'emp-1', number: 'EMP-0001', name: 'Nadia Karim', department: 'Finance', designation: 'Accountant', status: 'active' },
        assignments: [
            { id: 'a1', revision: 1, structureId: 's1', structureName: 'Grade A', basicAmount: 4_000_000, currency: 'BDT', effectiveFrom: '2025-01-01', reason: null, createdAt: null },
            { id: 'a2', revision: 2, structureId: 's1', structureName: 'Grade A', basicAmount: 5_000_000, currency: 'BDT', effectiveFrom: '2026-07-01', reason: 'Promotion', createdAt: null },
            { id: 'a3', revision: 3, structureId: 's1', structureName: 'Grade A', basicAmount: 5_500_000, currency: 'BDT', effectiveFrom: '2027-01-01', reason: null, createdAt: null },
        ],
        structures: [{ id: 's1', name: 'Grade A' }],
        paymentMethods: ['bank', 'cash'] as Array<'bank' | 'cash'>,
        currency: 'BDT',
        today: '2026-10-11',
    };

    it('shows only the masked account number to a viewer and no form', () => {
        const wrapper = track(mount(SalariesShow, { props: { ...base, account: account(), canManage: false }, global: { stubs } }));

        expect(wrapper.get('[data-testid="account-masked"]').text()).toBe('********1234');
        expect(wrapper.find('form').exists()).toBe(false);
        expect(buttonLabels(wrapper)).not.toContain('New revision');
        // Revision history, newest first, with the one in force and the scheduled one marked.
        expect(wrapper.text()).toContain('Current');
        expect(wrapper.text()).toContain('Scheduled');
    });

    it('keeps the stored number unless a new one is typed or removal is chosen', async () => {
        const wrapper = track(mount(SalariesShow, { props: { ...base, account: account(), canManage: true }, global: { stubs } }));
        const card = wrapper.get('[data-testid="account-card"]');

        // The field starts empty: the number itself never reaches the page.
        const number = card.findAll('input').find((input) => input.attributes('maxlength') === '60')!;
        expect((number.element as HTMLInputElement).value).toBe('');
        expect(card.text()).toContain('ends ********1234');
        expect(JSON.stringify(wrapper.html())).not.toContain('9876543210');

        await card.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)).toMatchObject({
            method: 'put',
            url: '/payroll.salaries.account.update?profile=prof-1',
            data: { account_number: null, clear_account_number: false, payment_method: 'bank' },
        });

        await number.setValue('9876543210');
        await card.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)!.data).toMatchObject({ account_number: '9876543210', clear_account_number: false });

        await card.get('input[type="checkbox"]').setValue(true);
        await card.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)!.data.clear_account_number).toBe(true);
    });
});

describe('rule set editor', () => {
    const options = { engines: ['slab'], categories: ['income_tax', 'levy'], payers: ['employee', 'employer'], bases: ['taxable_gross', 'gross'], methods: ['progressive', 'banded'], employmentTypes: ['full_time', 'contract'] };

    it('states plainly that the numbers are the workspace\'s own', () => {
        expect(en.payroll.rules.disclaimer).toContain('ships no tax or contribution law');
        expect(en.payroll.rules.disclaimer).toContain('not legal or tax advice');
        expect(bn.payroll.rules.disclaimer).toContain('Oyosend');
    });

    it('checks slab shape the way the server does', () => {
        const row = (key: number, from: number | null, to: number | null, rateBps: number | null) => ({ key, from, to, rateBps, fixed: null });

        expect(slabProblem([row(1, 0, 100, 0), row(2, 100, 500, 500), row(3, 500, null, 1000)])).toBeNull();
        expect(slabProblem([])).toBe('incomplete');
        expect(slabProblem([row(1, 0, null, null)])).toBe('incomplete');
        expect(slabProblem([row(1, 10, null, 0)])).toBe('first');
        expect(slabProblem([row(1, 0, 100, 0), row(2, 150, null, 100)])).toBe('gap');
        expect(slabProblem([row(1, 0, null, 0), row(2, 100, null, 100)])).toBe('order');
        expect(slabProblem([row(1, 0, 100, 0), row(2, 100, 200, 100)])).toBe('open');
        expect(slabPayload([row(1, 0, 100, 750)])).toEqual([{ from: 0, to: 100, rate_bps: 750, fixed: 0 }]);
    });

    it('sends bands in exact units and rates in basis points', async () => {
        const wrapper = track(mount(RuleSetDialog, {
            props: { open: true, ruleSet: null, options, currency: 'BDT', today: '2026-10-11' },
            global: { stubs },
        }));

        const text = wrapper.findAll('input').filter((input) => input.attributes('maxlength') === '120' || input.attributes('maxlength') === '40');
        await text[0]!.setValue('Example slab');
        await text[1]!.setValue('EXAMPLE');

        const band = wrapper.get('[data-testid="slab-0"]');
        const [from, to, rate] = band.findAll('input');
        await from!.setValue('0');
        await to!.setValue('');
        await rate!.setValue('7.5');

        expect(wrapper.find('[data-testid="slab-problem"]').exists()).toBe(false);
        await wrapper.get('form').trigger('submit');

        const sent = inertia.submissions.at(-1)!;
        expect(sent.url).toBe('/payroll.rules.store');
        expect(sent.data).toBeDefined();
    });
});

describe('dashboard widgets', () => {
    it('shows the latest own payslip and an empty state', () => {
        const filled = track(mount(PayrollSelfWidget, {
            props: { data: { count: 4, latest: { id: 'slip-1', number: 'PSL-000004', periodName: 'September 2026', netAmount: 5_500_000, currency: 'BDT', paymentStatus: 'paid', paidOn: '2026-10-06' } } },
        }));
        expect(filled.get('[data-testid="self-net"]').text()).toContain('55,000.00');
        expect(filled.text()).toContain('September 2026');
        expect(filled.text()).toContain('Paid on');

        const empty = track(mount(PayrollSelfWidget, { props: { data: { count: 0, latest: null } } }));
        expect(empty.text()).toContain('No payslip yet');
    });

    it('shows the overview counts and the latest run', () => {
        const wrapper = track(mount(PayrollOverviewWidget, {
            props: {
                data: {
                    currency: 'BDT', openPeriods: 1, awaitingApproval: 2, unpaidPayslips: 14, pendingLoans: 3,
                    latestRun: { id: 'run-1', number: 'RUN-000009', periodName: 'September 2026', status: 'submitted', netAmount: 11_000_000, employeeCount: 14 },
                },
            },
        }));
        expect(wrapper.get('[data-testid="awaiting-approval"]').text()).toBe('2');
        expect(wrapper.get('[data-testid="unpaid-payslips"]').text()).toBe('14');
        expect(wrapper.text()).toContain('RUN-000009');
        expect(wrapper.text()).toContain('Awaiting approval');
    });
});

describe('Bengali', () => {
    it('renders run states, codes and money in Bengali numerals', () => {
        setLocale('bn');
        inertia.page.props.app.locale = 'bn';
        inertia.page.props.app.numerals = 'beng';

        try {
            const wrapper = track(mount(RunsShow, {
                props: runProps({
                    run: run({ status: 'submitted', errors: [{ code: 'negative_net', employee_id: 'e1', employee_name: 'Nadia Karim' }] }),
                    abilities: { ...none, approve: true },
                }),
                global: { stubs },
            }));

            expect(wrapper.text()).toContain('অনুমোদনের অপেক্ষায়');
            expect(wrapper.get('[data-testid="run-errors"]').text()).toContain('Nadia Karim-এর নিট বেতন শূন্যের নিচে');
            expect(wrapper.get('[data-testid="total-net"]').text()).toMatch(/[০-৯]/);
            expect(wrapper.get('[data-testid="total-net"]').text()).not.toMatch(/[0-9]/);
        } finally {
            inertia.page.props.app.locale = 'en';
            inertia.page.props.app.numerals = 'latn';
        }
    });
});

describe('Payroll messages', () => {
    function files(directory: string): string[] {
        return readdirSync(directory).flatMap((name) => {
            const path = join(directory, name);

            return statSync(path).isDirectory() ? files(path) : [path];
        });
    }

    function lookup(tree: unknown, path: string): unknown {
        return path.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), tree);
    }

    const sources = () => files(join(process.cwd(), 'resources/js/Modules/Payroll')).filter((path) => path.endsWith('.vue') || path.endsWith('.ts'));

    it('defines every static key the Payroll pages and components use, in both languages', () => {
        const used = new Set<string>();

        for (const file of sources()) {
            const source = readFileSync(file, 'utf8');
            // Route names are quoted strings in `route(...)` calls and a few lookups; messages are those found in the catalogue.
            for (const match of source.matchAll(/'(payroll\.[\w.]+)'/g)) {
                used.add(match[1]!);
            }
        }

        expect(used.size).toBeGreaterThan(200);

        const routeName = /^payroll\.(payslips|payments|periods|runs|structures|salaries|items|loans|rules|reports|settings)\.(index|show|document|store|update|destroy|close|recalculate|submit|approve|return|reverse|decision|cancel|payment-sheet|export|adjustments\.store|payments\.store|components\.(store|update|destroy)|revisions\.store|account\.update)$/;
        const missing = [...used].filter((key) => (typeof lookup(en, key) !== 'string' || typeof lookup(bn, key) !== 'string') && !routeName.test(key));

        expect(missing).toEqual([]);
    });

    it('defines every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'payroll.status.run': ['draft', 'submitted', 'approved'],
            'payroll.status.runKind': ['regular', 'adjustment', 'reversal'],
            'payroll.status.period': ['open', 'processing', 'approved', 'paid', 'closed'],
            'payroll.status.payment': ['unpaid', 'paid', 'failed'],
            'payroll.status.loan': ['pending', 'active', 'completed', 'cancelled', 'rejected'],
            'payroll.status.item': ['pending', 'included', 'cancelled'],
            'payroll.status.installment': ['pending', 'deducted', 'cancelled'],
            'payroll.lineKinds': ['earning', 'deduction', 'reimbursement', 'employer'],
            'payroll.methods': ['bank', 'cash', 'mobile_wallet', 'cheque', 'other'],
            'payroll.periodKinds': ['monthly', 'custom'],
            'payroll.componentKinds': ['earning', 'deduction'],
            'payroll.componentCalcs': ['fixed', 'percent_basic'],
            'payroll.itemTypes': ['allowance', 'bonus', 'festival_bonus', 'deduction', 'reimbursement'],
            'payroll.loanKinds': ['loan', 'advance'],
            'payroll.ruleCategories': ['income_tax', 'social_contribution', 'levy', 'other'],
            'payroll.rulePayers': ['employee', 'employer'],
            'payroll.ruleBases': ['taxable_gross', 'gross', 'basic'],
            'payroll.ruleMethods': ['progressive', 'banded'],
            'payroll.engines': ['slab'],
            'payroll.employmentTypes': ['full_time', 'part_time', 'contract', 'intern', 'consultant'],
            'payroll.settings.daysBasis': ['calendar_days', 'fixed_days'],
            'payroll.settings.rateBase': ['basic', 'gross'],
            'payroll.rules.slabErrors': ['incomplete', 'first', 'gap', 'order', 'open'],
            'payroll.attendance': ['days_present', 'days_half', 'days_absent', 'days_on_leave', 'overtime'],
        };
        const missing: string[] = [];

        for (const [family, values] of Object.entries(families)) {
            for (const value of values) {
                for (const [locale, tree] of [['en', en], ['bn', bn]] as const) {
                    if (typeof lookup(tree, `${family}.${value}`) !== 'string') {
                        missing.push(`${locale}:${family}.${value}`);
                    }
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('defines the module name, navigation, permissions, limit and automation labels', () => {
        const permissions = [
            'structures.view', 'structures.manage', 'salaries.view', 'salaries.manage', 'periods.view', 'periods.manage', 'runs.view', 'runs.prepare',
            'runs.approve', 'runs.reverse', 'items.view', 'items.manage', 'loans.view', 'loans.manage', 'loans.approve', 'rules.view', 'rules.manage',
            'payslips.view', 'payments.record', 'payments.export', 'reports.view', 'settings.manage',
        ].map((name) => `payroll.${name}`);
        const groups = ['structures', 'salaries', 'periods', 'runs', 'items', 'loans', 'rules', 'payslips', 'payments', 'reports', 'settings'].map((name) => `payroll_${name}`);
        const nav = ['Payslips', 'Runs', 'Periods', 'Salaries', 'Items', 'Loans', 'Reports', 'Structures', 'Rules', 'Settings'].map((name) => `payroll${name}`);

        for (const tree of [en, bn]) {
            expect(typeof tree.catalogue.modules.payroll).toBe('string');

            for (const key of permissions) {
                const entry = (tree.catalogue.permissions as Record<string, { label: string; description: string }>)[key];
                expect(entry?.label, key).toBeTruthy();
                expect(entry?.description, key).toBeTruthy();
            }

            for (const key of groups) {
                expect(typeof (tree.catalogue.permissionGroups as Record<string, string>)[key], key).toBe('string');
            }

            for (const key of nav) {
                expect(typeof (tree.shell.nav as unknown as Record<string, string>)[key], key).toBe('string');
            }

            expect(tree.catalogue.limits['payroll.payslips'].label).toBeTruthy();

            for (const key of ['payroll.run.approved', 'payroll.payslip.paid', 'payroll.run.reversed']) {
                expect(typeof (tree.catalogue.automation.triggers as Record<string, string>)[key], key).toBe('string');
            }

            for (const key of ['payroll.item.create', 'payroll.run.prepare_draft']) {
                expect(typeof (tree.catalogue.automation.actions as Record<string, string>)[key], key).toBe('string');
            }
        }
    });

    it('keeps the English app text identical to the manifest', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/Payroll/PayrollServiceProvider.php'), 'utf8');

        expect(manifest).toContain(`description: '${en.appStore.catalog.payroll.description}'`);
        expect(manifest).toContain(`releaseNotes: '${en.appStore.catalog.payroll.releaseNotes}'`);
        expect(manifest).toContain(`name: '${en.appStore.catalog.payroll.name}'`);

        for (const feature of en.appStore.catalog.payroll.features) {
            expect(manifest).toContain(`'${feature}'`);
        }

        expect(bn.appStore.catalog.payroll.features).toHaveLength(en.appStore.catalog.payroll.features.length);
    });

    it('uses no raw palette colours, v-html or emoji in the Payroll templates', () => {
        const offending: string[] = [];

        for (const file of sources().filter((path) => path.endsWith('.vue'))) {
            const source = readFileSync(file, 'utf8');

            if (/\b(?:text|bg|border|ring)-(?:gray|slate|zinc|neutral|stone|red|green|blue|indigo|purple|amber|yellow|orange)-\d{2,3}\b/.test(source)) {
                offending.push(`${file}: raw palette colour`);
            }

            if (/v-html/.test(source)) {
                offending.push(`${file}: v-html`);
            }

            if (/\p{Extended_Pictographic}/u.test(source)) {
                offending.push(`${file}: emoji`);
            }
        }

        expect(offending).toEqual([]);
    });
});
