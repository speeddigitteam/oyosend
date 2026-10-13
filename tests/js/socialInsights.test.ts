import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.analytics.view'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
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
import AccountAccessSection from '@/Modules/Social/Components/insights/AccountAccessSection.vue';
import PageInsightsPanel from '@/Modules/Social/Components/insights/PageInsightsPanel.vue';
import TrendChart from '@/Modules/Social/Components/insights/TrendChart.vue';
import { INSIGHT_FIGURES, type AccessPanelProps, type InsightsDashboard, type InsightKpi } from '@/Modules/Social/Components/insights/types';
import type { AccountRow } from '@/Modules/Social/Components/types';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });

const dates = ['2026-04-09', '2026-04-10', '2026-04-11'];
const nothing = { impressions: null, reach: null, likes: null, comments: null, shares: null, clicks: null };

function kpi(figure: (typeof INSIGHT_FIGURES)[number], overrides: Partial<InsightKpi> = {}): InsightKpi {
    return {
        figure, level: figure === 'followers' || figure === 'fans', value: null, previous: null, delta: null, deltaPct: null,
        days: 0, previousDays: 0, expectedDays: 7, asOf: null, ...overrides,
    };
}

function dashboard(overrides: Partial<InsightsDashboard> = {}): InsightsDashboard {
    const trend = Object.fromEntries(INSIGHT_FIGURES.map((figure) => [figure, {
        total: figure === 'impressions' ? [null, 100, 250] : [null, null, null],
        accounts: [{ id: 'p1', name: 'Brand Page', values: figure === 'impressions' ? [null, 100, 250] : [null, null, null] }],
    }])) as unknown as InsightsDashboard['trend'];

    return {
        state: 'ready',
        period: { days: 7, from: '2026-04-09', to: '2026-04-15', previousFrom: '2026-04-02', previousTo: '2026-04-08', timezone: 'Asia/Dhaka', dates, options: [7, 28, 90] },
        pages: [{ id: 'p1', name: 'Brand Page', platform: 'facebook', selected: true }, { id: 'p2', name: 'Shop Page', platform: 'facebook', selected: false }],
        selected: ['p1'],
        maxPages: 10,
        kpis: INSIGHT_FIGURES.map((figure) => {
            if (figure === 'impressions') {
                return kpi(figure, { value: 350, previous: 100, delta: 250, deltaPct: 250, days: 2, previousDays: 2 });
            }

            if (figure === 'page_views') {
                return kpi(figure, { value: 20, previous: 5, days: 2, previousDays: 1 });
            }

            if (figure === 'unfollows') {
                return kpi(figure, { value: 9, previous: 3, delta: 6, deltaPct: 200, days: 7, previousDays: 7 });
            }

            return kpi(figure);
        }),
        trend,
        comparison: [{ id: 'p1', name: 'Brand Page', platform: 'facebook', platformLabel: 'Facebook Pages', status: 'connected', figures: {}, daysReported: 2, insightsStatus: 'ok', syncedAt: null }],
        posts: {
            published: 0, reported: 0, top: [], bottom: [], contentTypes: [], campaigns: [],
            bestTimes: { windowDays: 90, timezone: 'Asia/Dhaka', posts: 0, minimum: 10, sufficient: false, byHour: [], byWeekday: [], slots: [] },
        },
        coverage: { lastUpdatedAt: '2026-04-15T10:00:00+00:00', daysReported: 2, expectedDays: 7, notes: ['platform_days', 'partial_days'] },
        ...overrides,
    };
}

beforeEach(() => {
    setLocale('en');
    Object.values(inertia.router).forEach((fn) => fn.mockClear());
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('page performance dashboard', () => {
    const range = { from: '2026-03-17', to: '2026-04-15' };

    it('shows "Not reported" for a missing figure and never zero', () => {
        const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard(), range } }));
        const tiles = wrapper.get('[data-testid="kpis"]').text();

        expect(tiles).toContain('Not reported');
        expect(tiles).toContain('The platform returned nothing for this figure.');
        expect(wrapper.get('[data-testid="kpis"]').findAll('.figure').filter((el) => el.text() === '0')).toHaveLength(0);
    });

    it('shows the change only when both periods cover the same days, and says why otherwise', () => {
        const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard(), range } }));

        expect(wrapper.get('[data-testid="delta-impressions"]').text()).toContain('+250');
        expect(wrapper.get('[data-testid="delta-impressions"]').text()).toContain('250');
        expect(wrapper.find('[data-testid="delta-page_views"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="kpis"]').text()).toContain('Not compared: 2 days reported now, 1 in the previous period.');
        // More unfollows is bad news: it is drawn in the danger colour.
        expect(wrapper.get('[data-testid="delta-unfollows"] span').classes()).toContain('text-danger');
        expect(wrapper.get('[data-testid="delta-impressions"] span').classes()).toContain('text-ok');
    });

    it('prints the data coverage, the last update and the notes honestly', () => {
        const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard(), range } }));
        const text = wrapper.get('[data-testid="coverage"]').text();

        expect(text).toContain('Last read from the platform');
        expect(text).toContain('2 of 7 days');
        expect(text).toContain('Meta uses Pacific Time');
        expect(text).toContain('fewer days than the period');
    });

    it('explains what to do in each empty state', () => {
        const states = {
            no_accounts: 'Connect a Facebook Page under Accounts',
            restricted: 'Ask someone who manages accounts',
            not_configured: 'has not set up the platform app',
        } as const;

        for (const [state, words] of Object.entries(states)) {
            const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard({ state: state as InsightsDashboard['state'] }), range } }));
            expect(wrapper.get(`[data-testid="state-${state}"]`).text()).toContain(words);
            expect(wrapper.find('[data-testid="kpis"]').exists()).toBe(false);
        }

        const waiting = track(mount(PageInsightsPanel, { props: { insights: dashboard({ state: 'waiting' }), range } }));
        expect(waiting.get('[data-testid="state-waiting"]').text()).toContain('starting with the last 28 days');
        expect(waiting.find('[data-testid="kpis"]').exists()).toBe(true);
    });

    it('changes the period and the pages through the server, keeping the report range', async () => {
        const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard(), range } }));

        await wrapper.findAll('[role="radio"]').find((button) => button.text() === '90 days')!.trigger('click');
        expect(inertia.router.get).toHaveBeenCalledWith(
            '/social.analytics.index',
            { from: '2026-03-17', to: '2026-04-15', days: '90', pages: ['p1'] },
            expect.objectContaining({ preserveState: true }),
        );

        const boxes = wrapper.findAll('fieldset input[type="checkbox"]');
        await boxes[1].setValue(true);
        await wrapper.get('form').trigger('submit');
        expect(inertia.router.get).toHaveBeenLastCalledWith(
            '/social.analytics.index',
            expect.objectContaining({ pages: ['p1', 'p2'] }),
            expect.anything(),
        );
    });

    it('offers CSV downloads as plain links, with the chosen pages', () => {
        const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard(), range } }));
        const link = wrapper.get('[data-testid="export-daily"]');

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toContain('/social.analytics.export?');
        expect(link.attributes('href')).toContain('dataset=daily');
        expect(link.attributes('href')).toContain('p1');
    });

    it('renders in Bengali with the same structure', () => {
        setLocale('bn');
        const wrapper = track(mount(PageInsightsPanel, { props: { insights: dashboard(), range } }));

        expect(wrapper.text()).toContain('পেজের পারফরম্যান্স');
        expect(wrapper.text()).toContain('জানানো হয়নি');
        expect(wrapper.text()).not.toContain('Not reported');
    });
});

describe('trend chart', () => {
    it('draws gaps for missing days, prints the numbers and reads days with the arrow keys', async () => {
        const wrapper = track(mount(TrendChart, {
            props: { dates, label: 'Daily impressions', series: [{ id: 'a', name: 'Brand Page', values: [null, 100, 250] }] },
        }));

        // One path (two connected points), nothing drawn for the missing first day.
        expect(wrapper.findAll('svg path')).toHaveLength(1);
        expect(wrapper.get('table').text()).toContain('Not reported');
        expect(wrapper.get('table').text()).toContain('250');

        const group = wrapper.get('[role="group"]');
        await group.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.get('[data-testid="readout"]').text()).toContain('Not reported');
        await group.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.get('[data-testid="readout"]').text()).toContain('100');
        await group.trigger('keydown', { key: 'Escape' });
        expect(wrapper.get('[data-testid="readout"]').text()).toBe('');
    });

    it('says so when nothing was reported', () => {
        const wrapper = track(mount(TrendChart, { props: { dates, label: 'Daily reach', series: [{ id: 'a', name: 'P', values: [null, null, null] }] } }));

        expect(wrapper.text()).toContain('has not reported this figure');
        expect(wrapper.findAll('svg path')).toHaveLength(0);
    });
});

describe('account access', () => {
    const account: AccountRow = {
        id: 'acc-1', platform: 'facebook', platformLabel: 'Facebook Pages', name: 'Acme Bakery', handle: null, avatarUrl: null, type: 'page',
        status: 'connected', statusReason: null, needsReauthorization: false, platformAvailable: true,
        capabilities: { publish: true, schedule: true, analytics: true, comments: false, images: true, video: false, maxText: 10, maxMedia: 1, insights: true } as AccountRow['capabilities'],
        connectedAt: null, lastUsedAt: null, tokenExpiresAt: null, canRefresh: false,
    };
    const panel = (overrides: Partial<AccessPanelProps['accounts'][string]> = {}, canManage = true): AccessPanelProps => ({
        accounts: {
            'acc-1': {
                tasks: ['CREATE_CONTENT'], tasksStatus: 'ok', tasksCheckedAt: '2026-04-15T10:00:00+00:00', scopes: ['pages_show_list'],
                insightsStatus: 'ok', insightsSyncedAt: null, restricted: false, entries: [], entryCount: 0, ...overrides,
            },
        },
        features: [
            { feature: 'publish', task: 'CREATE_CONTENT', permissions: ['pages_manage_posts'] },
            { feature: 'insights', task: 'ANALYZE', permissions: ['read_insights', 'pages_read_engagement'] },
        ],
        canManage,
        members: [{ id: 'm1', name: 'Rina' }],
        roles: [{ id: 'r1', name: 'Editors' }],
    });
    const stubs = { Modal: modalStub };

    it('lists the tasks the token holds and which feature each one unlocks', () => {
        const wrapper = track(mount(AccountAccessSection, { props: { accounts: [account], panel: panel() }, global: { stubs } }));

        expect(wrapper.get('[data-testid="tasks"]').text()).toContain('Create content');
        const rows = wrapper.findAll('tbody tr');
        expect(rows[0].text()).toContain('Publish and schedule posts');
        expect(rows[0].text()).toContain('Available');
        expect(rows[1].text()).toContain('Analyze');
        expect(rows[1].text()).toContain('Task missing');
        expect(rows[1].text()).toContain('read_insights');
    });

    it('says "Not reported" instead of listing no tasks when the roles could not be read', () => {
        const wrapper = track(mount(AccountAccessSection, {
            props: { accounts: [account], panel: panel({ tasks: null, tasksStatus: 'permission_missing', scopes: null }) },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="tasks-unknown"]').text()).toContain('did not let us read');
        expect(wrapper.text()).toContain('Not reported');
        expect(wrapper.find('[data-testid="tasks"]').exists()).toBe(false);
    });

    it('hides the management buttons from someone who cannot manage accounts', () => {
        const wrapper = track(mount(AccountAccessSection, { props: { accounts: [account], panel: panel({}, false) }, global: { stubs } }));

        expect(wrapper.text()).not.toContain('Who may use this page');
        expect(wrapper.text()).not.toContain('Check access');
    });

    it('asks the platform to re-check access through the server', async () => {
        const wrapper = track(mount(AccountAccessSection, { props: { accounts: [account], panel: panel() }, global: { stubs } }));

        await wrapper.findAll('button').find((button) => button.text().includes('Check access'))!.trigger('click');
        expect(inertia.router.post).toHaveBeenCalledWith('/social.accounts.access.check?account=acc-1', {}, expect.anything());
    });

    it('edits the allow-list and sends only ids and flags', async () => {
        const wrapper = track(mount(AccountAccessSection, { props: { accounts: [account], panel: panel() }, global: { stubs } }));

        await wrapper.findAll('button').find((button) => button.text().includes('Who may use this page'))!.trigger('click');
        expect(wrapper.text()).toContain('Nobody is listed');

        await wrapper.get('select').setValue('membership:m1');
        expect(wrapper.get('[data-testid="allow-list"]').text()).toContain('Member: Rina');

        await wrapper.get('form').trigger('submit');
        expect(inertia.router.put).toHaveBeenCalledWith(
            '/social.accounts.access.update?account=acc-1',
            { entries: [{ subject_type: 'membership', subject_id: 'm1', can_publish: true, can_approve: false, can_analytics: true }] },
            expect.anything(),
        );
    });

    it('renders only for platforms that report insights', () => {
        const plain = { ...account, capabilities: { ...account.capabilities, insights: false } } as AccountRow;
        const wrapper = track(mount(AccountAccessSection, { props: { accounts: [plain], panel: panel() }, global: { stubs } }));

        expect(wrapper.find('[data-testid="access-panel"]').exists()).toBe(false);
    });
});

describe('catalogues', () => {
    it('has every English key in Bengali', () => {
        const flatten = (value: unknown, prefix = ''): string[] => (typeof value === 'object' && value !== null
            ? Object.entries(value).flatMap(([key, child]) => flatten(child, `${prefix}${key}.`))
            : [prefix]);

        expect(flatten(bn.socialInsights).sort()).toEqual(flatten(en.socialInsights).sort());
    });
});
