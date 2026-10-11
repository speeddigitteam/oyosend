import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProjectTaskSelect from '@/Modules/Projects/Components/ProjectTaskSelect.vue';

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string | number> = {}) => {
        const query = new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)])).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const pagination = (overrides: Partial<{ currentPage: number; perPage: number; total: number; lastPage: number; hasMorePages: boolean; previousPageUrl: string | null; nextPageUrl: string | null }> = {}) => ({
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

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('ProjectTaskSelect', () => {
    it('loads paginated project tasks and debounces server-side search', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = new URL(String(input), 'https://app.test');
            const page = Number(url.searchParams.get('page'));
            const search = url.searchParams.get('search') ?? '';
            const tasks = search
                ? [{ id: 'task-search', name: 'Launch review', projectId: 'project-1' }]
                : page === 2
                    ? [{ id: 'task-2', name: 'Second task', projectId: 'project-1' }]
                    : [{ id: 'task-1', name: 'First task', projectId: 'project-1' }];
            return jsonResponse({
                tasks,
                pagination: pagination({ currentPage: page, total: search ? 1 : 51, lastPage: search ? 1 : 2, hasMorePages: !search && page === 1 }),
                filters: { projectId: 'project-1', search },
            });
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(ProjectTaskSelect, { props: { projectId: 'project-1' } }));
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]![0])).toContain('project_id=project-1');
        expect(wrapper.get('select').text()).toContain('First task');
        expect(wrapper.get('input[type="search"]').attributes('maxlength')).toBe('100');

        await wrapper.findAll('button').find((button) => button.text() === 'Load more tasks')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('select').text()).toContain('Second task');

        await wrapper.get('input[type="search"]').setValue('launch');
        expect(fetchMock).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(String(fetchMock.mock.calls[2]![0])).toContain('search=launch');
        expect(wrapper.get('select').text()).toContain('Launch review');
        expect(wrapper.text()).not.toContain('Load more tasks');
    });

    it('preserves a hydrated selection but clears it when the project changes', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ tasks: [], pagination: pagination(), filters: { projectId: 'project-1', search: '' } })));
        const wrapper = track(mount(ProjectTaskSelect, {
            props: {
                projectId: 'project-1',
                modelValue: 'task-selected',
                selectedTask: { id: 'task-selected', name: 'Previously selected task', projectId: 'project-1' },
            },
        }));
        await flushPromises();

        expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('task-selected');
        expect(wrapper.get('select').text()).toContain('Previously selected task');
        expect(wrapper.get('[role="status"]').text()).toContain('No matching tasks in this project.');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();

        await wrapper.setProps({ projectId: 'project-2' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['']);
    });

    it('announces load failures and retries without dropping the current selection', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({}, false))
            .mockResolvedValueOnce(jsonResponse({ tasks: [], pagination: pagination(), filters: { projectId: 'project-1', search: '' } }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(ProjectTaskSelect, {
            props: {
                projectId: 'project-1',
                modelValue: 'task-selected',
                selectedTask: { id: 'task-selected', name: 'Previously selected task', projectId: 'project-1' },
            },
        }));
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Tasks could not be loaded.');
        expect(wrapper.get('select').text()).toContain('Previously selected task');

        await wrapper.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.get('select').text()).toContain('Previously selected task');
    });

    it('drops stale pagination when a searched request fails', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({
                tasks: [{ id: 'task-1', name: 'First task', projectId: 'project-1' }],
                pagination: pagination({ total: 51, lastPage: 2, hasMorePages: true }),
                filters: { projectId: 'project-1', search: '' },
            }))
            .mockResolvedValueOnce(jsonResponse({}, false));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(ProjectTaskSelect, { props: { projectId: 'project-1' } }));
        await flushPromises();

        expect(wrapper.text()).toContain('Load more tasks');
        await wrapper.get('input[type="search"]').setValue('unavailable');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Tasks could not be loaded.');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Load more tasks')).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
