import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Submission {
    method: string;
    url: string;
    data: Record<string, unknown>;
}

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: {
                permissions: [
                    'projects.projects.view',
                    'projects.time.view',
                    'projects.expenses.view',
                    'projects.approvals.view',
                    'projects.reports.view',
                    'projects.settings.manage',
                ],
            },
            tenant: { name: 'Acme' },
        },
        flash: {} as { clientAccessInvitation?: { url: string } | null },
    },
    forms: [] as Array<Record<string, any>>,
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        let defaults = structuredClone(initial);
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.defaults = (values: Record<string, unknown>) => { defaults = { ...defaults, ...structuredClone(values) }; return form; };
        form.reset = () => { Object.assign(form, structuredClone(defaults)); return form; };
        form.clearErrors = () => { form.errors = {}; };
        form.transform = () => form;
        form.post = (url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, structuredClone(toRaw(form[key]))]));
            inertia.submissions.push({ method: 'post', url, data });
            options?.onSuccess?.();
        };
        form.get = (url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, structuredClone(toRaw(form[key]))]));
            inertia.submissions.push({ method: 'get', url, data });
            options?.onSuccess?.();
        };
        form.patch = (url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, structuredClone(toRaw(form[key]))]));
            inertia.submissions.push({ method: 'patch', url, data });
            options?.onSuccess?.();
        };
        inertia.forms.push(form);
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
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import ClientAccessDialog from '@/Modules/Projects/Components/ClientAccessDialog.vue';
import ClientAccessPanel from '@/Modules/Projects/Components/ClientAccessPanel.vue';
import ProjectFormDialog from '@/Modules/Projects/Components/ProjectFormDialog.vue';
import ProgressBar from '@/Modules/Projects/Components/ProgressBar.vue';
import ProjectsNav from '@/Modules/Projects/Components/ProjectsNav.vue';
import ProjectStatusActions from '@/Modules/Projects/Components/ProjectStatusActions.vue';
import TaskBoard from '@/Modules/Projects/Components/TaskBoard.vue';
import TaskCalendar from '@/Modules/Projects/Components/TaskCalendar.vue';
import TaskAssigneesDialog from '@/Modules/Projects/Components/TaskAssigneesDialog.vue';
import TaskFormDialog from '@/Modules/Projects/Components/TaskFormDialog.vue';
import TaskGantt from '@/Modules/Projects/Components/TaskGantt.vue';
import type { Task } from '@/Modules/Projects/Components/types';
import ExpensesIndex from '@/Modules/Projects/Pages/Expenses/Index.vue';
import ApprovalsIndex from '@/Modules/Projects/Pages/Approvals/Index.vue';
import PortalShow from '@/Modules/Projects/Pages/Portal/Show.vue';
import ProjectShow from '@/Modules/Projects/Pages/Projects/Show.vue';
import ReportsIndex from '@/Modules/Projects/Pages/Reports/Index.vue';
import SettingsIndex from '@/Modules/Projects/Pages/Settings/Index.vue';
import TimeIndex from '@/Modules/Projects/Pages/Time/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const modalStub = defineComponent({ template: '<div><slot /><slot name="footer" /></div>' });
const task = (overrides: Partial<Task> = {}): Task => ({
    id: 'task-1',
    title: 'Prepare launch',
    status: 'todo',
    priority: 'normal',
    startDate: '2026-10-10',
    dueDate: '2026-10-12',
    assignees: [],
    ...overrides,
});
const portalTask = (id: string, title: string) => ({ ...task({ id, title }), progress: 10, checklist: [], comments: [] });
const pageMeta = (overrides: Partial<{ currentPage: number; perPage: number; total: number; lastPage: number; hasMorePages: boolean; previousPageUrl: string | null; nextPageUrl: string | null }> = {}) => ({
    currentPage: 1,
    perPage: 50,
    total: 0,
    lastPage: 1,
    hasMorePages: false,
    previousPageUrl: null,
    nextPageUrl: null,
    ...overrides,
});
const jsonResponse = (body: unknown, ok = true): Response => ({ ok, json: async () => body }) as Response;

beforeEach(() => {
    inertia.forms.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    inertia.page.props.app.timezone = 'Asia/Dhaka';
    inertia.page.flash = {};
    setLocale('en');
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('Projects frontend hardening', () => {
    it('offers resubmission for rejected time and expense rows', () => {
        const time = track(mount(TimeIndex, {
            props: {
                entries: [{ id: 'time-1', project: { id: 'project-1', name: 'Launch' }, member: { id: 'member-1', name: 'Nadia' }, date: '2026-10-10', minutes: 30, billable: true, status: 'rejected', submittable: true }],
                entriesPagination: pageMeta({ total: 1 }),
                projects: [], summary: { totalMinutes: 30, billableMinutes: 30, pendingMinutes: 0 }, canLog: false, approvalMembers: [], currentMembershipId: 'member-1',
            },
        }));
        const expenses = track(mount(ExpensesIndex, {
            props: {
                expenses: [{ id: 'expense-1', project: { id: 'project-1', name: 'Launch' }, member: { id: 'member-1', name: 'Nadia' }, date: '2026-10-10', amount: 1_000, currency: 'BDT', description: 'Taxi', status: 'rejected', billable: false, approvable: false, submittable: true }],
                expensesPagination: pageMeta({ total: 1 }),
                projects: [], summary: { currency: 'BDT', totalAmount: '1000', pendingAmount: '0' }, canCreate: false, approvalMembers: [], currentMembershipId: 'member-1',
            },
        }));
        expect(time.text()).toContain('Submit timesheet');
        expect(expenses.text()).toContain('Submit expense');
    });

    it('renders localized ranges and links from time and expense pagination metadata', () => {
        const time = track(mount(TimeIndex, {
            props: {
                entries: [{ id: 'time-3', project: { id: 'project-1', name: 'Launch' }, member: { id: 'member-1', name: 'Nadia' }, date: '2026-10-10', minutes: 30, billable: true, status: 'draft', submittable: false }],
                entriesPagination: pageMeta({ currentPage: 2, perPage: 2, total: 5, lastPage: 3, hasMorePages: true, previousPageUrl: '/time?page=1', nextPageUrl: '/time?page=3' }),
                projects: [], summary: { totalMinutes: 30, billableMinutes: 30, pendingMinutes: 0 }, canLog: false, approvalMembers: [], currentMembershipId: 'member-1',
            },
        }));
        const expenses = track(mount(ExpensesIndex, {
            props: {
                expenses: [{ id: 'expense-5', project: { id: 'project-1', name: 'Launch' }, member: { id: 'member-1', name: 'Nadia' }, date: '2026-10-10', amount: 1_000, currency: 'BDT', description: 'Taxi', status: 'draft', billable: false, approvable: false, submittable: false }],
                expensesPagination: pageMeta({ currentPage: 3, perPage: 2, total: 5, lastPage: 3, previousPageUrl: '/expenses?page=2' }),
                projects: [], summary: { currency: 'BDT', totalAmount: '1000', pendingAmount: '0' }, canCreate: false, approvalMembers: [], currentMembershipId: 'member-1',
            },
        }));
        const reports = track(mount(ReportsIndex, {
            props: {
                summary: { projectCount: 5, activeCount: 1, overdueCount: 0, tasks: 1, completedTasks: 0, workload: [] },
                filters: { from: '2026-10-01', to: '2026-10-31' },
                projects: [{ id: 'project-3', code: 'PRJ-003', name: 'Launch', status: 'active', progress: 50, dueDate: '2026-10-31', taskCount: 1, completedTaskCount: 0, financials: null }],
                projectsPagination: pageMeta({ currentPage: 2, perPage: 2, total: 5, lastPage: 3, hasMorePages: true, previousPageUrl: '/project-reports?from=2026-10-01&to=2026-10-31&page=1', nextPageUrl: '/project-reports?from=2026-10-01&to=2026-10-31&page=3' }),
            },
        }));

        expect(time.get('nav[aria-label="Pagination"]').text()).toContain('3–3 of 5');
        expect(time.get('a[rel="prev"]').attributes('href')).toBe('/time?page=1');
        expect(time.get('a[rel="next"]').attributes('href')).toBe('/time?page=3');
        expect(expenses.get('nav[aria-label="Pagination"]').text()).toContain('5–5 of 5');
        expect(expenses.get('a[rel="prev"]').attributes('href')).toBe('/expenses?page=2');
        expect(reports.get('a[rel="prev"]').attributes('href')).toContain('from=2026-10-01&to=2026-10-31&page=1');
        expect(reports.get('a[rel="next"]').attributes('href')).toContain('from=2026-10-01&to=2026-10-31&page=3');
    });

    it('clears a selected task when its project changes', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const projectId = new URL(String(input), 'https://app.test').searchParams.get('project_id');
            const tasks = projectId === 'project-1'
                ? [{ id: 'task-1', name: 'Prepare launch', projectId: 'project-1' }]
                : [{ id: 'task-2', name: 'Answer request', projectId: 'project-2' }];
            return jsonResponse({ tasks, pagination: pageMeta({ total: 1 }), filters: { projectId, search: '' } });
        }));
        const wrapper = track(mount(TimeIndex, {
            props: {
                entries: [],
                entriesPagination: pageMeta(),
                projects: [{ id: 'project-1', name: 'Launch' }, { id: 'project-2', name: 'Support' }],
                summary: { totalMinutes: 0, billableMinutes: 0, pendingMinutes: 0 }, canLog: true, approvalMembers: [], currentMembershipId: 'member-1',
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const selects = wrapper.findAll('select');
        await selects[0]!.setValue('project-1');
        await flushPromises();
        await selects[1]!.setValue('task-1');
        await selects[0]!.setValue('project-2');
        await flushPromises();

        expect((selects[1]!.element as HTMLSelectElement).value).toBe('');
    });

    it('isolates portal comment drafts between tasks and supports cancel', async () => {
        const wrapper = track(mount(PortalShow, {
            props: {
                project: { id: 'project-1', code: 'PRJ-1', name: 'Client launch', status: 'active', progress: 25 },
                tasks: [portalTask('task-1', 'Prepare launch'), portalTask('task-2', 'Review launch')],
                tasksPagination: pageMeta({ total: 2 }),
                client: { name: 'Nadia', canComment: true }, financials: null, token: 'secret',
            },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Add a comment')!.trigger('click');
        await wrapper.get('textarea').setValue('Draft for the first task');
        await wrapper.findAll('button').find((button) => button.text() === 'Add a comment')!.trigger('click');
        expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('');

        inertia.forms[0]!.errors = { body: 'Comment is required.' };
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[role="alert"]').text()).toBe('Comment is required.');
        expect(wrapper.get('textarea').attributes('aria-invalid')).toBe('true');

        await wrapper.findAll('button').find((button) => button.text() === 'Cancel')!.trigger('click');
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('uses token-preserving portal pagination links and an accessible range', () => {
        const wrapper = track(mount(PortalShow, {
            props: {
                project: { id: 'project-1', code: 'PRJ-1', name: 'Client launch', status: 'active', progress: 25 },
                tasks: [portalTask('task-51', 'Review launch')],
                tasksPagination: pageMeta({
                    currentPage: 2,
                    perPage: 50,
                    total: 101,
                    lastPage: 3,
                    hasMorePages: true,
                    previousPageUrl: '/project-portal/secret-token?page=1',
                    nextPageUrl: '/project-portal/secret-token?page=3',
                }),
                client: { name: 'Nadia', canComment: false },
                financials: null,
                token: 'secret-token',
            },
        }));

        const pagination = wrapper.get('nav[aria-label="Pagination"]');
        expect(pagination.text()).toContain('51–51 of 101');
        expect(pagination.get('a[rel="prev"]').attributes('href')).toBe('/project-portal/secret-token?page=1');
        expect(pagination.get('a[rel="next"]').attributes('href')).toBe('/project-portal/secret-token?page=3');
    });

    it('disables portal pagination while a comment is being submitted', async () => {
        const wrapper = track(mount(PortalShow, {
            props: {
                project: { id: 'project-1', code: 'PRJ-1', name: 'Client launch', status: 'active', progress: 25 },
                tasks: [portalTask('task-51', 'Review launch')],
                tasksPagination: pageMeta({
                    currentPage: 2,
                    perPage: 50,
                    total: 101,
                    lastPage: 3,
                    hasMorePages: true,
                    previousPageUrl: '/project-portal/secret-token?page=1',
                    nextPageUrl: '/project-portal/secret-token?page=3',
                }),
                client: { name: 'Nadia', canComment: true },
                financials: null,
                token: 'secret-token',
            },
        }));
        const pagination = wrapper.get('nav[aria-label="Pagination"]');
        expect(pagination.findAll('a')).toHaveLength(2);

        inertia.forms[0]!.processing = true;
        await wrapper.vm.$nextTick();

        expect(pagination.findAll('a')).toHaveLength(0);
        expect(pagination.findAll('[aria-disabled="true"]')).toHaveLength(2);
    });

    it('keeps Gantt date-only labels stable west of UTC', () => {
        inertia.page.props.app.timezone = 'America/Los_Angeles';
        const wrapper = track(mount(TaskGantt, { props: { tasks: [task()] } }));

        expect(wrapper.text()).toContain('Oct 10, 2026');
        expect(wrapper.text()).toContain('Oct 12, 2026');
        expect(wrapper.text()).not.toContain('Oct 9, 2026');
    });

    it('places start-only tasks on the calendar', () => {
        const wrapper = track(mount(TaskCalendar, { props: { tasks: [task({ dueDate: null })] } }));

        expect(wrapper.text()).toContain('Prepare launch');
        expect(wrapper.text()).toContain('Oct 10, 2026');
    });

    it('formats visible progress with the configured numeral system', () => {
        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const wrapper = track(mount(ProgressBar, { props: { value: 42, label: 'Progress' } }));

        expect(wrapper.text()).toContain('৪২%');
    });

    it('matches backend input limits, including task reminder and estimate controls', () => {
        const project = track(mount(ProjectFormDialog, {
            props: { open: true, canManageFinancials: false, options: { categories: [], templates: [], owners: [], contacts: [], companies: [], currency: 'BDT' } },
            global: { stubs: { Modal: modalStub } },
        }));
        const taskForm = track(mount(TaskFormDialog, {
            props: { open: true, projectId: 'project-1', options: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] } },
            global: { stubs: { Modal: modalStub } },
        }));
        const time = track(mount(TimeIndex, {
            props: { entries: [], entriesPagination: pageMeta(), projects: [], summary: { totalMinutes: 0, billableMinutes: 0, pendingMinutes: 0 }, canLog: true, approvalMembers: [], currentMembershipId: 'member-1' },
            global: { stubs: { Modal: modalStub } },
        }));

        expect(project.get('input[autofocus]').attributes('maxlength')).toBe('200');
        expect(project.get('textarea').attributes('maxlength')).toBe('20000');
        expect(taskForm.findAll('input[type="datetime-local"]')).toHaveLength(3);
        expect(taskForm.get('input[type="number"]').attributes()).toMatchObject({ min: '0', max: '10000000', step: '1' });
        expect(time.get('input[type="number"]').attributes('max')).toBe('10080');
    });

    it('exposes current navigation and named work regions to assistive technology', () => {
        const nav = track(mount(ProjectsNav, { props: { current: 'projects' } }));
        const board = track(mount(TaskBoard, { props: { tasks: [task()] } }));
        const calendar = track(mount(TaskCalendar, { props: { tasks: [task()] } }));
        const gantt = track(mount(TaskGantt, { props: { tasks: [task()] } }));

        expect(nav.get('[aria-current="page"]').text()).toBe('Projects');
        expect(board.get('[role="region"]').attributes('aria-label')).toBe('Kanban board');
        expect(calendar.get('[role="region"]').attributes('aria-label')).toBe('Calendar');
        expect(gantt.get('[role="region"]').attributes('aria-label')).toBe('Gantt chart');
    });

    it('shows lifecycle validation errors returned by the server', async () => {
        inertia.router.patch.mockImplementationOnce((_url: string, _data: unknown, options: { onError?: (errors: Record<string, string>) => void }) => {
            options.onError?.({ status: 'Complete or cancel every open task first.' });
        });
        const wrapper = track(mount(ProjectStatusActions, { props: { projectId: 'project-1', status: 'planned' } }));

        await wrapper.findAll('button')[0]!.trigger('click');

        expect(wrapper.get('[role="alert"]').text()).toBe('Complete or cancel every open task first.');
    });

    it('keeps terminal project errors inside their confirmation after requests finish', async () => {
        inertia.router.patch.mockImplementationOnce((_url: string, _data: unknown, options: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options.onError?.({ status: 'This project still has open work.' });
            options.onFinish?.();
        });
        const wrapper = track(mount(ProjectStatusActions, {
            props: { projectId: 'project-1', status: 'planned' },
            global: { stubs: { Modal: modalStub } },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Cancel project')!.trigger('click');
        const confirmation = wrapper.getComponent(ConfirmDialog);
        expect(confirmation.props('open')).toBe(true);
        confirmation.vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(confirmation.props('open')).toBe(true);
        expect(wrapper.findAll('[role="alert"]')).toHaveLength(1);
        expect(wrapper.get('[role="alert"]').text()).toBe('This project still has open work.');
    });

    it('keeps stale approval cancellation errors visible inside the confirmation', async () => {
        const wrapper = track(mount(ApprovalsIndex, {
            props: {
                approvals: {
                    data: [{ id: 'approval-1', type: 'task', title: 'Launch', project: { id: 'project-1', name: 'Launch' }, requester: { id: 'member-1', name: 'Nadia' }, approver: { id: 'member-2', name: 'Rafi' }, requestedAt: '2026-10-11T10:00:00Z', status: 'pending', actionable: false, cancelable: true }],
                    current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null,
                    links: [],
                },
            },
            global: { stubs: { Modal: modalStub } },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Cancel request')!.trigger('click');
        inertia.forms.at(-1)!.errors = { status: 'This approval request is no longer pending.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="alert"]').text()).toContain('no longer pending');
    });

    it('locks and submits approval cancellation then closes on success', async () => {
        const wrapper = track(mount(ApprovalsIndex, {
            props: {
                approvals: {
                    data: [{ id: 'approval-1', type: 'task', title: 'Launch', project: { id: 'project-1', name: 'Launch' }, requester: { id: 'member-1', name: 'Nadia' }, approver: { id: 'member-2', name: 'Rafi' }, requestedAt: '2026-10-11T10:00:00Z', status: 'pending', actionable: false, cancelable: true }],
                    current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null, links: [],
                },
            },
            global: { stubs: { Modal: modalStub } },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Cancel request')!.trigger('click');
        const cancellation = inertia.forms.at(-1)!;
        cancellation.processing = true;
        await wrapper.vm.$nextTick();

        const confirm = wrapper.findAll('button').filter((button) => button.text() === 'Cancel request').at(-1)!;
        expect(confirm.attributes('disabled')).toBeDefined();

        cancellation.processing = false;
        await wrapper.vm.$nextTick();
        await confirm.trigger('click');

        expect(inertia.submissions.at(-1)).toMatchObject({ method: 'patch', url: '/projects.approvals.cancel?approval=approval-1' });
        expect(wrapper.getComponent(ConfirmDialog).props('open')).toBe(false);
    });

    it('shows the approval queue to request-only members in project navigation', () => {
        const previous = [...inertia.page.props.auth.permissions];

        try {
            inertia.page.props.auth.permissions = ['projects.approvals.request'];
            const wrapper = track(mount(ProjectsNav, { props: { current: 'approvals' } }));

            expect(wrapper.get('[aria-current="page"]').text()).toBe('Approvals');
        } finally {
            inertia.page.props.auth.permissions = previous;
        }
    });

    it('shows time lifecycle errors and releases the pending action', async () => {
        inertia.router.patch.mockImplementationOnce((_url: string, _data: unknown, options: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options.onError?.({ status: 'Only draft or rejected time can be submitted.' });
            options.onFinish?.();
        });
        const wrapper = track(mount(TimeIndex, {
            props: {
                entries: [{ id: 'time-1', project: { id: 'project-1', name: 'Launch' }, member: { id: 'member-1', name: 'Nadia' }, date: '2026-10-10', minutes: 30, billable: true, status: 'rejected', submittable: true }],
                entriesPagination: pageMeta({ total: 1 }),
                projects: [], summary: { totalMinutes: 30, billableMinutes: 30, pendingMinutes: 0 }, canLog: false, approvalMembers: [], currentMembershipId: 'member-1',
            },
        }));

        await wrapper.findAll('button').find((button) => button.text() === 'Submit timesheet')!.trigger('click');

        expect(wrapper.get('[role="alert"]').text()).toContain('Only draft or rejected time');
        expect(wrapper.findAll('button').find((button) => button.text() === 'Submit timesheet')!.attributes('disabled')).toBeUndefined();
    });

    it('renders report range errors beside the invalid date', async () => {
        const wrapper = track(mount(ReportsIndex, {
            props: {
                summary: { projectCount: 0, activeCount: 0, overdueCount: 0, tasks: 0, completedTasks: 0, workload: [] },
                filters: { from: null, to: null }, projects: [],
                projectsPagination: { currentPage: 1, perPage: 50, total: 0, lastPage: 1, hasMorePages: false, previousPageUrl: null, nextPageUrl: null },
            },
        }));
        inertia.forms.at(-1)!.errors = { to: 'The end date must be on or after the start date.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="alert"]').text()).toContain('on or after');
    });

    it('surfaces task-level project errors returned by terminal project guards', async () => {
        const wrapper = track(mount(TaskFormDialog, {
            props: { open: true, projectId: 'project-1', options: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] } },
            global: { stubs: { Modal: modalStub } },
        }));
        inertia.forms.at(-1)!.errors = { project_id: 'New tasks cannot be added to a completed project.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="alert"]').text()).toContain('cannot be added');
    });

    it('does not offer task or planning creation on terminal projects, even to editors', () => {
        const wrapper = track(mount(ProjectShow, {
            props: {
                project: { id: 'project-1', name: 'Archived launch', status: 'completed', priority: 'normal', progress: 100, currency: 'BDT', taskCreatable: true, editable: true },
                members: [], memberOptions: [], approvalMembers: [], tasks: [], milestones: [], sprints: [], activity: [], financials: null, workload: [], clientAccesses: [], projectAttachments: [],
                projectForm: { categories: [], templates: [], owners: [], contacts: [], companies: [], currency: 'BDT' },
                taskForm: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] },
                canManageMembers: false, canManageFinancials: false, canRequestApprovals: false, currentMembershipId: 'member-1',
            },
        }));

        expect(wrapper.findAll('button').some((button) => button.text() === 'Create task')).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text() === 'Add milestone')).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text() === 'Add sprint')).toBe(false);
    });

    it('renders top-level assignee errors and clears stale indexed errors after removal', async () => {
        const wrapper = track(mount(TaskAssigneesDialog, {
            props: {
                open: true,
                projectId: 'project-1',
                task: task({ assignable: true, assignees: [{ id: 'member-1', name: 'Nadia', allocationPercent: 100 }] }),
                members: [{ id: 'member-1', name: 'Nadia' }, { id: 'member-2', name: 'Rafi' }],
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const form = inertia.forms.at(-1)!;
        form.errors = {
            assignees: 'One or more assignees are unavailable.',
            'assignees.0.membership_id': 'This assignee is no longer available.',
            'assignees.0.allocation_percent': 'Allocation must be between 0 and 100 percent.',
        };
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('[role="alert"]').map((alert) => alert.text())).toContain('One or more assignees are unavailable.');
        expect(wrapper.text()).toContain('This assignee is no longer available.');
        expect(wrapper.text()).toContain('Allocation must be between 0 and 100 percent.');

        await wrapper.get('button[aria-label]').trigger('click');

        expect(wrapper.text()).not.toContain('One or more assignees are unavailable.');
        expect(wrapper.text()).not.toContain('This assignee is no longer available.');
        expect(wrapper.text()).not.toContain('Allocation must be between 0 and 100 percent.');
    });

    it('requires confirmation and preserves cancellation errors for project planning', async () => {
        const wrapper = track(mount(ProjectShow, {
            props: {
                project: { id: 'project-1', name: 'Launch', status: 'active', priority: 'normal', progress: 20, currency: 'BDT', taskCreatable: true, editable: true },
                members: [], memberOptions: [], approvalMembers: [], tasks: [],
                milestones: [{ id: 'milestone-1', name: 'Release', dueDate: '2026-10-20', completed: false, taskCount: 0, completedTaskCount: 0, status: 'open' }],
                sprints: [], activity: [], financials: null, workload: [], clientAccesses: [], projectAttachments: [],
                projectForm: { categories: [], templates: [], owners: [], contacts: [], companies: [], currency: 'BDT' },
                taskForm: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] },
                canManageMembers: false, canManageFinancials: false, canRequestApprovals: false, currentMembershipId: 'member-1',
            },
            global: { stubs: { Modal: modalStub } },
        }));

        const milestone = wrapper.findAll('li').find((item) => item.text().includes('Release'))!;
        await milestone.findAll('button').find((button) => button.text() === 'Cancel')!.trigger('click');
        expect(inertia.router.patch).not.toHaveBeenCalled();
        const confirmation = wrapper.findAllComponents(ConfirmDialog).find((dialog) => dialog.props('description')?.includes('Release'))!;
        expect(confirmation).toBeDefined();
        expect(confirmation.props('open')).toBe(true);

        inertia.router.patch.mockImplementationOnce((_url: string, _data: unknown, options: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options.onError?.({ status: 'This milestone can no longer be cancelled.' });
            options.onFinish?.();
        });
        confirmation.vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(inertia.router.patch).toHaveBeenCalledWith(
            '/projects.milestones.status?project=project-1&milestone=milestone-1',
            { status: 'cancelled' },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(confirmation.props('open')).toBe(true);
        const planningAlerts = wrapper.findAll('[role="alert"]').filter((alert) => alert.text().includes('This milestone can no longer be cancelled.'));
        expect(planningAlerts).toHaveLength(1);
    });

    it('requires confirmation and preserves revoke errors for client access', async () => {
        const wrapper = track(mount(ClientAccessPanel, {
            props: {
                projectId: 'project-1',
                accesses: [{
                    id: 'access-1',
                    contact: { id: 'contact-1', name: 'Nadia', email: 'nadia@example.test' },
                    company: null,
                    status: 'active',
                    canComment: true,
                    canViewFinancials: false,
                    invitedAt: '2026-10-10T10:00:00Z',
                    acceptedAt: '2026-10-10T11:00:00Z',
                    expiresAt: null,
                    revocable: true,
                }],
            },
            global: { stubs: { Modal: modalStub } },
        }));

        await wrapper.get('button[aria-label]').trigger('click');
        expect(inertia.router.delete).not.toHaveBeenCalled();
        const confirmation = wrapper.getComponent(ConfirmDialog);
        expect(confirmation.props('open')).toBe(true);

        inertia.router.delete.mockImplementationOnce((_url: string, options: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options.onError?.({ client_access: 'This client access has already been revoked.' });
            options.onFinish?.();
        });
        confirmation.vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(inertia.router.delete).toHaveBeenCalledWith(
            '/projects.client-access.destroy?project=project-1&clientAccess=access-1',
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(confirmation.props('open')).toBe(true);
        expect(wrapper.get('[role="alert"]').text()).toContain('already been revoked');
    });

    it('resets an always-mounted client access form when it reopens', async () => {
        const wrapper = track(mount(ClientAccessDialog, {
            props: { open: true, projectId: 'project-1', contacts: [{ id: 'contact-1', name: 'Nadia' }], companies: [], canManageFinancials: true },
            global: { stubs: { Modal: modalStub } },
        }));
        await wrapper.get('select').setValue('contact-1');
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('');
    });

    it('explains empty required choices and locks modal cancellation while saving', async () => {
        const client = track(mount(ClientAccessDialog, {
            props: { open: true, projectId: 'project-1', contacts: [], companies: [], canManageFinancials: false },
            global: { stubs: { Modal: modalStub } },
        }));
        const time = track(mount(TimeIndex, {
            props: { entries: [], entriesPagination: pageMeta(), projects: [], summary: { totalMinutes: 0, billableMinutes: 0, pendingMinutes: 0 }, canLog: true, approvalMembers: [], currentMembershipId: 'member-1' },
            global: { stubs: { Modal: modalStub } },
        }));

        expect(client.text()).toContain('No available client contact');
        expect(client.findAll('button').find((button) => button.text() === 'Create private link')!.attributes('disabled')).toBeDefined();
        expect(time.text()).toContain('No available project');
        expect(time.findAll('button').find((button) => button.text() === 'Save')!.attributes('disabled')).toBeDefined();

        inertia.forms[0]!.processing = true;
        await client.vm.$nextTick();
        expect(client.findAll('button').find((button) => button.text() === 'Cancel')!.attributes('disabled')).toBeDefined();
    });

    it('binds blueprint limits and clears indexed errors when a row is removed', async () => {
        const wrapper = track(mount(SettingsIndex, { props: { categories: [], templates: [] }, global: { stubs: { Modal: modalStub } } }));
        await wrapper.findAll('button').find((button) => button.text() === 'Add template task')!.trigger('click');
        const templateForm = inertia.forms.at(-1)!;
        templateForm.errors = {
            'task_blueprints.0.estimated_minutes': 'The estimate is too large.',
            task_blueprints: 'No more than 100 template tasks are allowed.',
        };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('input[max="10000000"]').exists()).toBe(true);
        expect(wrapper.find('input[maxlength="240"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('The estimate is too large.');
        expect(wrapper.find('button[aria-label="Remove template task 1"]').exists()).toBe(true);

        await wrapper.get('button[aria-label="Remove template task 1"]').trigger('click');
        expect(wrapper.text()).not.toContain('The estimate is too large.');
    });

    it('surfaces clipboard failures for one-time client links', async () => {
        inertia.page.flash = { clientAccessInvitation: { url: 'https://client.test/invite' } };
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
        const wrapper = track(mount(ClientAccessPanel, { props: { projectId: 'project-1', accesses: [] } }));

        await wrapper.findAll('button').find((button) => button.text() === 'Copy link')!.trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('could not be copied');
    });
});
