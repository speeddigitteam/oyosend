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
            auth: { permissions: ['hrm.employees.view', 'hrm.attendance.view', 'hrm.leave.view'] as string[] },
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
import AdjustBalanceDialog from '@/Modules/Hrm/Components/AdjustBalanceDialog.vue';
import ClockCard from '@/Modules/Hrm/Components/ClockCard.vue';
import HrmOverviewWidget from '@/Modules/Hrm/Components/HrmOverviewWidget.vue';
import HrmSelfWidget from '@/Modules/Hrm/Components/HrmSelfWidget.vue';
import LeaveRequestDialog from '@/Modules/Hrm/Components/LeaveRequestDialog.vue';
import StatusChangeDialog from '@/Modules/Hrm/Components/StatusChangeDialog.vue';
import {
    daysToUnits,
    fromMinorUnits,
    monthGrid,
    shiftMonth,
    toMinorUnits,
    unitsToDays,
} from '@/Modules/Hrm/Components/hrmFormat';
import type { ClockState, EmployeeDetail, EmployeeRow, LeaveRequest, LeaveType } from '@/Modules/Hrm/Components/types';
import EmployeesIndex from '@/Modules/Hrm/Pages/Employees/Index.vue';
import EmployeesShow from '@/Modules/Hrm/Pages/Employees/Show.vue';
import LeaveApprovals from '@/Modules/Hrm/Pages/Leave/Approvals.vue';
import MeShow from '@/Modules/Hrm/Pages/Me/Show.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const page = <T>(rows: T[]) => ({ data: rows, current_page: 1, last_page: 1, per_page: 25, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null, prev_page_url: null, next_page_url: null });

const annual: LeaveType = {
    id: 'type-annual', name: 'Annual leave', code: 'AL', isPaid: true, tracksBalance: true, allowsHalfDay: true, requiresReason: false,
    maxConsecutiveDays: null, minNoticeDays: 0, approvalFlow: 'manager_then_hr', isActive: true, sortOrder: 0,
};
const noHalf: LeaveType = { ...annual, id: 'type-sick', name: 'Sick leave', code: 'SL', allowsHalfDay: false, requiresReason: true };

const shift = {
    id: 'shift-1', name: 'Day', startsAt: '09:00', endsAt: '17:00', crossesMidnight: false, breakMinutes: 60, lateGraceMinutes: 10,
    earlyGraceMinutes: 0, overtimeThresholdMinutes: 30, halfDayMinutes: null, scheduledMinutes: 420, weeklyOffDays: null, isDefault: true, isActive: true,
};

function clock(overrides: Partial<ClockState> = {}): ClockState {
    return { clockedIn: false, since: null, day: null, shift, isWorkingDay: true, holiday: null, ...overrides };
}

function leaveRequest(overrides: Partial<LeaveRequest> = {}): LeaveRequest {
    return {
        id: 'req-1', employee: { id: 'emp-1', name: 'Nadia Karim', number: 'EMP-0001' },
        leaveType: { id: annual.id, name: 'Annual leave', code: 'AL', isPaid: true },
        startsOn: '2026-10-20', endsOn: '2026-10-21', startSession: 'full', endSession: 'full', units: 3, days: { '2026-10-20': 2, '2026-10-21': 1 },
        leaveYear: 2026, status: 'pending_manager', reason: null, requestedAt: '2026-10-11T04:00:00Z', decidedAt: null, cancelledAt: null,
        cancellationReason: null, approvals: [], abilities: { decide: false, step: null, cancel: false }, ...overrides,
    };
}

function employee(overrides: Partial<EmployeeDetail> = {}): EmployeeDetail {
    return {
        id: 'emp-1', number: 'EMP-0001', name: 'Nadia Karim', firstName: 'Nadia', lastName: 'Karim', status: 'active', employmentType: 'full_time',
        designation: { id: 'des-1', name: 'Accountant' }, department: { id: 'dep-1', name: 'Finance' }, branch: null, managerId: null, managerName: null,
        joinedOn: '2026-01-05', terminatedOn: null, workEmail: 'nadia@acme.test', phone: null, hasAccount: true,
        probationEndsOn: null, confirmedOn: null, statusChangedOn: null, statusReason: null, lastWorkingOn: null, terminationType: null,
        terminationReason: null, membership: null, notes: null, costRateAmount: null, costCurrency: null, personal: null, ...overrides,
    };
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['hrm.employees.view', 'hrm.attendance.view', 'hrm.leave.view'];
});
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()));

describe('HR helpers', () => {
    it('shows leave units as days: one unit is a half day', () => {
        expect(unitsToDays(1)).toBe(0.5);
        expect(unitsToDays(3)).toBe(1.5);
        expect(unitsToDays(20)).toBe(10);
        expect(daysToUnits('1.5')).toBe(3);
        expect(daysToUnits('2')).toBe(4);
        expect(daysToUnits('0.25')).toBeNull();
        expect(daysToUnits('')).toBeNull();
        expect(daysToUnits('-1')).toBeNull();
    });

    it('builds a Monday-first month grid', () => {
        // 1 October 2026 is a Thursday: three blanks, thirty-one days.
        const grid = monthGrid('2026-10');
        expect(grid.leading).toBe(3);
        expect(grid.dates).toHaveLength(31);
        expect(grid.dates[0]).toBe('2026-10-01');
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
    });

    it('converts money text to exact minor units and refuses extra decimals', () => {
        expect(toMinorUnits('12.5', 'BDT')).toBe(1250);
        expect(toMinorUnits('12', 'BDT')).toBe(1200);
        expect(toMinorUnits('12.505', 'BDT')).toBeNull();
        expect(toMinorUnits('abc', 'BDT')).toBeNull();
        expect(toMinorUnits('500', 'JPY')).toBe(500);
        expect(fromMinorUnits(1250, 'BDT')).toBe('12.50');
        expect(fromMinorUnits(null, 'BDT')).toBe('');
    });
});

describe('clock card', () => {
    it('offers clock in when out and clock out, with the start time, when in', () => {
        const out = track(mount(ClockCard, { props: { clock: clock(), canClock: true } }));
        expect(out.get('[data-testid="clock-idle"]').text()).toContain('working day');
        expect(out.get('button[type="submit"]').text()).toBe('Clock in');

        const live = track(mount(ClockCard, {
            props: { clock: clock({ clockedIn: true, since: new Date(Date.now() - 95 * 60_000).toISOString() }), canClock: true },
        }));
        expect(live.get('[data-testid="clock-since"]').text()).toContain('Since');
        expect(live.get('[data-testid="clock-elapsed"]').text()).toContain('1 h 35 min');
        expect(live.get('button[type="submit"]').text()).toBe('Clock out');
    });

    it('posts to the clock endpoints without sending a time', async () => {
        const out = track(mount(ClockCard, { props: { clock: clock(), canClock: true } }));
        await out.get('form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'post', url: '/hrm.attendance.clock-in' });
        expect(Object.keys(inertia.submissions.at(-1)!.data)).toEqual(['note']);

        const live = track(mount(ClockCard, { props: { clock: clock({ clockedIn: true, since: new Date().toISOString() }), canClock: true } }));
        await live.get('form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'post', url: '/hrm.attendance.clock-out' });
    });

    it('hides the button for an account that cannot clock and names the holiday', () => {
        const wrapper = track(mount(ClockCard, { props: { clock: clock({ holiday: 'Victory Day', isWorkingDay: false }), canClock: false } }));
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Victory Day');
        expect(wrapper.text()).toContain("can't clock in or out");
    });
});

describe('dashboard widgets', () => {
    it('shows the self widget for a linked and an unlinked account', () => {
        const linked = track(mount(HrmSelfWidget, { props: { data: { hasProfile: true, clockedIn: true, leaveBalanceUnits: 21, pendingRequests: 2, canClock: true } } }));
        expect(linked.get('[data-testid="self-clock-state"]').text()).toBe('Clocked in');
        expect(linked.get('[data-testid="self-balance"]').text()).toBe('10.5 days');
        expect(linked.text()).toContain('Clock out');

        const unlinked = track(mount(HrmSelfWidget, { props: { data: { hasProfile: false, clockedIn: false, leaveBalanceUnits: 0, pendingRequests: 0, canClock: false } } }));
        expect(unlinked.text()).toContain('No employee record');
        expect(unlinked.find('button').exists()).toBe(false);
    });

    it('shows the overview numbers and an empty state', () => {
        const filled = track(mount(HrmOverviewWidget, {
            props: { data: { headcount: 24, onProbation: 3, onLeaveToday: 2, clockedInNow: 17, pendingLeave: 4, pendingCorrections: 1 } },
        }));
        expect(filled.text()).toContain('24');
        expect(filled.text()).toContain('Leave to decide');

        const empty = track(mount(HrmOverviewWidget, {
            props: { data: { headcount: 0, onProbation: 0, onLeaveToday: 0, clockedInNow: 0, pendingLeave: 0, pendingCorrections: 0 } },
        }));
        expect(empty.text()).toContain('No employees yet');
        expect(empty.text()).not.toContain('Add employee');

        inertia.page.props.auth.permissions = ['hrm.employees.create'];
        const creator = track(mount(HrmOverviewWidget, {
            props: { data: { headcount: 0, onProbation: 0, onLeaveToday: 0, clockedInNow: 0, pendingLeave: 0, pendingCorrections: 0 } },
        }));
        expect(creator.text()).toContain('Add employee');
    });
});

describe('leave dialogs', () => {
    it('sends a half day with the same session at both ends and only offers half days where allowed', async () => {
        const wrapper = track(mount(LeaveRequestDialog, { props: { open: true, types: [annual, noHalf], today: '2026-10-12' }, global: { stubs } }));
        // A one-day request on a type that allows half days offers a duration choice.
        const selects = wrapper.findAll('select');
        expect(selects).toHaveLength(2);
        await selects[1]!.setValue('second_half');
        await wrapper.get('form').trigger('submit');

        const sent = inertia.submissions.at(-1)!;
        expect(sent.url).toBe('/hrm.leave.requests.store');
        expect(sent.data).toMatchObject({ leave_type_id: 'type-annual', starts_on: '2026-10-12', ends_on: '2026-10-12', start_session: 'second_half', end_session: 'second_half', reason: null });

        // The second type does not allow half days and needs a reason.
        await selects[0]!.setValue('type-sick');
        expect(wrapper.findAll('select')).toHaveLength(1);
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('turns days typed into whole units for a balance adjustment', async () => {
        const wrapper = track(mount(AdjustBalanceDialog, {
            props: { open: true, employee: { id: 'emp-1', name: 'Nadia Karim' }, types: [annual], year: 2026 },
            global: { stubs },
        }));
        const days = wrapper.get('input[inputmode="decimal"]');
        const note = wrapper.get('textarea');

        await days.setValue('-1.5');
        await note.setValue('Correction');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data).toMatchObject({ employee_id: 'emp-1', leave_type_id: 'type-annual', units: -3, leave_year: 2026, note: 'Correction' });

        // A quarter day is not a unit, and zero is not an adjustment.
        inertia.submissions.length = 0;
        await days.setValue('0.25');
        await wrapper.get('form').trigger('submit');
        await days.setValue('0');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions).toHaveLength(0);
    });

    it('asks for a reason and a type before ending employment', async () => {
        const wrapper = track(mount(StatusChangeDialog, {
            props: { open: true, employeeId: 'emp-1', options: ['terminated', 'suspended'], terminationTypes: ['resignation', 'dismissal'], today: '2026-10-12' },
            global: { stubs },
        }));
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
        const [status, type] = wrapper.findAll('select');
        expect(status!.element).toBeTruthy();
        await type!.setValue('resignation');
        await wrapper.get('textarea').setValue('Moved abroad');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions.at(-1)).toMatchObject({
            url: '/hrm.employees.status?employee=emp-1',
            data: { status: 'terminated', termination_type: 'resignation', reason: 'Moved abroad', last_working_on: '2026-10-12' },
        });
    });
});

describe('HR pages', () => {
    const buttonLabels = (wrapper: VueWrapper) => wrapper.findAll('button, a').map((item) => item.text());

    const meProps = (abilities: Partial<Record<'clock' | 'requestLeave' | 'requestCorrection' | 'viewLeave' | 'viewAttendance', boolean>> = {}) => ({
        hasProfile: true,
        employee: { ...employee(), confirmedOn: null, probationEndsOn: null },
        today: '2026-10-11',
        clock: clock(),
        month: '2026-10',
        days: [],
        leaveYear: 2026,
        balances: [{ leaveType: annual, units: 3 }, { leaveType: { ...annual, id: 'type-casual', name: 'Casual leave' }, units: 20 }],
        leaveTypes: [annual],
        sessions: ['full', 'first_half', 'second_half'],
        requests: [leaveRequest({ abilities: { cancel: true } })],
        corrections: [],
        upcomingHolidays: [{ id: 'h1', name: 'Victory Day', date: '2026-12-16', type: 'public' as const }],
        abilities: { clock: true, requestLeave: true, requestCorrection: true, viewLeave: true, viewAttendance: true, ...abilities },
    });

    it('shows balances in days and only the actions the server allows on My HR', () => {
        const full = track(mount(MeShow, { props: meProps(), global: { stubs } }));
        const balances = full.findAll('[data-testid="balance-value"]').map((item) => item.text());
        expect(balances).toEqual(['1.5 days', '10 days']);
        expect(buttonLabels(full)).toContain('Request leave');
        expect(buttonLabels(full)).toContain('Request correction');
        expect(full.text()).toContain('Victory Day');
        expect(full.text()).toContain('Awaiting manager');

        const limited = track(mount(MeShow, {
            props: { ...meProps({ requestLeave: false, requestCorrection: false, clock: false }), requests: [leaveRequest()] },
            global: { stubs },
        }));
        expect(buttonLabels(limited)).not.toContain('Request leave');
        expect(buttonLabels(limited)).not.toContain('Request correction');
        expect(limited.find('[data-testid="clock-card"] button[type="submit"]').exists()).toBe(false);
    });

    it('explains an account without an employee record', () => {
        const wrapper = track(mount(MeShow, { props: { hasProfile: false, employee: null }, global: { stubs } }));
        expect(wrapper.text()).toContain('No employee record');
        expect(wrapper.find('[data-testid="clock-card"]').exists()).toBe(false);
    });

    it('renders My HR in Bengali with Bengali digits', () => {
        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const wrapper = track(mount(MeShow, { props: meProps(), global: { stubs } }));
        expect(wrapper.text()).toContain('আমার এইচআর');
        expect(wrapper.text()).toContain('ছুটির ব্যালেন্স');
        const balances = wrapper.findAll('[data-testid="balance-value"]').map((item) => item.text());
        expect(balances).toEqual(['১.৫ দিন', '১০ দিন']);
    });

    it('lists employees with the add button only for people who may create', () => {
        const props = {
            employees: page<EmployeeRow>([{
                id: 'emp-1', number: 'EMP-0001', name: 'Nadia Karim', firstName: 'Nadia', lastName: 'Karim', status: 'probation', employmentType: 'full_time',
                designation: { id: 'd', name: 'Accountant' }, department: { id: 'p', name: 'Finance' }, branch: null, managerId: null, managerName: null,
                joinedOn: '2026-01-05', terminatedOn: null, workEmail: 'nadia@acme.test', phone: null, hasAccount: true,
            }]),
            filters: { q: '', status: '', department_id: '', branch_id: '', manager_id: '', employment_type: '' },
            statuses: ['probation', 'active', 'on_leave', 'suspended', 'terminated'] as ('probation' | 'active' | 'on_leave' | 'suspended' | 'terminated')[],
            employmentTypes: ['full_time', 'part_time'] as ('full_time' | 'part_time')[],
            departments: [{ id: 'p', name: 'Finance' }],
            branches: [],
        };
        const viewer = track(mount(EmployeesIndex, { props: { ...props, canCreate: false } }));
        expect(viewer.text()).toContain('Nadia Karim');
        expect(viewer.text()).toContain('On probation');
        expect(viewer.text()).not.toContain('Add employee');

        const creator = track(mount(EmployeesIndex, { props: { ...props, canCreate: true } }));
        expect(creator.text()).toContain('Add employee');

        setLocale('bn');
        const bengali = track(mount(EmployeesIndex, { props: { ...props, canCreate: true } }));
        expect(bengali.text()).toContain('শিক্ষানবিশ');
        expect(bengali.text()).toContain('কর্মী যোগ করুন');
    });

    it('shows the personal block only when the server sent it, and gates the record actions', () => {
        const base = {
            employee: employee(),
            reports: [],
            history: [{ id: 'h1', event: 'joined' as const, effectiveOn: '2026-01-05', changes: { status: { from: null, to: 'probation' } }, reason: null, recordedAt: null }],
            emergencyContacts: [],
            documents: [],
            assets: [],
            statusOptions: ['suspended' as const],
            terminationTypes: ['resignation' as const],
            form: null,
        };
        const noAbilities = { edit: false, editEmployment: false, changeStatus: false, viewPersonal: false, manageRecords: false, isSelf: false };

        const plain = track(mount(EmployeesShow, { props: { ...base, abilities: noAbilities }, global: { stubs } }));
        expect(plain.find('[data-testid="personal-block"]').exists()).toBe(false);
        expect(buttonLabels(plain)).not.toContain('Edit');
        expect(buttonLabels(plain)).not.toContain('Change status');
        // Contacts and documents are personal data: no tab for someone without access.
        expect(plain.text()).not.toContain('Emergency contacts');
        expect(plain.text()).toContain('Employment history');

        const personal = {
            personalEmail: 'nadia@home.test', dateOfBirth: '1994-03-02', gender: 'female' as const, nationalId: '1234567890',
            address: 'Road 5', city: 'Dhaka', country: 'BD',
        };
        const hr = track(mount(EmployeesShow, {
            props: {
                ...base,
                employee: employee({ personal, costRateAmount: 25000, costCurrency: 'BDT' }),
                abilities: { edit: true, editEmployment: true, changeStatus: true, viewPersonal: true, manageRecords: true, isSelf: false },
                form: { departments: [], branches: [], designations: [], managers: [], members: [], employmentTypes: ['full_time'], genders: ['female'] },
            },
            global: { stubs },
        }));
        expect(hr.get('[data-testid="personal-block"]').text()).toContain('nadia@home.test');
        expect(buttonLabels(hr)).toContain('Edit');
        expect(buttonLabels(hr)).toContain('Change status');
        expect(hr.text()).toContain('Emergency contacts');
        expect(hr.text()).toMatch(/BDT\s250\.00 per hour/);
    });

    it('decides leave only where the row says the person may', async () => {
        const rows = [
            leaveRequest({ id: 'a', abilities: { decide: true, step: 'manager', cancel: false } }),
            leaveRequest({ id: 'b', employee: { id: 'emp-2', name: 'Rafi Ahmed', number: 'EMP-0002' }, abilities: { decide: false, step: null, cancel: true } }),
        ];
        const wrapper = track(mount(LeaveApprovals, { props: { requests: page(rows) }, global: { stubs } }));
        const labels = buttonLabels(wrapper);
        expect(labels.filter((label) => label === 'Approve')).toHaveLength(1);
        expect(labels.filter((label) => label === 'Reject')).toHaveLength(1);
        expect(labels.filter((label) => label === 'Cancel')).toHaveLength(1);
        // Three units are a day and a half.
        expect(wrapper.findAll('[data-testid="request-days"]')[0]!.text()).toBe('1.5 days');
    });
});

describe('HR messages', () => {
    function files(directory: string): string[] {
        return readdirSync(directory).flatMap((name) => {
            const path = join(directory, name);

            return statSync(path).isDirectory() ? files(path) : [path];
        });
    }

    function lookup(tree: unknown, path: string): unknown {
        return path.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), tree);
    }

    // Route names look like keys (`hrm.leave.index`); they are not messages.
    const isRoute = (key: string) => /^hrm\.(me|employees\.(index|create|store|show|update|status|contacts|documents|assets)|attendance\.(index|summary|employee|clock-in|clock-out|entries|shift-assignments|corrections\.(index|store|decision|cancel))|leave\.(index|approvals|balances|calendar|requests\.(store|show|decision|cancel)|adjustments)|holidays\.(index|store|update|destroy)|reports\.index|settings\.(index|update|designations\.(store|update)|shifts\.(store|update)|leave-types|leave-policies))/.test(key);

    it('defines every static key the HR pages and components use, in both languages', () => {
        const pattern = /\bt\(\s*(?:[\w.!]+\s*\?\s*)?'(hrm\.[\w.]+)'(?:\s*:\s*'(hrm\.[\w.]+)')?/g;
        const ternary = /\?\s*t\('(hrm\.[\w.]+)'\)\s*:\s*t\('(hrm\.[\w.]+)'\)/g;
        const used = new Set<string>();

        for (const file of files(join(process.cwd(), 'resources/js/Modules/Hrm')).filter((path) => path.endsWith('.vue'))) {
            const source = readFileSync(file, 'utf8');

            for (const match of source.matchAll(pattern)) {
                used.add(match[1]!);
                if (match[2]) used.add(match[2]);
            }

            for (const match of source.matchAll(ternary)) {
                used.add(match[1]!);
                used.add(match[2]!);
            }
        }

        expect(used.size).toBeGreaterThan(150);

        const missing = [...used].filter((key) => !isRoute(key) && (typeof lookup(en, key) !== 'string' || typeof lookup(bn, key) !== 'string'));
        expect(missing).toEqual([]);
    });

    it('defines every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'hrm.status.employee': ['probation', 'active', 'on_leave', 'suspended', 'terminated'],
            'hrm.status.day': ['present', 'half_day', 'absent', 'on_leave', 'holiday', 'weekly_off', 'incomplete', 'not_recorded'],
            'hrm.status.leave': ['pending_manager', 'pending_hr', 'approved', 'rejected', 'cancelled'],
            'hrm.status.correction': ['pending', 'approved', 'rejected', 'cancelled'],
            'hrm.status.holiday': ['public', 'optional'],
            'hrm.employmentType': ['full_time', 'part_time', 'contract', 'intern', 'consultant'],
            'hrm.gender': ['female', 'male', 'other'],
            'hrm.termination': ['resignation', 'dismissal', 'end_of_contract', 'retirement', 'redundancy', 'other'],
            'hrm.session': ['full', 'first_half', 'second_half', 'half'],
            'hrm.flow': ['manager_then_hr', 'manager_only', 'hr_only'],
            'hrm.accrual': ['annual', 'monthly'],
            'hrm.documents.types': ['contract', 'identity', 'certificate', 'offer', 'other'],
            'hrm.attendance.sources': ['clock', 'manual', 'regularization', 'device'],
            'hrm.leave.steps': ['manager', 'hr'],
            'hrm.nav': ['day', 'summary', 'corrections', 'requests', 'approvals', 'balances', 'calendar'],
            'hrm.settings.tabs': ['general', 'designations', 'shifts', 'leaveTypes', 'policies'],
            'hrm.history.events': ['joined', 'status_changed', 'updated', 'terminated', 'reinstated', 'membership_linked'],
            'hrm.history.fields': [
                'status', 'employment_type', 'department_id', 'branch_id', 'designation_id', 'manager_id', 'owner_id', 'probation_ends_on',
                'confirmed_on', 'terminated_on', 'last_working_on', 'termination_type', 'cost_rate_amount', 'cost_currency',
            ],
            'hrm.units': ['dayOne', 'dayMany', 'hours', 'hoursMinutes', 'minutes', 'kb', 'mb'],
        };

        for (const [family, keys] of Object.entries(families)) {
            for (const key of keys) {
                expect(typeof lookup(en, `${family}.${key}`), `en ${family}.${key}`).toBe('string');
                expect(typeof lookup(bn, `${family}.${key}`), `bn ${family}.${key}`).toBe('string');
            }
        }
    });

    it('labels the module, permissions, limit, sidebar entries and app text the server announces', () => {
        const permissions = [
            'hrm.employees.view', 'hrm.employees.create', 'hrm.employees.manage', 'hrm.employees.lifecycle', 'hrm.personal.view', 'hrm.self.view',
            'hrm.attendance.view', 'hrm.attendance.manage', 'hrm.attendance.clock', 'hrm.attendance.regularize', 'hrm.attendance.approve',
            'hrm.leave.view', 'hrm.leave.request', 'hrm.leave.approve', 'hrm.leave.finalize', 'hrm.leave.manage',
            'hrm.holidays.view', 'hrm.holidays.manage', 'hrm.reports.view', 'hrm.settings.manage',
        ];

        for (const messages of [en, bn]) {
            expect(typeof messages.catalogue.modules.hrm).toBe('string');
            expect(typeof (messages.catalogue.limits as Record<string, { label: string }>)['hrm.employees']?.label).toBe('string');
            expect(typeof messages.appStore.catalog.hrm.name).toBe('string');
            expect(messages.appStore.catalog.hrm.features).toHaveLength(4);

            for (const permission of permissions) {
                const entry = (messages.catalogue.permissions as Record<string, { label: string; description: string }>)[permission];
                expect(entry?.label, permission).toBeTruthy();
                expect(entry?.description, permission).toBeTruthy();
            }

            for (const group of ['employees', 'self', 'attendance', 'leave', 'calendar', 'reports', 'settings']) {
                expect((messages.catalogue.permissionGroups as Record<string, string>)[`hrm_${group}`], group).toBeTruthy();
            }

            for (const key of ['hrmMe', 'hrmEmployees', 'hrmAttendance', 'hrmLeave', 'hrmLeaveApprovals', 'hrmHolidays', 'hrmReports', 'hrmSettings']) {
                expect((messages.shell.nav as unknown as Record<string, string>)[key], key).toBeTruthy();
            }

            const automation = messages.catalogue.automation;
            expect(Object.keys(automation.triggers).filter((key) => key.startsWith('hrm.'))).toHaveLength(5);
            expect(Object.keys(automation.actions).filter((key) => key.startsWith('hrm.'))).toHaveLength(2);
        }
    });

    it('keeps the English app text identical to the manifest', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/Hrm/HrmServiceProvider.php'), 'utf8');

        expect(manifest).toContain(`description: '${en.appStore.catalog.hrm.description}'`);
        expect(manifest).toContain(`releaseNotes: '${en.appStore.catalog.hrm.releaseNotes}'`);

        for (const feature of en.appStore.catalog.hrm.features) {
            expect(manifest).toContain(`'${feature}'`);
        }
    });

    it('uses no raw palette colours or hard-coded text in the HR templates', () => {
        const offending: string[] = [];

        for (const file of files(join(process.cwd(), 'resources/js/Modules/Hrm')).filter((path) => path.endsWith('.vue'))) {
            const source = readFileSync(file, 'utf8');

            if (/\b(?:text|bg|border|ring)-(?:gray|slate|zinc|neutral|stone|red|green|blue|indigo|purple|amber|yellow|orange)-\d{2,3}\b/.test(source)) {
                offending.push(`${file}: raw palette colour`);
            }

            if (/v-html/.test(source)) {
                offending.push(`${file}: v-html`);
            }
        }

        expect(offending).toEqual([]);
    });
});
