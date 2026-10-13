import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Visit { method: string; url: string }
interface Submission extends Visit { data: Record<string, unknown> }
const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: ['projects.projects.view'] }, tenant: { name: 'Acme' } } },
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
import ApprovalRequestDialog from '@/Modules/Projects/Components/ApprovalRequestDialog.vue';
import ClientAccessDialog from '@/Modules/Projects/Components/ClientAccessDialog.vue';
import ProjectFormDialog from '@/Modules/Projects/Components/ProjectFormDialog.vue';
import ProjectsOverviewWidget from '@/Modules/Projects/Components/ProjectsOverviewWidget.vue';
import ProjectStatusActions from '@/Modules/Projects/Components/ProjectStatusActions.vue';
import ProjectTeamPanel from '@/Modules/Projects/Components/ProjectTeamPanel.vue';
import TaskAssigneesDialog from '@/Modules/Projects/Components/TaskAssigneesDialog.vue';
import TaskBoard from '@/Modules/Projects/Components/TaskBoard.vue';
import TaskDetailDialog from '@/Modules/Projects/Components/TaskDetailDialog.vue';
import TaskFormDialog from '@/Modules/Projects/Components/TaskFormDialog.vue';
import TaskGantt from '@/Modules/Projects/Components/TaskGantt.vue';
import ViewSwitcher from '@/Modules/Projects/Components/ViewSwitcher.vue';
import ExpensesIndex from '@/Modules/Projects/Pages/Expenses/Index.vue';
import PortalShow from '@/Modules/Projects/Pages/Portal/Show.vue';
import type { ProjectView, Task } from '@/Modules/Projects/Components/types';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const modalStub = defineComponent({ template: '<div><slot /><slot name="footer" /></div>' });
const task = (overrides: Partial<Task> = {}): Task => ({ id: 'task-1', title: 'Prepare launch', status: 'todo', priority: 'normal', startDate: '2026-10-10', dueDate: '2026-10-12', assignees: [], ...overrides });

beforeEach(() => { inertia.visits.length = 0; inertia.submissions.length = 0; Object.values(inertia.router).forEach((mock) => mock.mockClear()); setLocale('en'); inertia.page.props.app.numerals = 'latn'; });
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()));

describe('Projects frontend', () => {
    it('renders canonical Kanban columns and completed work', () => {
        const wrapper = track(mount(TaskBoard, { props: { tasks: [task({ status: 'completed' })] } }));
        const headings = wrapper.findAll('h3').map((item) => item.text());
        expect(headings).toEqual(['Backlog', 'To do', 'In progress', 'Review', 'Completed', 'Cancelled']);
        expect(wrapper.text()).toContain('Prepare launch');
        expect(wrapper.text()).toContain('Normal');
    });

    it('keeps cancelled work discoverable on the board', () => {
        const wrapper = track(mount(TaskBoard, { props: { tasks: [task({ status: 'cancelled' })] } }));
        expect(wrapper.text()).toContain('Cancelled');
        expect(wrapper.text()).toContain('Prepare launch');
    });

    it('changes a task status by dropping its card on another column', async () => {
        const wrapper = track(mount(TaskBoard, { props: { tasks: [task({ editable: true })], projectId: 'project-1' } }));
        const target = wrapper.get('section[aria-labelledby="task-column-in_progress"]');

        await wrapper.get('button[draggable="true"]').trigger('dragstart');
        await target.trigger('dragover');
        await target.trigger('drop');

        expect(inertia.router.patch).toHaveBeenCalledWith('/projects.tasks.status?project=project-1&task=task-1', { status: 'in_progress' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('keeps closed and view-only tasks where they are', async () => {
        const wrapper = track(mount(TaskBoard, { props: { tasks: [task({ status: 'completed', editable: true }), task({ id: 'task-2', title: 'Read only', editable: false })], projectId: 'project-1' } }));
        const target = wrapper.get('section[aria-labelledby="task-column-review"]');

        expect(wrapper.findAll('button[draggable="true"]')).toHaveLength(0);
        for (const card of wrapper.findAll('button[draggable="false"]')) {
            await card.trigger('dragstart');
            await target.trigger('drop');
        }

        expect(inertia.router.patch).not.toHaveBeenCalled();
    });

    it('shows why the server refused a dropped task', async () => {
        inertia.router.patch.mockImplementationOnce((_url: string, _data: unknown, options: { onError: (errors: Record<string, string>) => void; onFinish: () => void }) => {
            options.onError({ status: 'Complete this task’s dependencies first.' });
            options.onFinish();
        });
        const wrapper = track(mount(TaskBoard, { props: { tasks: [task({ editable: true })], projectId: 'project-1' } }));

        await wrapper.get('button[draggable="true"]').trigger('dragstart');
        await wrapper.get('section[aria-labelledby="task-column-completed"]').trigger('drop');

        expect(wrapper.get('[role="alert"]').text()).toBe('Complete this task’s dependencies first.');
    });

    it('exposes a dependency-free accessible Gantt range', () => {
        const wrapper = track(mount(TaskGantt, { props: { tasks: [task()] } }));
        expect(wrapper.get('button[aria-label]').attributes('aria-label')).toContain('Prepare launch');
        expect(wrapper.get('button[aria-label]').attributes('style')).toContain('width');
    });

    it('switches between every project work view', async () => {
        const wrapper = track(mount(ViewSwitcher, { props: { modelValue: 'board' as ProjectView, 'onUpdate:modelValue': (value: ProjectView) => wrapper.setProps({ modelValue: value }) } }));
        expect(wrapper.findAll('[role="radio"]')).toHaveLength(4);
        await wrapper.findAll('[role="radio"]')[3]!.trigger('click');
        expect(wrapper.props('modelValue')).toBe('gantt');
    });

    it('uses the exact project form contract without fake fields', () => {
        const wrapper = track(mount(ProjectFormDialog, { props: { open: true, canManageFinancials: true, options: { categories: [], templates: [], owners: [], contacts: [], companies: [], currency: 'BDT' } }, global: { stubs: { Modal: modalStub } } }));
        const labels = wrapper.findAll('label').map((item) => item.text());
        expect(labels).toEqual(expect.arrayContaining(['Name', 'Priority', 'Start date', 'Due date', 'Budget (BDT, minor units)']));
        expect(labels).not.toEqual(expect.arrayContaining(['Code', 'Status', 'Currency']));
    });

    it('does not offer or submit immutable owner and restricted budget fields while editing', async () => {
        const wrapper = track(mount(ProjectFormDialog, {
            props: {
                open: true,
                canManageFinancials: false,
                project: { id: 'project-1', name: 'Launch', status: 'active', priority: 'normal', progress: 20, currency: 'BDT', owner: { id: 'member-1', name: 'Nadia' }, budgetAmount: 50_000 },
                options: { categories: [], templates: [], owners: [{ id: 'member-1', name: 'Nadia' }], contacts: [], companies: [], currency: 'BDT' },
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const labels = wrapper.findAll('label').map((item) => item.text());
        expect(labels).not.toContain('Owner');
        expect(labels.some((label) => label.startsWith('Budget'))).toBe(false);
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data).not.toHaveProperty('owner_id');
        expect(inertia.submissions.at(-1)?.data).not.toHaveProperty('budget_amount');
    });

    it('offers task assignment only when the backend grants assign access', () => {
        const denied = track(mount(TaskFormDialog, { props: { open: true, projectId: 'project-1', options: { canAssign: false, members: [{ id: 'member-1', name: 'Nadia' }], milestones: [], sprints: [], parentTasks: [] } }, global: { stubs: { Modal: modalStub } } }));
        const allowed = track(mount(TaskFormDialog, { props: { open: true, projectId: 'project-1', options: { canAssign: true, members: [{ id: 'member-1', name: 'Nadia' }], milestones: [], sprints: [], parentTasks: [] } }, global: { stubs: { Modal: modalStub } } }));
        expect(denied.findAll('label').map((item) => item.text())).not.toContain('Assignee');
        expect(allowed.findAll('label').map((item) => item.text())).toContain('Assignee');
    });

    it('does not offer the edited task or its descendants as parent tasks', () => {
        const wrapper = track(mount(TaskFormDialog, {
            props: {
                open: true,
                projectId: 'project-1',
                task: task(),
                options: {
                    canAssign: false,
                    members: [], milestones: [], sprints: [],
                    parentTasks: [
                        { id: 'task-1', name: 'Prepare launch' },
                        { id: 'task-2', name: 'Child', parentId: 'task-1' },
                        { id: 'task-3', name: 'Grandchild', parentId: 'task-2' },
                        { id: 'task-4', name: 'Independent' },
                    ],
                },
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const parentSelect = wrapper.findAll('select').find((select) => select.element.labels?.[0]?.textContent?.includes('Parent task'));
        expect(parentSelect?.text()).toContain('Independent');
        expect(parentSelect?.text()).not.toContain('Prepare launch');
        expect(parentSelect?.text()).not.toContain('Child');
        expect(parentSelect?.text()).not.toContain('Grandchild');
    });

    it('converts exact task instants to workspace-local datetime controls', () => {
        const wrapper = track(mount(TaskFormDialog, {
            props: {
                open: true,
                projectId: 'project-1',
                task: task({ startsAt: '2026-10-10T03:30:00Z', dueAt: '2026-10-10T04:45:00Z', remindAt: '2026-10-10T03:00:00Z' }),
                options: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] },
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const values = wrapper.findAll('input[type="datetime-local"]').map((input) => (input.element as HTMLInputElement).value);
        expect(values).toEqual(['2026-10-10T09:30', '2026-10-10T10:45', '2026-10-10T09:00']);
    });

    it('prefers server-projected workspace-local task datetimes', () => {
        const wrapper = track(mount(TaskFormDialog, {
            props: {
                open: true,
                projectId: 'project-1',
                task: task({ startsAt: '2026-10-10T03:30:00Z', dueAt: '2026-10-10T04:45:00Z', remindAt: '2026-10-10T03:00:00Z', startsLocal: '2026-10-10T08:15', dueLocal: '2026-10-10T11:30', remindLocal: '2026-10-10T07:45' }),
                options: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] },
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const values = wrapper.findAll('input[type="datetime-local"]').map((input) => (input.element as HTMLInputElement).value);
        expect(values).toEqual(['2026-10-10T08:15', '2026-10-10T11:30', '2026-10-10T07:45']);
    });

    it('preserves an existing recurrence rule while editing a recurring task', async () => {
        const recurrenceRule = { frequency: 'monthly' as const, interval: 2, until: '2027-03-31' };
        const wrapper = track(mount(TaskFormDialog, {
            props: {
                open: true,
                projectId: 'project-1',
                task: task({ recurrenceRule }),
                options: { canAssign: false, members: [], milestones: [], sprints: [], parentTasks: [] },
            },
            global: { stubs: { Modal: modalStub } },
        }));
        expect((wrapper.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true);
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data.recurrence_rule).toEqual(recurrenceRule);
    });

    it('renders the workspace widget in Bengali with locale numerals', () => {
        setLocale('bn'); inertia.page.props.app.numerals = 'beng';
        const wrapper = track(mount(ProjectsOverviewWidget, { props: { data: { activeProjects: 3, openTasks: 7, overdueTasks: 1, dueSoonTasks: 2 } } }));
        expect(wrapper.text()).toContain('প্রজেক্ট');
        expect(wrapper.text()).toContain('৩');
    });

    it('keeps portal comments hidden when the client grant is read-only', () => {
        const wrapper = track(mount(PortalShow, { props: { project: { id: 'p1', code: 'PRJ-1', name: 'Client launch', status: 'active', progress: 25 }, tasks: [{ ...task(), progress: 10, checklist: [], comments: [] }], tasksPagination: { currentPage: 1, perPage: 50, total: 1, lastPage: 1, hasMorePages: false, previousPageUrl: null, nextPageUrl: null }, client: { name: 'Nadia', canComment: false }, financials: null, token: 'secret' } }));
        expect(wrapper.text()).toContain('Client launch');
        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text() === 'Add a comment')).toBe(false);
    });

    it('posts client portal grants through the named route', async () => {
        const wrapper = track(mount(ClientAccessDialog, { props: { open: true, projectId: 'project-1', contacts: [{ id: 'contact-1', name: 'Nadia' }], companies: [], canManageFinancials: true }, global: { stubs: { Modal: modalStub } } }));
        await wrapper.get('form').trigger('submit');
        expect(inertia.visits).toEqual([{ method: 'post', url: '/projects.client-access.store?project=project-1' }]);
    });

    it('submits approval requests with the selected entity contract', async () => {
        const wrapper = track(mount(ApprovalRequestDialog, {
            props: { open: true, projectId: 'project-1', entityType: 'task', entityId: 'task-1', subject: 'Prepare launch', members: [{ id: 'member-1', name: 'Nadia' }, { id: 'member-2', name: 'Rafi' }], currentMembershipId: 'member-1' },
            global: { stubs: { Modal: modalStub } },
        }));
        await wrapper.get('form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'post', url: '/projects.approvals.store' });
        expect(inertia.submissions.at(-1)?.data).toMatchObject({ project_id: 'project-1', entity_type: 'task', entity_id: 'task-1' });
    });

    it('posts project team changes through the member endpoint', async () => {
        const wrapper = track(mount(ProjectTeamPanel, {
            props: { projectId: 'project-1', members: [], options: [{ id: 'member-2', name: 'Rafi' }], canManage: true },
            global: { stubs: { Modal: modalStub, ConfirmDialog: true } },
        }));
        await wrapper.get('#project-member-form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'post', url: '/projects.members.store?project=project-1' });
        expect(inertia.submissions.at(-1)?.data).toMatchObject({ role: 'member', allocation_percent: '100' });
    });

    it('posts task comments with mention and visibility fields', async () => {
        const wrapper = track(mount(TaskDetailDialog, {
            props: { open: true, projectId: 'project-1', task: task({ editable: true, commentable: true, comments: [], checklist: [], dependencies: [], attachments: [] }), members: [{ id: 'member-2', name: 'Rafi' }], approvalMembers: [{ id: 'member-2', name: 'Rafi' }], assigneeOptions: [], taskOptions: [], currentMembershipId: 'member-1' },
            global: { stubs: { Modal: modalStub, ApprovalRequestDialog: true, AttachmentsPanel: true } },
        }));
        const commentForm = wrapper.findAll('form').find((form) => form.find('textarea').exists());
        expect(commentForm).toBeDefined();
        await commentForm!.get('textarea').setValue('Ready for review');
        await commentForm!.trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'post', url: '/projects.tasks.comments.store?project=project-1&task=task-1' });
        expect(inertia.submissions.at(-1)?.data).toMatchObject({ body: 'Ready for review', mentions: [], is_internal: false });
    });

    it('submits multiple task assignees with explicit allocations', async () => {
        const wrapper = track(mount(TaskAssigneesDialog, {
            props: { open: true, projectId: 'project-1', task: task({ assignable: true }), members: [{ id: 'member-1', name: 'Nadia' }, { id: 'member-2', name: 'Rafi' }] },
            global: { stubs: { Modal: modalStub } },
        }));
        await wrapper.get('button').trigger('click');
        await wrapper.findAll('button').find((button) => button.text() === 'Add assignee')!.trigger('click');
        await wrapper.get('#task-assignees-form').trigger('submit');
        expect(inertia.visits.at(-1)).toEqual({ method: 'put', url: '/projects.tasks.assign?project=project-1&task=task-1' });
        expect(inertia.submissions.at(-1)?.data.assignees).toEqual([
            { membership_id: 'member-1', allocation_percent: '100' },
            { membership_id: 'member-2', allocation_percent: '100' },
        ]);
    });

    it('offers only valid project lifecycle transitions', async () => {
        const wrapper = track(mount(ProjectStatusActions, { props: { projectId: 'project-1', status: 'planned' }, global: { stubs: { ConfirmDialog: true } } }));
        expect(wrapper.text()).toContain('Start project');
        expect(wrapper.text()).toContain('Cancel project');
        expect(wrapper.text()).not.toContain('Complete project');
        await wrapper.findAll('button').find((button) => button.text() === 'Start project')!.trigger('click');
        expect(inertia.router.patch).toHaveBeenCalledWith('/projects.status?project=project-1', { status: 'active' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('cannot grant client financial visibility without financial access', async () => {
        const wrapper = track(mount(ClientAccessDialog, { props: { open: true, projectId: 'project-1', contacts: [{ id: 'contact-1', name: 'Nadia' }], companies: [], canManageFinancials: false }, global: { stubs: { Modal: modalStub } } }));
        expect(wrapper.findAll('label').map((item) => item.text())).not.toContain('Share project financials');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submissions.at(-1)?.data.can_view_financials).toBe(false);
    });

    it('renders the backend expense summary contract and hides forbidden submit actions', () => {
        const wrapper = track(mount(ExpensesIndex, {
            props: {
                expenses: [{ id: 'expense-1', project: { id: 'project-1', name: 'Launch' }, member: { id: 'member-1', name: 'Nadia' }, date: '2026-10-10', amount: 1_234, currency: 'BDT', description: 'Taxi', status: 'draft', billable: false, approvable: false, submittable: false }],
                expensesPagination: { currentPage: 1, perPage: 50, total: 1, lastPage: 1, hasMorePages: false, previousPageUrl: null, nextPageUrl: null },
                projects: [{ id: 'project-1', name: 'Launch' }],
                summary: { currency: 'BDT', totalAmount: '900719925474099312345', pendingAmount: '-5' },
                canCreate: false,
                approvalMembers: [],
                currentMembershipId: 'member-1',
            },
        }));
        expect(wrapper.text()).toContain('9,007,199,254,740,993,123.45');
        expect(wrapper.text()).toContain('-BDT\u00a00.05');
        expect(wrapper.text()).toContain('12.34');
        expect(wrapper.text()).not.toContain('NaN');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Submit expense')).toBe(false);
    });
});
