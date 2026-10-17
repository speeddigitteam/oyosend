import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' } } },
    router: { post: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => undefined) },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
    usePage: () => inertia.page,
    router: inertia.router,
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => (params.notification ? `/${name}/${params.notification}` : `/${name}`),
    isCurrentRoute: () => false,
}));

import NotificationBell from '@/Core/Components/Shell/NotificationBell.vue';
import { setLocale } from '@/Core/i18n';

const item = (id: string, overrides: Record<string, unknown> = {}) => ({
    id, severity: 'info', title: `Notification ${id}`, url: `/somewhere/${id}`, createdAt: '2026-10-14T04:00:00Z', ...overrides,
});

function answer(unread: number, items: unknown[]): void {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ unread, items }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
}

let wrapper: VueWrapper | null = null;

async function mountBell(): Promise<VueWrapper> {
    wrapper = mount(NotificationBell, { attachTo: document.body });
    await flushPromises();

    return wrapper;
}

async function openMenu(bell: VueWrapper): Promise<void> {
    const trigger = bell.get('[data-testid="notification-bell"]');
    await trigger.trigger('keydown', { key: 'Enter' });
    await flushPromises();
}

beforeEach(() => {
    setLocale('en');
    inertia.router.post.mockReset();
    inertia.router.visit.mockReset();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('the notification bell', () => {
    it('asks the summary endpoint and shows the unread count on the bell', async () => {
        answer(3, [item('a'), item('b'), item('c')]);
        const bell = await mountBell();

        expect(fetch).toHaveBeenCalledWith('/communication.notifications.summary', expect.objectContaining({ credentials: 'same-origin' }));
        expect(bell.get('[data-testid="notification-bell-count"]').text()).toBe('3');
        expect(bell.get('[data-testid="notification-bell"]').attributes('aria-label')).toBe('Notifications, 3 unread');
    });

    it('caps the badge and shows no badge when nothing is unread', async () => {
        answer(42, [item('a')]);
        const busy = await mountBell();
        expect(busy.get('[data-testid="notification-bell-count"]').text()).toBe('9+');
        busy.unmount();

        answer(0, []);
        const quiet = await mountBell();
        expect(quiet.find('[data-testid="notification-bell-count"]').exists()).toBe(false);
        expect(quiet.get('[data-testid="notification-bell"]').attributes('aria-label')).toBe('Notifications');
    });

    it('keeps the bell quiet when the endpoint refuses (no entitlement, paywall, offline)', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 403 })));
        const bell = await mountBell();

        expect(bell.find('[data-testid="notification-bell-count"]').exists()).toBe(false);
    });

    it('lists the latest, marks one read when opened and follows its link', async () => {
        answer(2, [item('a'), item('b', { url: null })]);
        const bell = await mountBell();
        await openMenu(bell);

        const rows = document.querySelectorAll('[data-testid="notification-bell-item"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]?.textContent).toContain('Notification a');

        inertia.router.post.mockImplementation((_url: string, _data: unknown, options: { onSuccess?: () => void }) => options.onSuccess?.());
        (rows[0] as HTMLElement).click();
        await flushPromises();

        expect(inertia.router.post).toHaveBeenCalledWith('/communication.notifications.read/a', {}, expect.any(Object));
        expect(inertia.router.visit).toHaveBeenCalledWith('/somewhere/a');
        expect(bell.get('[data-testid="notification-bell-count"]').text()).toBe('1');
    });

    it('drops a summary still on its way when everything is marked read, so the old items do not come back', async () => {
        answer(2, [item('a'), item('b')]);
        const bell = await mountBell();
        await openMenu(bell);

        // A slow refresh, started before the click, that answers with the old unread items only after it.
        let release: () => void = () => undefined;
        vi.stubGlobal('fetch', vi.fn((_url: string, init: RequestInit) => new Promise<Response>((resolve, reject) => {
            init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            release = () => resolve(new Response(JSON.stringify({ unread: 2, items: [item('a'), item('b')] }), { status: 200 }));
        })));
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();
        expect(fetch).toHaveBeenCalledTimes(1);

        const markAll = [...document.querySelectorAll('[role="menuitem"]')].find((row) => row.textContent?.includes('Mark all as read'));
        (markAll as HTMLElement).click();
        release();
        await flushPromises();

        expect(inertia.router.post).toHaveBeenCalledWith('/communication.notifications.read-all', {}, expect.any(Object));
        expect(bell.find('[data-testid="notification-bell-count"]').exists()).toBe(false);
    });
    it('says so when everything has been read', async () => {
        answer(0, []);
        const bell = await mountBell();
        await openMenu(bell);

        expect(document.querySelector('[data-testid="notification-bell-empty"]')?.textContent).toContain('You are all caught up.');
    });
});
