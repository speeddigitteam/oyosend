import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.leadads.view', 'social.leadads.manage'] as string[] },
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

import en from '@/Core/i18n/locales/en';
import bn from '@/Core/i18n/locales/bn';
import LeadLogDialog from '@/Modules/Social/Components/LeadLogDialog.vue';
import LeadMappingDialog from '@/Modules/Social/Components/LeadMappingDialog.vue';
import LeadPageCard from '@/Modules/Social/Components/LeadPageCard.vue';
import SocialOverviewWidget from '@/Modules/Social/Components/SocialOverviewWidget.vue';
import {
    KNOWN_REASONS,
    type LeadAdsAvailability,
    type LeadFormRow,
    type LeadLogRow,
    type LeadPageRow,
    type LeadRow,
} from '@/Modules/Social/Components/leadAds';
import LeadAdsIndex from '@/Modules/Social/Pages/LeadAds/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub, SocialFormDialog: defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /></div>' }) };

const availability: LeadAdsAvailability = {
    problem: null, crmAvailable: true, requiredScopes: ['leads_retrieval', 'pages_manage_ads'],
    webhookUrl: 'https://acme.example.test/social/webhooks/facebook/leads', backfillDays: 3, backfillMaxDays: 14,
};

function form(overrides: Partial<LeadFormRow> = {}): LeadFormRow {
    return {
        id: 'form-1', externalId: '777', name: 'Spring offer', status: 'ACTIVE', isEnabled: true, fetchedAt: null, lastLeadAt: null,
        questions: [
            { key: 'full_name', label: 'Full name', type: 'FULL_NAME' },
            { key: 'email', label: 'Email', type: 'EMAIL' },
            { key: 'budget', label: 'Budget', type: 'CUSTOM' },
        ],
        mapping: {
            full_name: { target: 'name', answerKey: null },
            email: { target: 'email', answerKey: null },
            budget: { target: 'answer', answerKey: null },
        },
        preview: { name: 'Sample Person', email: 'sample@example.com', phone: null, company: null, answers: { budget: 'Sample answer' }, consentEvidence: false, problem: null, ignored: [] },
        warnings: [],
        counts: { received: 4, processed: 2, failed: 1, waiting: 1, skipped: 0 },
        ...overrides,
    };
}

function page(overrides: Partial<LeadPageRow> = {}): LeadPageRow {
    return {
        account: { id: 'acc-1', name: 'Brand Page', avatarUrl: null, status: 'connected', statusReason: null },
        state: 'subscribed', pageId: '1000', subscribedAt: null, missingScopes: [], needsReconnect: false, permissionsCheckedAt: null,
        lastError: null, lastErrorCode: null, formsSyncedAt: null, formsSyncError: null, lastBackfillAt: null,
        counts: { received: 4, processed: 2, failed: 1, waiting: 1, skipped: 0 },
        history: [{ date: '2026-10-12', received: 4, processed: 2, failed: 1 }],
        forms: [form()],
        ...overrides,
    };
}

function lead(overrides: Partial<LeadRow> = {}): LeadRow {
    return {
        id: 'lead-1', status: 'failed', reasonCode: 'missing_contact', reason: null, accountId: 'acc-1', accountName: 'Brand Page', formId: 'form-1',
        formName: 'Spring offer', externalFormId: '777', adId: 'AD1', campaignRef: 'CMP1', origin: 'webhook', attempts: 1,
        receivedAt: '2026-10-12T04:00:00+00:00', processedAt: null, nextAttemptAt: null, crmContactId: null, crmLeadId: null, canRetry: true,
        ...overrides,
    };
}

type IndexProps = InstanceType<typeof LeadAdsIndex>['$props'];

function indexProps(overrides: Partial<IndexProps> = {}): IndexProps {
    return {
        availability, pages: [page()], filters: { status: null, account_id: null, form_id: null },
        statuses: ['received', 'captured', 'duplicate', 'failed', 'waiting_for_crm', 'waiting_for_account', 'skipped'],
        leads: { data: [lead()], current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null },
        selected: null, canManage: true, targets: ['name', 'email', 'phone', 'company', 'consent', 'answer', 'ignore'],
        ...overrides,
    };
}

beforeEach(() => {
    Object.values(inertia.router).forEach((fn) => fn.mockReset());
    inertia.page.props.auth.permissions = ['social.leadads.view', 'social.leadads.manage'];
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('lead forms page', () => {
    it('shows the Page state, counts, forms and the outcome list', () => {
        const wrapper = track(mount(LeadAdsIndex, { props: indexProps(), global: { stubs } }));

        expect(wrapper.get('[data-testid="page-state"]').text()).toBe('Receiving leads');
        expect(wrapper.get('[data-testid="page-counts"]').text()).toContain('Received');
        expect(wrapper.get('[data-testid="forms"]').text()).toContain('Spring offer');
        expect(wrapper.text()).toContain('Failed');
        expect(wrapper.find('[data-testid="crm-missing"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="webhook-url"]').text()).toBe(availability.webhookUrl);
    });

    it('says plainly when the CRM is not available and when the operator has not enabled lead ads', () => {
        const wrapper = track(mount(LeadAdsIndex, {
            props: indexProps({ availability: { ...availability, crmAvailable: false, problem: 'not_enabled' }, pages: [page({ state: 'off', forms: [] })] }),
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="crm-missing"]').text()).toContain('CRM is not available');
        expect(wrapper.get('[data-testid="operator-problem"]').text()).toContain('not switched on');
        expect(wrapper.get('[data-testid="enable"]').attributes('disabled')).toBeDefined();
    });

    it('offers connecting a Page when there is none', () => {
        const wrapper = track(mount(LeadAdsIndex, { props: indexProps({ pages: [], leads: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null } }), global: { stubs } }));

        expect(wrapper.get('[data-testid="no-pages"]').text()).toContain('No Facebook Page connected');
    });

    it('retries a lead on the server', async () => {
        const wrapper = track(mount(LeadAdsIndex, { props: indexProps(), global: { stubs } }));

        await wrapper.get('[data-testid="retry-lead-1"]').trigger('click');

        expect(inertia.router.post).toHaveBeenCalledWith('/social.leadads.leads.retry?lead=lead-1', {}, expect.anything());
    });

    it('hides every change control from a viewer', () => {
        const wrapper = track(mount(LeadAdsIndex, { props: indexProps({ canManage: false }), global: { stubs } }));

        expect(wrapper.find('[data-testid="retry-lead-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="backfill"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="switch-form-1"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="webhook-url"]').exists()).toBe(false);
    });
});

describe('Page card', () => {
    const card = (row: LeadPageRow, canManage = true) => track(mount(LeadPageCard, { props: { page: row, availability, canManage }, global: { stubs } }));

    it('explains missing permissions and an expired connection', () => {
        const wrapper = card(page({ state: 'failed', missingScopes: ['leads_retrieval'], account: { ...page().account, status: 'needs_reauthorization' } }));

        expect(wrapper.get('[data-testid="page-missing-scopes"]').text()).toContain('leads_retrieval');
        expect(wrapper.get('[data-testid="page-reconnect"]').text()).toContain('leads cannot be fetched');
    });

    it('asks to connect again when the Page predates the lead permissions', () => {
        const wrapper = card(page({ state: 'off', needsReconnect: true, forms: [] }));

        expect(wrapper.get('[data-testid="page-needs-scopes"]').text()).toContain('leads_retrieval');
    });

    it('switches a form on or off through the server', async () => {
        const wrapper = card(page());

        await wrapper.get('[data-testid="switch-form-1"]').trigger('click');

        expect(wrapper.get('[data-testid="switch-form-1"]').attributes('aria-checked')).toBe('true');
        expect(inertia.router.post).toHaveBeenCalledWith('/social.leadads.forms.switch?form=form-1', { enabled: false }, expect.anything());
    });

    it('fetches recent leads inside the operator window', async () => {
        const wrapper = card(page());

        await wrapper.get('[data-testid="backfill"] button').trigger('click');

        expect(inertia.router.post).toHaveBeenCalledWith('/social.leadads.backfill?account=acc-1', { days: 3 }, expect.anything());
        const options = wrapper.findAll('[data-testid="backfill"] option').map((option) => option.attributes('value'));
        expect(Math.max(...options.map(Number))).toBeLessThanOrEqual(availability.backfillMaxDays);
    });
});

describe('mapping dialog', () => {
    it('lets the person re-target a question and saves the choice', async () => {
        const wrapper = track(mount(LeadMappingDialog, {
            props: { open: true, form: form(), targets: ['name', 'email', 'phone', 'company', 'consent', 'answer', 'ignore'] },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="mapping-preview"]').text()).toContain('sample@example.com');

        // A question kept as an answer offers an answer name; re-targeted to Company it does not.
        expect(wrapper.text()).toContain('Answer name');
        await wrapper.get('[data-testid="target-budget"]').setValue('company');
        expect((wrapper.get('[data-testid="target-budget"]').element as HTMLSelectElement).value).toBe('company');
        expect(wrapper.text()).not.toContain('Answer name');
    });
});

describe('processing log', () => {
    it('lists each step with a translated reason and the CRM ids as text', () => {
        const log: LeadLogRow[] = [
            { id: 'l1', step: 'received', code: 'webhook', message: 'Delivered by the Facebook webhook.', at: '2026-10-12T04:00:00+00:00' },
            { id: 'l2', step: 'waiting', code: 'crm_unavailable', message: 'x', at: '2026-10-12T04:00:02+00:00' },
        ];
        const wrapper = track(mount(LeadLogDialog, {
            props: { open: true, lead: lead({ status: 'captured', reasonCode: null, crmLeadId: 'crm-lead-1', crmContactId: 'crm-contact-1', canRetry: false }), log, canManage: true },
            global: { stubs },
        }));

        const steps = wrapper.get('[data-testid="lead-steps"]').text();
        expect(steps).toContain('Received');
        expect(steps).toContain('The CRM is not available in this workspace.');
        expect(wrapper.get('[data-testid="lead-crm"]').text()).toContain('crm-lead-1');
        expect(wrapper.find('[data-testid="log-retry"]').exists()).toBe(false);
    });
});

describe('dashboard widget', () => {
    const data = { scheduled: 1, awaitingApproval: 0, failed: 0, accountsNeedingAttention: 0, nextScheduledFor: null };

    it('shows the lead-ad counts only when there are any', () => {
        const without = track(mount(SocialOverviewWidget, { props: { data }, global: { stubs } }));
        expect(without.find('[data-testid="social-lead-ads"]').exists()).toBe(false);

        const wrapper = track(mount(SocialOverviewWidget, {
            props: { data: { ...data, leadAdsEnabled: true, leadAdsReceived: 7, leadAdsProcessed: 5, leadAdsFailed: 1, leadAdsWaiting: 1 } },
            global: { stubs },
        }));
        expect(wrapper.get('[data-testid="social-lead-ads"]').text()).toContain('7');
    });
});

describe('translations', () => {
    it('has a Bengali sentence for every reason code and the same keys as English', () => {
        for (const code of KNOWN_REASONS) {
            expect((bn.leadAds.reasons as Record<string, string>)[code]).toBeTruthy();
            expect((en.leadAds.reasons as Record<string, string>)[code]).toBeTruthy();
        }

        const keys = (value: unknown, prefix = ''): string[] => Object.entries(value as Record<string, unknown>)
            .flatMap(([key, child]) => (typeof child === 'object' && child !== null ? keys(child, `${prefix}${key}.`) : [`${prefix}${key}`]));

        expect(keys(bn.leadAds).sort()).toEqual(keys(en.leadAds).sort());
    });
});
