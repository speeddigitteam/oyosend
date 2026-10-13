import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

interface Visit { method: string; url: string; data?: unknown }

const inertia = vi.hoisted(() => ({
    page: { props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: [] as string[] }, tenant: { name: 'Acme' } } },
    visits: [] as Visit[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: {
        get: (url: string, data: unknown) => inertia.visits.push({ method: 'get', url, data }),
        post: (url: string, data: unknown) => inertia.visits.push({ method: 'post', url, data }),
        put: (url: string, data: unknown) => inertia.visits.push({ method: 'put', url, data }),
        patch: (url: string, data: unknown) => inertia.visits.push({ method: 'patch', url, data }),
        delete: (url: string) => inertia.visits.push({ method: 'delete', url }),
    },
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({
            ...initial,
            errors: {},
            processing: false,
            clearErrors: () => {},
            reset: () => {},
            post: (url: string, options?: unknown) => inertia.visits.push({ method: 'post', url, data: options }),
            put: (url: string, options?: unknown) => inertia.visits.push({ method: 'put', url, data: options }),
            patch: (url: string, options?: unknown) => inertia.visits.push({ method: 'patch', url, data: options }),
        });
        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
    isCurrentRoute: () => false,
}));

import DealBoard from '@/Modules/CRM/Components/DealBoard.vue';
import PipelineWidget from '@/Modules/CRM/Components/PipelineWidget.vue';
import DealFormDialog from '@/Modules/CRM/Components/DealFormDialog.vue';
import LeadFormDialog from '@/Modules/CRM/Components/LeadFormDialog.vue';
import QuoteFormDialog from '@/Modules/CRM/Components/QuoteFormDialog.vue';
import ActivityIndex from '@/Modules/CRM/Pages/Activities/Index.vue';
import LeadsIndex from '@/Modules/CRM/Pages/Leads/Index.vue';
import SettingsIndex from '@/Modules/CRM/Pages/Settings/Index.vue';
import type { Activity, Deal, Lead, Pipeline } from '@/Modules/CRM/Components/types';
import { omitTieredPriceOverrides } from '@/Modules/CRM/Components/quotePricing';
import { setLocale } from '@/Core/i18n';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const paginated = <T>(data: T[]) => ({ data, current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null, prev_page_url: null, next_page_url: null });

beforeEach(() => { inertia.visits.length = 0; inertia.page.props.auth.permissions = []; setLocale('en'); });
afterEach(() => { mounted.splice(0).forEach((wrapper) => wrapper.unmount()); document.body.innerHTML = ''; });

describe('CRM frontend', () => {
    const modalStub = defineComponent({ template: '<div><slot /><slot name="footer" /></div>' });

    it('keeps lead mutations hidden from a view-only role', () => {
        const wrapper = track(mount(LeadsIndex, { props: {
            leads: paginated<Lead>([{ id: 'lead-1', title: 'Renewal', status: 'new', score: 10, qualificationNotes: null, qualifiedAt: null, convertedAt: null, contact: null, company: { id: 'company-1', name: 'Acme' }, source: null, owner: null, editable: false, qualifiable: false, assignable: false, createdAt: '2026-10-10T00:00:00Z' }]),
            filters: { search: '', status: null, source: null, segment: null }, segments: [], sources: [], contacts: [], companies: [], owners: [], canCreate: false,
        } }));

        expect(wrapper.text()).toContain('Renewal');
        expect(wrapper.text()).toContain('You can view these records');
        expect(wrapper.findAll('button').some((button) => button.text().includes('Add lead'))).toBe(false);
    });

    it('completes a planned activity through the named route', async () => {
        const wrapper = track(mount(ActivityIndex, { props: {
            activities: paginated<Activity>([{ id: 'activity-1', type: 'follow_up', status: 'planned', priority: 'high', subject: 'Call buyer', description: null, startsAt: null, dueAt: '2026-10-11T08:00:00Z', remindAt: null, completedAt: null, lead: null, deal: null, contact: null, company: null, owner: null, editable: true, createdAt: '2026-10-10T00:00:00Z' }]),
            filters: { search: '', status: null, type: null, overdue: false, segment: null }, segments: [], leads: [], deals: [], contacts: [], companies: [], owners: [], canCreate: true,
        } }));

        await wrapper.get('button[aria-label="Mark complete"]').trigger('click');
        expect(inertia.visits).toEqual([{ method: 'post', url: '/crm.activities.complete?activity=activity-1', data: {} }]);
    });

    it('renders the dashboard pipeline widget with locale-aware Bengali copy', () => {
        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const wrapper = track(mount(PipelineWidget, { props: { data: { openDeals: 3, openAmount: 450000, weightedAmount: 225000, newLeads: 2, overdueActivities: 1, currency: 'BDT' } } }));

        expect(wrapper.text()).toContain('বিক্রয় পাইপলাইন');
        expect(wrapper.text()).toContain('চলমান ডিল');
        expect(wrapper.text()).toContain('৩');
        expect(wrapper.get('a').attributes('href')).toBe('/crm.deals.index');
        inertia.page.props.app.numerals = 'latn';
    });

    it('exposes creators for every supported CRM setting', () => {
        const wrapper = track(mount(SettingsIndex, { props: {
            pipelines: [{ id: 'pipeline-1', name: 'Sales', default: true, active: true, stages: [] }],
            sources: [],
            tags: [],
            customFields: [],
            segments: [],
        } }));
        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels).toEqual(expect.arrayContaining([
            'Add pipeline',
            'Add stage',
            'Add source',
            'Add tag',
            'Add custom field',
            'Add segment',
        ]));
    });

    it('lets the backend resolve price-list tiers without a client price override', () => {
        const payload = omitTieredPriceOverrides({
            price_list_id: 'price-list-1',
            lines: [
                { product_id: 'product-1', quantity_milli: 5000, unit_price_amount: 1200 },
                { product_id: '', name: 'Custom service', unit_price_amount: 900 },
            ],
        });

        expect(payload.lines[0]).not.toHaveProperty('unit_price_amount');
        expect(payload.lines[1]).toHaveProperty('unit_price_amount', 900);
    });

    it('does not offer ordinary lifecycle rollback controls for a qualified lead', () => {
        const lead: Lead = {
            id: 'lead-qualified', title: 'Qualified lead', status: 'qualified', score: 80,
            qualificationNotes: 'Ready', qualifiedAt: '2026-10-10T00:00:00Z', convertedAt: null,
            contact: null, company: null, source: null, owner: null,
            editable: true, qualifiable: true, assignable: true, createdAt: '2026-10-09T00:00:00Z',
        };
        const wrapper = track(mount(LeadFormDialog, {
            props: { open: true, lead },
            global: { stubs: { Modal: modalStub } },
        }));

        expect(wrapper.findAll('label').map((label) => label.text())).not.toContain('Status');
    });

    it('keeps pipeline and stage selectors out of ordinary deal edits', () => {
        const deal: Deal = {
            id: 'deal-1', title: 'Renewal', status: 'open', amount: 50_000, currency: 'BDT',
            expectedCloseDate: null, wonAt: null, lostAt: null, lostReason: null, description: null,
            pipeline: { id: 'pipeline-1', name: 'Sales' }, stage: { id: 'stage-1', name: 'Open' },
            lead: null, contact: null, company: null, owner: null,
            editable: true, assignable: true, createdAt: '2026-10-10T00:00:00Z',
        };
        const pipelines: Pipeline[] = [{
            id: 'pipeline-1', name: 'Sales', stages: [{ id: 'stage-1', name: 'Open', probability: 20, position: 0, won: false, lost: false }],
        }];
        const wrapper = track(mount(DealFormDialog, {
            props: { open: true, deal, pipelines },
            global: { stubs: { Modal: modalStub } },
        }));
        const labels = wrapper.findAll('label').map((label) => label.text());

        expect(labels).not.toContain('Pipeline');
        expect(labels).not.toContain('Stage');
    });

    it('links a quotation to a deal and applies its canonical customer and currency', async () => {
        const wrapper = track(mount(QuoteFormDialog, {
            props: {
                open: true,
                options: {
                    canSelectDeals: true,
                    deals: [{ id: 'deal-1', name: 'Annual renewal', contactId: 'contact-1', companyId: 'company-1', currency: 'USD' }],
                    products: [],
                    priceLists: [],
                    contacts: [{ id: 'contact-1', name: 'Nadia Rahman' }],
                    companies: [{ id: 'company-1', name: 'Northwind' }],
                },
            },
            global: { stubs: { Modal: modalStub } },
        }));
        const control = (label: string) => {
            const fieldLabel = wrapper.findAll('label').find((item) => item.text() === label);
            expect(fieldLabel).toBeDefined();
            return wrapper.get(`[id="${fieldLabel?.attributes('for')}"]`);
        };

        await control('Deal').setValue('deal-1');

        expect((control('Contact').element as HTMLSelectElement).value).toBe('contact-1');
        expect((control('Company').element as HTMLSelectElement).value).toBe('company-1');
        expect((control('Currency').element as HTMLInputElement).value).toBe('USD');
    });
});

describe('CRM deal board', () => {
    const modalStub = defineComponent({ template: '<div><slot /><slot name="footer" /></div>' });
    const boardPipeline: Pipeline = { id: 'pipeline-1', name: 'Sales', stages: [
        { id: 'stage-new', name: 'New', probability: 10, position: 1, won: false, lost: false },
        { id: 'stage-proposal', name: 'Proposal', probability: 60, position: 2, won: false, lost: false },
        { id: 'stage-won', name: 'Won', probability: 100, position: 3, won: true, lost: false },
        { id: 'stage-lost', name: 'Lost', probability: 0, position: 4, won: false, lost: true },
    ] };
    const boardDeal = (overrides: Partial<Deal> = {}): Deal => ({
        id: 'deal-1', title: 'Fleet renewal', status: 'open', amount: 2500000, currency: 'BDT', expectedCloseDate: null, wonAt: null, lostAt: null, lostReason: null, description: null,
        pipeline: { id: 'pipeline-1', name: 'Sales' }, stage: { id: 'stage-new', name: 'New' }, lead: null, contact: null, company: { id: 'company-1', name: 'Acme' }, owner: null,
        editable: true, assignable: true, createdAt: '2026-10-10T00:00:00Z', ...overrides,
    });
    const mountBoard = (deals: Deal[]) => track(mount(DealBoard, { props: { pipelines: [boardPipeline], boardDeals: deals, selectedPipelineId: 'pipeline-1' }, global: { stubs: { Modal: modalStub } } }));
    const dropOn = async (wrapper: VueWrapper, stageId: string): Promise<void> => {
        const column = wrapper.get(`section[aria-labelledby="deal-col-${stageId}"]`);
        await column.trigger('dragover');
        await column.trigger('drop');
    };

    it('moves a dragged deal to an open stage through the stage route', async () => {
        const wrapper = mountBoard([boardDeal()]);

        expect(wrapper.findAll('h3').map((item) => item.text())).toEqual(['New', 'Proposal', 'Won', 'Lost']);
        await wrapper.get('a[draggable="true"]').trigger('dragstart');
        await dropOn(wrapper, 'stage-proposal');

        expect(inertia.visits).toEqual([{ method: 'post', url: '/crm.deals.stage?deal=deal-1', data: { stage_id: 'stage-proposal', note: null } }]);
    });

    it('asks before a drop closes a deal and sends the note as the lost reason', async () => {
        const wrapper = mountBoard([boardDeal()]);

        await wrapper.get('a[draggable="true"]').trigger('dragstart');
        await dropOn(wrapper, 'stage-lost');

        expect(inertia.visits).toEqual([]);
        expect(wrapper.text()).toContain('Moving this deal to Lost closes it.');
        expect(wrapper.text()).toContain('this note is saved as the reason');
        await wrapper.get('textarea').setValue('Chose a cheaper supplier');
        await wrapper.get('form#crm-board-close-form').trigger('submit');

        expect(inertia.visits).toEqual([{ method: 'post', url: '/crm.deals.stage?deal=deal-1', data: { stage_id: 'stage-lost', note: 'Chose a cheaper supplier' } }]);
    });

    it('does not let a view-only deal be dragged', async () => {
        const wrapper = mountBoard([boardDeal({ editable: false })]);
        const card = wrapper.get('a[href="/crm.deals.show?deal=deal-1"]');

        expect(card.attributes('draggable')).toBe('false');
        await card.trigger('dragstart');
        await dropOn(wrapper, 'stage-proposal');

        expect(inertia.visits).toEqual([]);
    });
});
