import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

interface Submission { method: string; url: string; data: Record<string, unknown> }
const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: ['meta_ads.insights.view'] as string[] } } },
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.clearErrors = () => {};
        form.transform = (callback: (data: Record<string, unknown>) => Record<string, unknown>) => { transform = callback; return form; };
        form.post = (url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));
            inertia.submissions.push({ method: 'post', url, data: transform(data) });
            options?.onSuccess?.();
        };
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
import ChangeDialog from '@/Modules/MetaAds/Components/ChangeDialog.vue';
import { bpToPercent, KNOWN_STATUSES, minorToDecimal, newRequestId, statusTone } from '@/Modules/MetaAds/Components/metaAdsFormat';
import MetaAdsOverviewWidget from '@/Modules/MetaAds/Components/MetaAdsOverviewWidget.vue';
import MetaStatusBadge from '@/Modules/MetaAds/Components/MetaStatusBadge.vue';
import ReadinessChecklist from '@/Modules/MetaAds/Components/ReadinessChecklist.vue';
import TrendChart from '@/Modules/MetaAds/Components/TrendChart.vue';
import type { ObjectRow, ReadinessItem } from '@/Modules/MetaAds/Components/types';

const object: ObjectRow = {
    id: 'obj1', externalId: '1001', level: 'campaign', name: 'Eid Sale', status: 'ACTIVE', effectiveStatus: 'ACTIVE', objective: null,
    dailyBudget: 100000, lifetimeBudget: null, budgetRemaining: null, currency: 'BDT', startAt: null, endAt: null, accountId: 'a1',
    accountName: 'Shop Ads', parentName: null, insights: null, canPause: true, canResume: false, canEditBudget: true,
    budgetField: 'daily_budget', ceiling: 200000, blockedReason: null,
};

const ModalStub = defineComponent({ props: { open: Boolean, title: String, description: String }, setup: (_, { slots }) => () => h('div', [slots.default?.(), slots.footer?.()]) });

beforeEach(() => {
    inertia.submissions.length = 0;
    setLocale('en');
});

describe('Meta Ads formatting helpers', () => {
    it('maps Meta statuses to tones and keeps unknown ones neutral', () => {
        expect(statusTone('ACTIVE')).toBe('ok');
        expect(statusTone('paused')).toBe('neutral');
        expect(statusTone('DISAPPROVED')).toBe('danger');
        expect(statusTone('WITH_ISSUES')).toBe('warn');
        expect(statusTone('SOMETHING_NEW')).toBe('neutral');
        expect(statusTone(null)).toBe('neutral');
    });

    it('renders money text exactly, from minor units, without floats', () => {
        expect(minorToDecimal(150050, 'BDT')).toBe('1500.50');
        expect(minorToDecimal(5, 'USD')).toBe('0.05');
        expect(minorToDecimal(12, 'JPY')).toBe('12');
        expect(bpToPercent(1250)).toBe(12.5);
    });

    it('makes a fresh 32 character request id for every confirmation', () => {
        const ids = new Set(Array.from({ length: 20 }, () => newRequestId()));

        expect(ids.size).toBe(20);
        expect([...ids].every((id) => /^[0-9a-f]{32}$/.test(id))).toBe(true);
    });

    it('translates every known status in both languages', () => {
        for (const key of KNOWN_STATUSES) {
            expect((en as any).metaAds.status[key]).toBeTruthy();
            expect((bn as any).metaAds.status[key]).toBeTruthy();
        }
    });
});

describe('MetaStatusBadge', () => {
    it('shows the translated status, or the raw one when it is new', () => {
        expect(mount(MetaStatusBadge, { props: { status: 'PAUSED' } }).text()).toBe('Paused');
        expect(mount(MetaStatusBadge, { props: { status: 'BRAND_NEW' } }).text()).toBe('BRAND_NEW');
        expect(mount(MetaStatusBadge, { props: { status: null } }).text()).toBe('Unknown');
    });
});

describe('TrendChart', () => {
    const format = (value: number): string => String(value);

    it('breaks the line at a day with no figure and never draws it as zero', () => {
        const wrapper = mount(TrendChart, {
            props: {
                title: 'Spend', kind: 'line', formatValue: format,
                points: [{ date: '2026-10-01', value: 10 }, { date: '2026-10-02', value: 20 }, { date: '2026-10-03', value: null }, { date: '2026-10-04', value: 5 }, { date: '2026-10-05', value: 8 }],
            },
        });

        expect(wrapper.findAll('polyline')).toHaveLength(2);
        const labels = wrapper.findAll('rect[role="img"]').map((rect) => rect.attributes('aria-label'));
        expect(labels).toHaveLength(5);
        expect(labels[2]).toContain('Not reported');
    });

    it('says so when no day has a figure, and offers the numbers as a table otherwise', () => {
        const empty = mount(TrendChart, { props: { title: 'Leads', kind: 'bar', formatValue: format, points: [{ date: '2026-10-01', value: null }] } });
        expect(empty.text()).toContain('Meta has not reported any figures');
        expect(empty.find('svg').exists()).toBe(false);

        const full = mount(TrendChart, { props: { title: 'Leads', kind: 'bar', formatValue: format, points: [{ date: '2026-10-01', value: 3 }, { date: '2026-10-02', value: 0 }] } });
        expect(full.find('details table').exists()).toBe(true);
        expect(full.findAll('details tbody tr')).toHaveLength(2);
    });

    it('shows the day under focus', async () => {
        const wrapper = mount(TrendChart, { props: { title: 'Spend', kind: 'line', formatValue: (v: number) => `${v} BDT`, points: [{ date: '2026-10-01', value: 10 }, { date: '2026-10-02', value: 20 }] } });

        await wrapper.findAll('rect[role="img"]')[1]!.trigger('focus');

        expect(wrapper.find('[data-testid="readout"]').text()).toContain('20 BDT');
    });
});

describe('ChangeDialog', () => {
    const open = (mode: 'pause' | 'resume' | 'budget') => mount(ChangeDialog, { props: { open: true, object, mode, maxIncreasePercent: 100 }, global: { stubs: { Modal: ModalStub } } });

    it('shows what will happen before anything is sent, and sends a pause only on confirm', async () => {
        const wrapper = open('pause');

        expect(wrapper.find('[data-testid="before"]').text()).toBe('Active');
        expect(wrapper.find('[data-testid="after"]').text()).toBe('Paused');
        expect(wrapper.text()).toContain('Eid Sale');
        expect(inertia.submissions).toHaveLength(0);

        await wrapper.findAll('button').find((button) => button.text() === 'Pause')!.trigger('click');

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]!.url).toBe('/meta_ads.campaigns.status?object=obj1');
        expect(inertia.submissions[0]!.data).toMatchObject({ action: 'pause', expected: 'ACTIVE' });
        expect(String(inertia.submissions[0]!.data.request_id)).toMatch(/^[0-9a-f]{32}$/);
    });

    it('shows the current and the new budget, bounded by the ceiling, and sends the typed amount as text', async () => {
        const wrapper = open('budget');

        expect(wrapper.find('[data-testid="before"]').text()).toContain('1,000.00');
        expect(wrapper.text()).toContain('2,000.00');

        await wrapper.find('input').setValue('1500.50');
        expect(wrapper.find('[data-testid="after"]').text()).toBe('BDT 1500.50');

        await wrapper.findAll('button').find((button) => button.text() === 'Change budget')!.trigger('click');

        expect(inertia.submissions[0]!.url).toBe('/meta_ads.campaigns.budget?object=obj1');
        expect(inertia.submissions[0]!.data).toMatchObject({ amount: '1500.50', expected: '100000' });
    });

    it('does not offer to send a budget that is not an amount', async () => {
        const wrapper = open('budget');

        await wrapper.find('input').setValue('abc');

        const confirm = wrapper.findAll('button').find((button) => button.text() === 'Change budget')!;
        expect(confirm.attributes('disabled')).toBeDefined();
    });
});

describe('ReadinessChecklist', () => {
    it('lists the steps in order with their state in words', () => {
        const items: ReadinessItem[] = [
            { key: 'meta_app', state: 'blocked' }, { key: 'authorize', state: 'blocked' }, { key: 'accounts', state: 'blocked' },
            { key: 'first_sync', state: 'blocked' }, { key: 'social_page', state: 'unavailable' }, { key: 'management', state: 'optional' },
        ];
        const wrapper = mount(ReadinessChecklist, { props: { items } });

        const rows = wrapper.findAll('li');
        expect(rows).toHaveLength(6);
        expect(rows[0]!.text()).toContain('Only your platform operator can do this');
        expect(rows[0]!.attributes('data-state')).toBe('blocked');
        expect(rows[5]!.text()).toContain('Optional');
    });
});

describe('MetaAdsOverviewWidget', () => {
    it('shows not reported instead of zero and flags accounts needing attention', () => {
        const wrapper = mount(MetaAdsOverviewWidget, {
            props: { data: { currency: 'BDT', currencies: 2, spend: null, leads: null, trackedAccounts: 2, accountsNeedingAttention: 1 } },
        });

        expect(wrapper.text()).toContain('Not reported');
        expect(wrapper.text()).toContain('1 ad accounts need attention');
        expect(wrapper.text()).toContain('Main currency BDT');
    });

    it('invites setup when nothing is tracked', () => {
        const wrapper = mount(MetaAdsOverviewWidget, {
            props: { data: { currency: null, currencies: 0, spend: null, leads: null, trackedAccounts: 0, accountsNeedingAttention: 0 } },
        });

        expect(wrapper.find('[data-testid="widget-empty"]').exists()).toBe(true);
    });
});
