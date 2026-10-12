import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
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
            auth: { permissions: ['talent.reviews.view', 'talent.applications.manage'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    visits: [] as Visit[],
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
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
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key] instanceof File ? form[key] : JSON.parse(JSON.stringify(toRaw(form[key]) ?? null))]));
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
import { toMinorUnits, fromMinorUnits } from '@/Core/lib/minorUnits';
import ApplicationBoard from '@/Modules/Talent/Components/ApplicationBoard.vue';
import CalibrateDialog from '@/Modules/Talent/Components/CalibrateDialog.vue';
import CandidateDialog from '@/Modules/Talent/Components/CandidateDialog.vue';
import InterviewDialog from '@/Modules/Talent/Components/InterviewDialog.vue';
import RatingsForm from '@/Modules/Talent/Components/RatingsForm.vue';
import ReviewFormDialog from '@/Modules/Talent/Components/ReviewFormDialog.vue';
import TalentOverviewWidget from '@/Modules/Talent/Components/TalentOverviewWidget.vue';
import TalentSelfWidget from '@/Modules/Talent/Components/TalentSelfWidget.vue';
import {
    TOTAL_WEIGHT_BP,
    bpToPercentText,
    parseInteger,
    percentToBp,
    scorePlain,
    scoreToHundredths,
    weightsTotal,
} from '@/Modules/Talent/Components/talentFormat';
import type { ApplicationRow, InterviewRow, ReviewDetail, ReviewFormDetail, Stage } from '@/Modules/Talent/Components/types';
import ApplicationsIndex from '@/Modules/Talent/Pages/Applications/Index.vue';
import InterviewsShow from '@/Modules/Talent/Pages/Interviews/Show.vue';
import MeShow from '@/Modules/Talent/Pages/Me/Show.vue';
import ReviewsShow from '@/Modules/Talent/Pages/Reviews/Show.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const page = <T>(rows: T[]) => ({ data: rows, current_page: 1, last_page: 1, per_page: 25, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null, prev_page_url: null, next_page_url: null });

/** The control a label belongs to. */
function field(wrapper: VueWrapper, label: string) {
    const element = wrapper.findAll('label').find((item) => item.text().startsWith(label));
    expect(element, `label ${label}`).toBeTruthy();

    return wrapper.get(`[id="${element!.attributes('for')}"]`);
}

const stages: Stage[] = [
    { id: 'st-applied', name: 'Applied', type: 'active', position: 10 },
    { id: 'st-interview', name: 'Interview', type: 'active', position: 20 },
    { id: 'st-hired', name: 'Hired', type: 'hired', position: 30 },
    { id: 'st-rejected', name: 'Rejected', type: 'rejected', position: 40 },
];

function application(overrides: Partial<ApplicationRow> = {}): ApplicationRow {
    return {
        id: 'app-1',
        status: 'open',
        appliedOn: '2026-10-01',
        source: null,
        stage: { id: 'st-applied', name: 'Applied', type: 'active' },
        opening: { id: 'op-1', title: 'Accountant', status: 'open' },
        candidate: { id: 'cand-1', name: 'Rahim Uddin', headline: 'Senior accountant', isAnonymized: false, email: null, phone: null },
        rejectionReasonId: null,
        rejectionNote: null,
        startOn: null,
        hiredAt: null,
        statusChangedAt: null,
        ...overrides,
    };
}

const formDetail: ReviewFormDetail = {
    id: 'form-1',
    name: 'Annual review',
    description: null,
    scaleMax: 5,
    isActive: true,
    items: [
        { id: 'item-1', name: 'Quality of work', description: null, weightBp: 6000, position: 0 },
        { id: 'item-2', name: 'Teamwork', description: null, weightBp: 4000, position: 1 },
    ],
};

function review(overrides: Partial<ReviewDetail> = {}): ReviewDetail {
    return {
        id: 'rev-1',
        cycleId: 'cycle-1',
        employee: { id: 'emp-1', number: 'EMP-0001', name: 'Nadia Karim' },
        reviewer: { id: 'emp-2', number: 'EMP-0002', name: 'Imran Hossain' },
        status: 'pending_calibration',
        selfScore: 435,
        managerScore: 380,
        finalScore: null,
        finalOverridden: false,
        selfSubmittedAt: null,
        managerSubmittedAt: null,
        calibratedAt: null,
        acknowledgedAt: null,
        scoreText: { self: '4.35', manager: '3.80', final: null },
        selfComment: null,
        managerComment: null,
        calibrationNote: null,
        employeeComment: null,
        ...overrides,
    };
}

const cycle = {
    id: 'cycle-1', name: '2026 annual review', status: 'calibration' as const, formId: 'form-1', startsOn: '2026-01-01', endsOn: '2026-12-31',
    selfDueOn: null, managerDueOn: null, requiresSelfReview: true, departmentId: null, launchedAt: null, closedAt: null, reviews: 1,
};

const noAbilities = { submitSelf: false, submitManager: false, calibrate: false, acknowledge: false, assignReviewer: false };

function interview(overrides: Partial<InterviewRow> = {}): InterviewRow {
    return {
        id: 'int-1', applicationId: 'app-1', roundName: 'Technical round', mode: 'video', status: 'scheduled', outcome: null,
        startsAt: '2026-10-20T04:00:00+00:00', endsAt: '2026-10-20T05:00:00+00:00', timezone: 'Asia/Dhaka', date: '2026-10-20', time: '10:00',
        endTime: '11:00', durationMinutes: 60, location: null, notes: null,
        panel: [{ id: 'emp-2', number: 'EMP-0002', name: 'Imran Hossain' }, { id: 'emp-3', number: 'EMP-0003', name: 'Farah Khan' }],
        scorecards: [], scorecardCount: 0, abilities: { edit: false, cancel: false, complete: false, feedback: false }, ...overrides,
    };
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['talent.reviews.view', 'talent.applications.manage'];
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('talent helpers', () => {
    it('adds weights in basis points and compares them with exactly 10 000', () => {
        expect(TOTAL_WEIGHT_BP).toBe(10_000);
        expect(weightsTotal([6000, 4000])).toBe(10_000);
        expect(weightsTotal([6000, '3000', '', null, undefined, -5, 1.5])).toBe(9000);
    });

    it('converts percent text to exact basis points and back', () => {
        expect(percentToBp('60')).toBe(6000);
        expect(percentToBp('33.33')).toBe(3333);
        expect(percentToBp('12,5')).toBe(1250);
        expect(percentToBp('12.345')).toBeNull();
        expect(percentToBp('abc')).toBeNull();
        expect(bpToPercentText(1250)).toBe('12.5');
        expect(bpToPercentText(6000)).toBe('60');
        expect(bpToPercentText(3333)).toBe('33.33');
    });

    it('keeps scores in integer hundredths', () => {
        expect(scoreToHundredths('4.35')).toBe(435);
        expect(scoreToHundredths('4,5')).toBe(450);
        expect(scoreToHundredths('4')).toBe(400);
        expect(scoreToHundredths('4.355')).toBeNull();
        expect(scorePlain(435)).toBe('4.35');
        expect(scorePlain(400)).toBe('4.00');
        expect(scorePlain(105)).toBe('1.05');
    });

    it('parses whole numbers and exact money', () => {
        expect(parseInteger('12')).toBe(12);
        expect(parseInteger('-12', true)).toBe(-12);
        expect(parseInteger('-12')).toBeNull();
        expect(parseInteger('1.5')).toBeNull();
        expect(toMinorUnits('1250.5', 'BDT')).toBe(125_050);
        expect(toMinorUnits('12.505', 'BDT')).toBeNull();
        expect(fromMinorUnits(125_050, 'BDT')).toBe('1250.50');
    });
});

describe('application board', () => {
    const applications = [
        application(),
        application({ id: 'app-2', stage: { id: 'st-interview', name: 'Interview', type: 'active' }, candidate: { ...application().candidate, id: 'cand-2', name: 'Salma Akter' } }),
        application({ id: 'app-3', status: 'hired', stage: { id: 'st-hired', name: 'Hired', type: 'hired' }, candidate: { ...application().candidate, id: 'cand-3', name: 'Karim Ali' } }),
    ];

    it('groups cards by stage and moves a card from the keyboard accessible menu', async () => {
        const wrapper = track(mount(ApplicationBoard, { props: { applications, stages, canMove: true } }));

        expect(wrapper.findAll('section')).toHaveLength(4);
        expect(wrapper.get('[data-stage="st-applied"]').text()).toContain('Rahim Uddin');
        expect(wrapper.get('[data-stage="st-interview"]').text()).toContain('Salma Akter');
        expect(wrapper.get('[data-stage="st-hired"]').text()).toContain('Karim Ali');

        // Only open applications move, and only into the other active stage.
        const select = wrapper.get('[data-application="app-1"] select');
        expect(select.findAll('option').map((option) => option.text())).toEqual(['Move to stage', 'Interview']);
        expect(wrapper.find('[data-application="app-3"] select').exists()).toBe(false);

        await select.setValue('st-interview');
        const [moved, target] = wrapper.emitted('move')![0] as [ApplicationRow, Stage];
        expect(moved.id).toBe('app-1');
        expect(target.id).toBe('st-interview');
    });

    it('moves a card dropped on an active column and ignores drops on the hired column', async () => {
        const wrapper = track(mount(ApplicationBoard, { props: { applications, stages, canMove: true } }));

        await wrapper.get('[data-application="app-1"]').trigger('dragstart');
        await wrapper.get('[data-stage="st-hired"]').trigger('drop');
        expect(wrapper.emitted('move')).toBeUndefined();

        await wrapper.get('[data-application="app-1"]').trigger('dragstart');
        await wrapper.get('[data-stage="st-interview"]').trigger('drop');
        expect((wrapper.emitted('move')![0] as [ApplicationRow, Stage])[1].id).toBe('st-interview');
    });

    it('offers no way to move for someone who may only view', () => {
        const wrapper = track(mount(ApplicationBoard, { props: { applications, stages, canMove: false } }));

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('[draggable="true"]').exists()).toBe(false);
    });

    it('posts the move from the applications page', async () => {
        const wrapper = track(mount(ApplicationsIndex, {
            props: {
                applications: page(applications),
                filters: { opening_id: 'op-1', stage_id: '', status: 'open', q: '' },
                stages,
                openings: [{ id: 'op-1', title: 'Accountant', status: 'open' as const }],
                statuses: ['open', 'hired', 'rejected', 'withdrawn'] as ('open' | 'hired' | 'rejected' | 'withdrawn')[],
            },
            global: { stubs },
        }));

        await wrapper.get('[data-application="app-1"] select').setValue('st-interview');
        expect(inertia.router.post).toHaveBeenCalledWith('/talent.applications.move?application=app-1', { stage_id: 'st-interview' }, { preserveScroll: true });
    });

    it('falls back to the list when no job opening is chosen', () => {
        const wrapper = track(mount(ApplicationsIndex, {
            props: {
                applications: page(applications),
                filters: { opening_id: '', stage_id: '', status: 'open', q: '' },
                stages: [],
                openings: [],
                statuses: ['open', 'hired', 'rejected', 'withdrawn'] as ('open' | 'hired' | 'rejected' | 'withdrawn')[],
            },
            global: { stubs },
        }));

        expect(wrapper.find('[data-testid="application-board"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="board-hint"]').text()).toContain('Choose a job opening');
        expect(wrapper.text()).toContain('Rahim Uddin');
    });
});

describe('review forms and ratings', () => {
    it('shows the running weight total and only allows a form that adds up to 100 percent', async () => {
        const wrapper = track(mount(ReviewFormDialog, { props: { open: true, reviewForm: null, locked: false }, global: { stubs } }));

        const total = () => wrapper.get('[data-testid="weights-total"]').text();
        const submit = () => wrapper.get('button[type="submit"]');

        await field(wrapper, 'Name').setValue('Annual review');
        await field(wrapper, 'Competency').setValue('Quality of work');
        await field(wrapper, 'Weight in percent').setValue('60');
        expect(total()).toContain('60%');
        expect(total()).toContain('must be exactly 100%');
        expect(submit().attributes('disabled')).toBeDefined();

        await wrapper.findAll('button').find((button) => button.text() === 'Add competency')!.trigger('click');
        const second = wrapper.get('[data-testid="competency-1"]');
        await second.findAll('input')[0]!.setValue('Teamwork');
        await second.findAll('input')[1]!.setValue('40');
        expect(total()).toContain('100%');
        expect(total()).not.toContain('must be');
        expect(submit().attributes('disabled')).toBeUndefined();

        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)).toMatchObject({
            method: 'post',
            url: '/talent.forms.store',
            data: {
                name: 'Annual review',
                scale_max: 5,
                items: [{ name: 'Quality of work', weight_bp: 6000 }, { name: 'Teamwork', weight_bp: 4000 }],
            },
        });
    });

    it('locks the competencies of a form used by a launched cycle', async () => {
        const wrapper = track(mount(ReviewFormDialog, { props: { open: true, reviewForm: formDetail, locked: true }, global: { stubs } }));

        expect(wrapper.find('[data-testid="weights-total"]').exists()).toBe(false);
        // The competencies and the scale cannot be edited once a cycle has been launched.
        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.findAll('button').map((button) => button.text())).not.toContain('Add competency');
        await wrapper.get('form').trigger('submit');
        const sent = inertia.submissions.at(-1)!;
        expect(sent.url).toBe('/talent.forms.update?form=form-1');
        expect(sent.data).not.toHaveProperty('items');
    });

    it('needs every competency rated before a review step can be submitted', async () => {
        const wrapper = track(mount(RatingsForm, { props: { reviewId: 'rev-1', party: 'manager', items: formDetail.items, scaleMax: 5 } }));
        const submit = () => wrapper.get('button[type="submit"]');

        expect(submit().attributes('disabled')).toBeDefined();
        // First competency: rate 4 on the five point scale.
        const groups = wrapper.findAll('[role="radiogroup"]');
        expect(groups).toHaveLength(2);
        await groups[0]!.findAll('button')[3]!.trigger('click');
        expect(submit().attributes('disabled')).toBeDefined();
        await groups[1]!.findAll('button')[4]!.trigger('click');
        expect(submit().attributes('disabled')).toBeUndefined();

        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)).toMatchObject({
            url: '/talent.reviews.manager?review=rev-1',
            data: { ratings: { 'item-1': { rating: 4 }, 'item-2': { rating: 5 } } },
        });
    });

    it('requires a note and an in-scale score to override the manager score when calibrating', async () => {
        const wrapper = track(mount(CalibrateDialog, { props: { open: true, reviewId: 'rev-1', managerScore: 380, scaleMax: 5 }, global: { stubs } }));
        const submit = () => wrapper.get('button[type="submit"]');

        expect(wrapper.text()).toContain('The manager’s score is 3.80');
        expect(submit().attributes('disabled')).toBeUndefined();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data).toEqual({ final_score: null, note: null });

        await wrapper.get('input[type="checkbox"]').setValue(true);
        expect(submit().attributes('disabled')).toBeDefined();
        await field(wrapper, 'Final score').setValue('6');
        await wrapper.get('textarea').setValue('Strong year');
        expect(submit().attributes('disabled')).toBeDefined();
        await field(wrapper, 'Final score').setValue('4.25');
        expect(submit().attributes('disabled')).toBeUndefined();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data).toEqual({ final_score: 425, note: 'Strong year' });
    });
});

describe('reviews and the blind release', () => {
    it('shows scores in hundredths as decimals, in the person’s numerals', () => {
        const calibrated = review({ status: 'calibrated', finalScore: 400, finalOverridden: true, scoreText: { self: '4.35', manager: '3.80', final: '4.00' } });
        const ratings = [
            { itemId: 'item-1', party: 'self' as const, rating: 5, comment: null },
            { itemId: 'item-1', party: 'manager' as const, rating: 4, comment: null },
        ];
        const props = { review: calibrated, cycle, form: formDetail, ratings, abilities: noAbilities, employees: [] };

        const latin = track(mount(ReviewsShow, { props, global: { stubs } }));
        expect(latin.get('[data-testid="score-self"]').text()).toBe('4.35');
        expect(latin.get('[data-testid="score-manager"]').text()).toBe('3.80');
        expect(latin.get('[data-testid="score-final"]').text()).toContain('4.00');
        expect(latin.get('[data-testid="score-final"]').text()).toContain('Overridden');
        expect(latin.get('[data-testid="ratings-table"]').text()).toContain('Quality of work');

        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const bengali = track(mount(ReviewsShow, { props, global: { stubs } }));
        expect(bengali.get('[data-testid="score-self"]').text()).toBe('৪.৩৫');
        expect(bengali.text()).toContain('চূড়ান্ত স্কোর');
    });

    it('shows nothing of the manager side to the employee before calibration', () => {
        // The server sends nulls for the manager's score, comments and ratings until release.
        const withheld = review({ status: 'pending_calibration', managerScore: null, finalScore: null, scoreText: { self: '4.35', manager: null, final: null } });
        const ratings = [{ itemId: 'item-1', party: 'self' as const, rating: 5, comment: null }];

        const wrapper = track(mount(ReviewsShow, {
            props: { review: withheld, cycle, form: formDetail, ratings, abilities: noAbilities, employees: [] },
            global: { stubs },
        }));

        expect(wrapper.find('[data-testid="score-manager"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="score-final"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Manager score');
        expect(wrapper.get('[data-testid="withheld-note"]').text()).toContain('released after calibration');
        expect(wrapper.get('[data-testid="score-self"]').text()).toBe('4.35');
    });

    it('offers the step and the buttons the server allows, and nothing else', () => {
        const props = { review: review({ status: 'pending_self', managerScore: null }), cycle: { ...cycle, status: 'active' as const }, form: formDetail, ratings: [], employees: [] };

        const selfStep = track(mount(ReviewsShow, { props: { ...props, abilities: { ...noAbilities, submitSelf: true } }, global: { stubs } }));
        expect(selfStep.find('[data-testid="ratings-form"]').exists()).toBe(true);
        expect(selfStep.text()).toContain('Submit self review');

        const viewer = track(mount(ReviewsShow, { props: { ...props, abilities: noAbilities }, global: { stubs } }));
        expect(viewer.find('[data-testid="ratings-form"]').exists()).toBe(false);
        expect(viewer.findAll('button')).toHaveLength(0);

        const calibrator = track(mount(ReviewsShow, {
            props: { ...props, review: review(), abilities: { ...noAbilities, calibrate: true } },
            global: { stubs },
        }));
        expect(calibrator.findAll('button').map((button) => button.text())).toContain('Calibrate');
    });
});

describe('recruitment dialogs', () => {
    it('warns about a duplicate email and asks for confirmation before adding the person again', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ matches: [{ id: 'cand-9', name: 'Rahim Uddin', email: 'rahim@example.test' }] }) })));

        const wrapper = track(mount(CandidateDialog, {
            props: { open: true, candidate: null, consentBases: ['candidate_provided', 'referral'] },
            global: { stubs },
        }));
        const submit = () => wrapper.get('button[type="submit"]');

        await field(wrapper, 'First name').setValue('Rahim');
        await field(wrapper, 'Email').setValue('Rahim@Example.test');
        await field(wrapper, 'Email').trigger('blur');
        await flushPromises();

        expect(wrapper.get('[data-testid="duplicate-warning"]').text()).toContain('Rahim Uddin');
        expect(vi.mocked(fetch)).toHaveBeenCalledWith('/talent.candidates.lookup?email=rahim%40example.test', expect.anything());
        expect(submit().attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="duplicate-warning"] input[type="checkbox"]').setValue(true);
        expect(submit().attributes('disabled')).toBeUndefined();
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions.at(-1)).toMatchObject({
            method: 'post',
            url: '/talent.candidates.store',
            data: { first_name: 'Rahim', email: 'Rahim@Example.test', confirm_duplicate: true, expected_amount: null, consent_basis: 'candidate_provided' },
        });
    });

    it('sends the expected pay as exact minor units', async () => {
        const wrapper = track(mount(CandidateDialog, { props: { open: true, candidate: null, consentBases: ['candidate_provided'] }, global: { stubs } }));

        await field(wrapper, 'First name').setValue('Salma');
        await field(wrapper, 'Expected pay').setValue('1250.5');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data).toMatchObject({ expected_amount: 125_050, currency: 'BDT' });

        inertia.submissions.length = 0;
        await field(wrapper, 'Expected pay').setValue('12.505');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('schedules an interview with local date and time, a panel and a duration inside the limits', async () => {
        const employees = [
            { id: 'emp-2', number: 'EMP-0002', name: 'Imran Hossain', department: 'Finance' },
            { id: 'emp-3', number: 'EMP-0003', name: 'Farah Khan', department: null },
        ];
        const wrapper = track(mount(InterviewDialog, {
            props: { open: true, applicationId: 'app-1', interview: null, employees, timezone: 'Asia/Dhaka' },
            global: { stubs },
        }));
        const submit = () => wrapper.get('button[type="submit"]');

        await field(wrapper, 'Round').setValue('Technical round');
        await field(wrapper, 'Date').setValue('2026-10-20');
        await field(wrapper, 'Time').setValue('10:30');
        expect(submit().attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="panel-options"] input[type="checkbox"]').setValue(true);
        expect(submit().attributes('disabled')).toBeUndefined();

        await field(wrapper, 'Duration in minutes').setValue('10');
        expect(submit().attributes('disabled')).toBeDefined();
        await field(wrapper, 'Duration in minutes').setValue('45');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions.at(-1)).toMatchObject({
            method: 'post',
            url: '/talent.interviews.store?application=app-1',
            data: { round_name: 'Technical round', mode: 'in_person', date: '2026-10-20', time: '10:30', duration_minutes: 45, panel: ['emp-2'] },
        });
    });
});

describe('interview feedback is blind', () => {
    const base = { application: { id: 'app-1', status: 'open' }, candidate: { id: 'cand-1', name: 'Rahim Uddin' }, opening: { id: 'op-1', title: 'Accountant' }, modes: [], outcomes: [], employees: [], timezone: 'Asia/Dhaka' };

    it('says how many scorecards stay hidden until the panelist has submitted their own', () => {
        const wrapper = track(mount(InterviewsShow, {
            props: { ...base, interview: interview({ scorecardCount: 2, abilities: { edit: false, cancel: false, complete: false, feedback: true } }) },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="hidden-scorecards"]').text()).toContain('2 more scorecards are hidden');
        expect(wrapper.text()).toContain('Submit scorecard');
        expect(wrapper.text()).toContain('Feedback is blind');
    });

    it('lists the scorecards the server sent and no hidden note when all are visible', () => {
        const scorecard = { employee: { id: 'emp-2', number: 'EMP-0002', name: 'Imran Hossain' }, rating: 4, recommendation: 'yes' as const, strengths: 'Precise', concerns: null, submittedAt: '2026-10-20T06:00:00Z' };
        const wrapper = track(mount(InterviewsShow, {
            props: { ...base, interview: interview({ status: 'completed', outcome: 'advance', scorecards: [scorecard], scorecardCount: 1 }) },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="scorecards"]').text()).toContain('Imran Hossain');
        expect(wrapper.get('[data-testid="scorecards"]').text()).toContain('Precise');
        expect(wrapper.find('[data-testid="hidden-scorecards"]').exists()).toBe(false);
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('talent self-service and widgets', () => {
    it('explains an account without an employee record', () => {
        const wrapper = track(mount(MeShow, { props: { hasProfile: false }, global: { stubs } }));

        expect(wrapper.text()).toContain('No employee record linked');
    });

    it('lists tasks, reviews waiting for the person and goal progress', () => {
        inertia.page.props.auth.permissions = ['talent.tasks.complete', 'talent.reviews.view', 'talent.goals.view'];
        const wrapper = track(mount(MeShow, {
            props: {
                hasProfile: true,
                employee: { id: 'emp-1', number: 'EMP-0001', name: 'Nadia Karim' },
                today: '2026-10-11',
                tasks: [{
                    id: 't-1', checklistId: 'c-1', title: 'Sign the handbook', description: null, assigneeRole: 'employee' as const, assignee: null,
                    dueOn: '2026-10-10', status: 'pending' as const, completedAt: null, note: null, checklistName: 'Onboarding', checklistKind: 'onboarding',
                }],
                reviews: [{ ...review({ status: 'pending_self' }), cycle: { id: 'cycle-1', name: '2026 annual review', status: 'active' as const }, role: 'subject' as const, awaitingMe: true }],
                goals: [{
                    id: 'g-1', employee: null, cycleId: null, title: 'Close the books in five days', description: null, kind: 'kpi' as const, unit: 'days',
                    startValue: 10, targetValue: 5, currentValue: 7, progressBp: 6000, status: 'active' as const, dueOn: null, achievedAt: null,
                }],
                enrolments: [],
                probation: null,
                interviews: [],
            },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="my-tasks"]').text()).toContain('Sign the handbook');
        expect(wrapper.get('[data-testid="my-tasks"]').text()).toContain('Mark done');
        expect(wrapper.get('[data-testid="my-reviews"]').text()).toContain('Waiting for you');
        expect(wrapper.get('[data-testid="my-goals"] [role="progressbar"]').attributes('aria-valuenow')).toBe('60');
    });

    it('shows the self widget for a linked and an unlinked account', () => {
        const linked = track(mount(TalentSelfWidget, {
            props: { data: { hasProfile: true, pendingTasks: 3, reviewsToWrite: 1, reviewsToAcknowledge: 0, activeGoals: 2, openEnrolments: 1 } },
        }));
        expect(linked.get('[data-testid="self-tasks"]').text()).toBe('3');

        const unlinked = track(mount(TalentSelfWidget, {
            props: { data: { hasProfile: false, pendingTasks: 0, reviewsToWrite: 0, reviewsToAcknowledge: 0, activeGoals: 0, openEnrolments: 0 } },
        }));
        expect(unlinked.text()).toContain('No employee record');
    });

    it('shows the overview numbers in both languages', () => {
        const data = {
            openOpenings: 4, activeApplications: 31, interviewsThisWeek: 6, openOnboardingTasks: 9, probationsEnding: 2,
            reviewsAwaitingCalibration: 5, certificatesExpiring: 1,
        };
        const english = track(mount(TalentOverviewWidget, { props: { data } }));
        expect(english.text()).toContain('31');
        expect(english.text()).toContain('Reviews to calibrate');

        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const bengali = track(mount(TalentOverviewWidget, { props: { data } }));
        expect(bengali.text()).toContain('৩১');
        expect(bengali.text()).toContain('ক্যালিব্রেশনের রিভিউ');
    });
});

describe('talent messages', () => {
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
        const pattern = /\bt\(\s*'(talent\.[\w.]+)'/g;
        const used = new Set<string>();
        const roots = [join(process.cwd(), 'resources/js/Modules/Talent')];

        for (const file of roots.flatMap((root) => files(root)).filter((path) => path.endsWith('.vue') || path.endsWith('.ts'))) {
            for (const match of readFileSync(file, 'utf8').matchAll(pattern)) {
                used.add(match[1]!);
            }
        }

        expect(used.size).toBeGreaterThan(300);

        const missing = [...used].filter((key) => typeof lookup(en, key) !== 'string' || typeof lookup(bn, key) !== 'string');
        expect(missing).toEqual([]);
    });

    it('defines every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'talent.status.opening': ['draft', 'open', 'on_hold', 'closed'],
            'talent.status.application': ['open', 'hired', 'rejected', 'withdrawn'],
            'talent.status.stageType': ['active', 'hired', 'rejected'],
            'talent.status.interview': ['scheduled', 'completed', 'cancelled', 'no_show'],
            'talent.status.outcome': ['advance', 'hold', 'reject'],
            'talent.status.offer': ['draft', 'sent', 'accepted', 'declined', 'withdrawn'],
            'talent.status.checklist': ['open', 'completed', 'cancelled'],
            'talent.status.task': ['pending', 'done', 'skipped'],
            'talent.status.probation': ['in_progress', 'confirmed', 'ended'],
            'talent.status.cycle': ['draft', 'active', 'calibration', 'closed'],
            'talent.status.review': ['pending_self', 'pending_manager', 'pending_calibration', 'calibrated', 'acknowledged'],
            'talent.status.goal': ['active', 'achieved', 'missed', 'cancelled'],
            'talent.status.goalKind': ['goal', 'kpi'],
            'talent.status.enrolment': ['enrolled', 'completed', 'failed', 'cancelled'],
            'talent.status.session': ['scheduled', 'completed', 'cancelled'],
            'talent.status.recommendation': ['strong_no', 'no', 'yes', 'strong_yes'],
            'talent.status.kind': ['onboarding', 'offboarding'],
            'talent.status.assigneeRole': ['employee', 'manager', 'hr', 'person'],
            'talent.status.mode': ['in_person', 'video', 'phone'],
            'talent.status.consent': ['candidate_provided', 'applied_directly', 'consent_form', 'referral'],
            'talent.status.document': ['resume', 'cover_letter', 'portfolio', 'other'],
            'talent.status.employmentType': ['full_time', 'part_time', 'contract', 'intern', 'consultant'],
            'talent.history': ['applied', 'moved', 'rejected', 'hired', 'withdrawn', 'reopened'],
            'talent.offers.action': ['sent', 'accepted', 'declined', 'withdrawn'],
            'talent.openings.statusAction': ['open', 'on_hold', 'closed', 'draft'],
            'talent.probations.decision': ['confirm', 'extend', 'end'],
            'talent.settings.tabs': ['general', 'pipelines', 'reasons', 'templates', 'forms'],
            'talent.cycles.confirm.launch': ['title', 'body', 'action'],
            'talent.cycles.confirm.calibration': ['title', 'body', 'action'],
            'talent.cycles.confirm.close': ['title', 'body', 'action'],
            'talent.enrolments.confirm.fail': ['title', 'body', 'action'],
            'talent.enrolments.confirm.cancel': ['title', 'body', 'action'],
            'talent.goals.close': ['achieved', 'missed', 'cancelled'],
            'talent.goals.confirm.achieved': ['title', 'body'],
            'talent.goals.confirm.missed': ['title', 'body'],
            'talent.goals.confirm.cancelled': ['title', 'body'],
            'talent.analytics.openings': ['draft', 'open', 'onHold', 'closed'],
            'talent.widget.overview': [
                'openOpenings', 'activeApplications', 'interviewsThisWeek', 'openOnboardingTasks', 'probationsEnding',
                'reviewsAwaitingCalibration', 'certificatesExpiring',
            ],
            'talent.units': ['dayOne', 'dayMany', 'hours', 'hoursMinutes', 'minutes', 'kb', 'mb'],
        };

        for (const [family, keys] of Object.entries(families)) {
            for (const key of keys) {
                expect(typeof lookup(en, `${family}.${key}`), `en ${family}.${key}`).toBe('string');
                expect(typeof lookup(bn, `${family}.${key}`), `bn ${family}.${key}`).toBe('string');
            }
        }
    });

    it('labels the module, permissions, limits, sidebar entries, automation and app text the server announces', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/Talent/TalentServiceProvider.php'), 'utf8');
        const permissions = [...manifest.matchAll(/new PermissionDefinition\('(talent\.[\w.]+)'/g)].map((match) => match[1]!);
        const groups = new Set([...manifest.matchAll(/new PermissionDefinition\('talent\.[\w.]+', '(\w+)'/g)].map((match) => match[1]!));
        const nav = [...manifest.matchAll(/new NavigationItem\('(talent\w+)'/g)].map((match) => match[1]!);

        expect(permissions).toHaveLength(30);
        expect(nav).toHaveLength(15);
        expect([...groups].sort()).toEqual([
            'talent_analytics', 'talent_onboarding', 'talent_performance', 'talent_recruitment', 'talent_self', 'talent_settings', 'talent_training',
        ]);

        for (const messages of [en, bn]) {
            expect(typeof messages.catalogue.modules.talent).toBe('string');
            expect(typeof messages.appStore.catalog.talent.name).toBe('string');
            expect(messages.appStore.catalog.talent.features).toHaveLength(6);

            for (const limit of ['talent.job_openings', 'talent.candidates']) {
                expect((messages.catalogue.limits as Record<string, { label: string }>)[limit]?.label, limit).toBeTruthy();
            }

            for (const permission of permissions) {
                const entry = (messages.catalogue.permissions as Record<string, { label: string; description: string }>)[permission];
                expect(entry?.label, permission).toBeTruthy();
                expect(entry?.description, permission).toBeTruthy();
            }

            for (const group of groups) {
                expect((messages.catalogue.permissionGroups as Record<string, string>)[group], group).toBeTruthy();
            }

            for (const key of nav) {
                expect((messages.shell.nav as unknown as Record<string, string>)[key], key).toBeTruthy();
            }

            const automation = messages.catalogue.automation;
            expect(Object.keys(automation.triggers).filter((key) => key.startsWith('talent.'))).toHaveLength(9);
            expect(Object.keys(automation.actions).filter((key) => key.startsWith('talent.'))).toHaveLength(2);
        }
    });

    it('keeps the English app text identical to the manifest', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/Talent/TalentServiceProvider.php'), 'utf8');

        expect(manifest).toContain(`name: '${en.appStore.catalog.talent.name}'`);
        expect(manifest).toContain(`description: '${en.appStore.catalog.talent.description}'`);
        expect(manifest).toContain(`releaseNotes: '${en.appStore.catalog.talent.releaseNotes}'`);

        for (const feature of en.appStore.catalog.talent.features) {
            expect(manifest).toContain(`'${feature}'`);
        }
    });
});
