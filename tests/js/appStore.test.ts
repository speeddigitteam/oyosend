import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

interface Visit {
    method: string;
    url: string;
    data?: unknown;
}

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: [] as string[] },
            tenant: { id: 'tenant-1', name: 'Acme Trading', slug: 'acme', logoUrl: null },
        },
    },
    visits: [] as Visit[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ name: 'InertiaHeadStub', setup: () => () => null }),
    usePage: () => inertia.page,
    router: {
        on: () => () => {},
        post: (url: string, data: unknown) => inertia.visits.push({ method: 'post', url, data }),
        delete: (url: string) => inertia.visits.push({ method: 'delete', url }),
    },
    useForm: (initial: Record<string, string>) => {
        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {},
            processing: false,
            clearErrors: () => {},
            transform: () => form,
            get: (url: string) => inertia.visits.push({ method: 'get', url }),
        });

        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();

        return query === '' ? `/${name}` : `/${name}?${query}`;
    },
    isCurrentRoute: () => false,
}));

import AppActionPanel from '@/Core/Components/AppStore/AppActionPanel.vue';
import AppSettingsPanel from '@/Core/Components/AppStore/AppSettingsPanel.vue';
import type { AppSubscription, StoreApp } from '@/Core/Components/AppStore/types';
import { setLocale } from '@/Core/i18n';
import AppStoreIndex from '@/Core/Pages/AppStore/Index.vue';
import AppStoreShow from '@/Core/Pages/AppStore/Show.vue';

const mounted: VueWrapper[] = [];

function track<T extends VueWrapper>(wrapper: T): T {
    mounted.push(wrapper);

    return wrapper;
}

function app(overrides: Partial<StoreApp> = {}): StoreApp {
    return {
        key: 'crm',
        name: 'CRM',
        description: 'Track leads and customer conversations.',
        category: 'crm_sales',
        icon: 'target',
        featured: true,
        popular: false,
        version: '1.0.0',
        compatibility: '^1.0',
        features: ['Lead pipeline'],
        screenshots: [],
        offers: [
            {
                key: 'community',
                name: 'Community',
                type: 'free',
                currency: null,
                amount: null,
                trial_days: 0,
                public: true,
            },
        ],
        dependencies: ['core'],
        permissions: ['crm.leads.view'],
        releaseNotes: null,
        includedInPlan: false,
        installation: null,
        subscription: null,
        licence: null,
        settings: [],
        ...overrides,
    };
}

function subscription(overrides: Partial<AppSubscription> = {}): AppSubscription {
    return {
        id: 'subscription-item-1',
        offerKey: 'pro',
        billingType: 'monthly',
        status: 'active',
        currency: 'BDT',
        amount: 250000,
        quantity: 1,
        trialEndsAt: null,
        periodEnd: '2026-11-01T00:00:00Z',
        graceEndsAt: null,
        cancelAtPeriodEnd: false,
        endedAt: null,
        ...overrides,
    };
}

function buttonNamed(wrapper: VueWrapper, label: string): DOMWrapper<HTMLButtonElement> | undefined {
    return wrapper.findAll<HTMLButtonElement>('button').find((button) => button.text() === label);
}

function dialogButton(label: string): HTMLButtonElement {
    const button = [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find(
        (candidate) => candidate.textContent?.trim() === label,
    );

    if (!button) {
        throw new Error(`The open dialog has no button labelled [${label}].`);
    }

    return button;
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.page.props.auth.permissions = [];
    setLocale('en');
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

describe('App Store', () => {
    it('is honest when this build contains no optional apps', () => {
        const wrapper = track(
            mount(AppStoreIndex, {
                props: { apps: [], filters: { q: '', category: null, pricing: null }, categories: [], pricingTypes: [] },
            }),
        );

        expect(wrapper.text()).toContain('No optional apps are compiled into this build yet');
        expect(wrapper.text()).toContain('New first-party apps appear here when they are shipped and published.');
    });

    it('shows catalogue metadata and the installed state', () => {
        const crm = app({
            installation: {
                id: 'installation-1',
                status: 'active',
                installedAt: '2026-10-01T00:00:00Z',
                activatedAt: '2026-10-01T00:00:00Z',
                deactivatedAt: null,
                uninstalledAt: null,
                installedVersion: '1.0.0',
            },
        });
        const wrapper = track(
            mount(AppStoreIndex, {
                props: {
                    apps: [crm],
                    filters: { q: '', category: null, pricing: null },
                    categories: ['crm_sales'],
                    pricingTypes: ['free'],
                },
            }),
        );

        expect(wrapper.text()).toContain('CRM');
        expect(wrapper.text()).toContain('CRM & sales');
        expect(wrapper.text()).toContain('Featured');
        expect(wrapper.text()).toContain('Active');
        expect(wrapper.get('a[href="/core.apps.show?app=crm"]').text()).toContain('View details');
    });

    it('installs a free offer through the tenant route', async () => {
        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const wrapper = track(mount(AppActionPanel, { props: { app: app() } }));

        await buttonNamed(wrapper, 'Install free')?.trigger('click');

        expect(inertia.visits).toEqual([
            { method: 'post', url: '/core.apps.install?app=crm', data: { offer: 'community' } },
        ]);
    });

    it('confirms a paid purchase before creating its invoice', async () => {
        inertia.page.props.auth.permissions = ['core.apps.manage', 'core.billing.manage'];
        const crm = app({
            offers: [
                {
                    key: 'pro',
                    name: 'Pro',
                    type: 'monthly',
                    currency: 'BDT',
                    amount: 250000,
                    trial_days: 0,
                    public: true,
                },
            ],
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: crm } }));

        await buttonNamed(wrapper, 'Buy app')?.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('If payment is due, the app activates only after the invoice is verified as paid.');

        dialogButton('Continue').click();
        await flushPromises();

        expect(inertia.visits).toEqual([{ method: 'post', url: '/core.apps.purchase?app=crm', data: { offer: 'pro' } }]);
    });

    it('keeps controls out of reach for someone who can only view apps', () => {
        const active = app({
            installation: {
                id: 'installation-1',
                status: 'active',
                installedAt: '2026-10-01T00:00:00Z',
                activatedAt: '2026-10-01T00:00:00Z',
                deactivatedAt: null,
                uninstalledAt: null,
                installedVersion: '1.0.0',
            },
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: active } }));

        expect(wrapper.text()).toContain('Someone whose role manages apps can make changes here.');
        expect(buttonNamed(wrapper, 'Deactivate')).toBeUndefined();
        expect(buttonNamed(wrapper, 'Uninstall')).toBeUndefined();
    });

    it('offers a fresh licence instead of activation after a licence expires', () => {
        inertia.page.props.auth.permissions = ['core.apps.manage', 'core.billing.manage'];
        const inactive = app({
            installation: {
                id: 'installation-1',
                status: 'inactive',
                installedAt: '2026-09-01T00:00:00Z',
                activatedAt: '2026-09-01T00:00:00Z',
                deactivatedAt: '2026-10-01T00:00:00Z',
                uninstalledAt: null,
                installedVersion: '1.0.0',
            },
            licence: null,
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: inactive } }));

        expect(buttonNamed(wrapper, 'Activate')).toBeUndefined();
        expect(buttonNamed(wrapper, 'Install free')).toBeDefined();
        expect(buttonNamed(wrapper, 'Uninstall existing copy')).toBeDefined();
    });

    it('does not sell an app that the workspace plan already includes', () => {
        inertia.page.props.auth.permissions = ['core.apps.manage', 'core.billing.manage'];
        const wrapper = track(mount(AppActionPanel, { props: { app: app({ includedInPlan: true }) } }));

        expect(wrapper.text()).toContain('Included in your plan');
        expect(wrapper.text()).toContain('There is nothing extra to buy.');
        expect(buttonNamed(wrapper, 'Install free')).toBeUndefined();
        expect(buttonNamed(wrapper, 'Buy app')).toBeUndefined();
    });

    it('reinstalls an uninstalled app with a retained direct licence', async () => {
        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const retained = app({
            installation: {
                id: 'installation-1',
                status: 'uninstalled',
                installedAt: '2026-09-01T00:00:00Z',
                activatedAt: '2026-09-01T00:00:00Z',
                deactivatedAt: '2026-10-01T00:00:00Z',
                uninstalledAt: '2026-10-01T00:00:00Z',
                installedVersion: '1.0.0',
            },
            licence: {
                id: 'licence-1',
                source: 'purchase',
                status: 'active',
                validUntil: null,
            },
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: retained } }));

        expect(wrapper.text()).toContain('The existing licence is still valid.');
        expect(buttonNamed(wrapper, 'Buy app')).toBeUndefined();

        await buttonNamed(wrapper, 'Reinstall app')?.trigger('click');

        expect(inertia.visits).toEqual([{ method: 'post', url: '/core.apps.reinstall?app=crm', data: {} }]);
    });

    it('offers reinstall for a retained plan licence without showing purchase offers', () => {
        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const retained = app({
            includedInPlan: true,
            installation: {
                id: 'installation-1',
                status: 'uninstalled',
                installedAt: '2026-09-01T00:00:00Z',
                activatedAt: '2026-09-01T00:00:00Z',
                deactivatedAt: '2026-10-01T00:00:00Z',
                uninstalledAt: '2026-10-01T00:00:00Z',
                installedVersion: '1.0.0',
            },
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: retained } }));

        expect(buttonNamed(wrapper, 'Reinstall app')).toBeDefined();
        expect(buttonNamed(wrapper, 'Install free')).toBeUndefined();
        expect(buttonNamed(wrapper, 'Buy app')).toBeUndefined();
    });

    it('requires billing permission for paid uninstall, cancel, and resume controls', () => {
        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const installation: StoreApp['installation'] = {
            id: 'installation-1',
            status: 'active',
            installedAt: '2026-09-01T00:00:00Z',
            activatedAt: '2026-09-01T00:00:00Z',
            deactivatedAt: null,
            uninstalledAt: null,
            installedVersion: '1.0.0',
        };
        const paid = app({
            installation,
            subscription: subscription(),
            licence: { id: 'licence-1', source: 'purchase', status: 'active', validUntil: '2026-11-01T00:00:00Z' },
        });
        const active = track(mount(AppActionPanel, { props: { app: paid } }));

        expect(buttonNamed(active, 'Uninstall')?.attributes('disabled')).toBeDefined();
        expect(buttonNamed(active, 'Cancel renewal')?.attributes('disabled')).toBeDefined();
        expect(active.text()).toContain('Billing permission is required');

        const ending = track(
            mount(AppActionPanel, {
                props: { app: { ...paid, subscription: subscription({ cancelAtPeriodEnd: true }) } },
            }),
        );

        expect(buttonNamed(ending, 'Keep subscription')?.attributes('disabled')).toBeDefined();
    });

    it('updates an installed app when a newer manifest version is available', async () => {
        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const installed = app({
            version: '1.1.0',
            installation: {
                id: 'installation-1',
                status: 'active',
                installedAt: '2026-09-01T00:00:00Z',
                activatedAt: '2026-09-01T00:00:00Z',
                deactivatedAt: null,
                uninstalledAt: null,
                installedVersion: '1.0.0',
            },
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: installed } }));

        expect(wrapper.text()).toContain('Version 1.1.0 is available');
        expect(wrapper.text()).toContain('This workspace has version 1.0.0 installed.');

        await buttonNamed(wrapper, 'Update app')?.trigger('click');

        expect(inertia.visits).toEqual([{ method: 'post', url: '/core.apps.upgrade?app=crm', data: {} }]);
    });

    it('never reveals a saved secret in app settings', () => {
        const settings = [
            {
                key: 'api_token',
                label: 'API token',
                type: 'secret' as const,
                required: true,
                description: 'Token from the provider.',
                options: {},
                min: null,
                max: null,
                max_length: 255,
                hasValue: true,
            },
        ];
        const viewer = track(mount(AppSettingsPanel, { props: { appKey: 'crm', settings } }));

        expect(viewer.text()).toContain('Configured');
        expect(viewer.find('input').exists()).toBe(false);

        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const manager = track(mount(AppSettingsPanel, { props: { appKey: 'crm', settings } }));
        const secret = manager.get<HTMLInputElement>('input[type="password"]');

        expect(secret.element.value).toBe('');
        expect(secret.attributes('placeholder')).toBe('Leave blank to keep the saved secret');
        expect(manager.text()).toContain('Leave this blank to keep the saved secret.');
    });

    it('explains data retention before uninstalling', async () => {
        inertia.page.props.auth.permissions = ['core.apps.manage'];
        const active = app({
            installation: {
                id: 'installation-1',
                status: 'active',
                installedAt: '2026-10-01T00:00:00Z',
                activatedAt: '2026-10-01T00:00:00Z',
                deactivatedAt: null,
                uninstalledAt: null,
                installedVersion: '1.0.0',
            },
        });
        const wrapper = track(mount(AppActionPanel, { props: { app: active } }));

        await buttonNamed(wrapper, 'Uninstall')?.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Business data is retained.');

        dialogButton('Uninstall app').click();
        await flushPromises();

        expect(inertia.visits).toEqual([{ method: 'delete', url: '/core.apps.uninstall?app=crm' }]);
    });
});

describe('App Store catalogue translations', () => {
    const crmEn = {
        name: 'CRM & Sales',
        description: 'Turn shared contacts into qualified leads, managed opportunities, quotations and sales orders.',
        features: ['Lead qualification and assignment', 'Visual deal pipelines and activities'],
        releaseNotes: 'Initial CRM and sales release.',
    };

    function indexProps(apps: StoreApp[]) {
        return { apps, filters: { q: '', category: null, pricing: null }, categories: [], pricingTypes: [] };
    }

    it('keeps the server text in English, which matches the manifest', () => {
        const wrapper = track(mount(AppStoreIndex, { props: indexProps([app({ ...crmEn })]) }));

        expect(wrapper.text()).toContain(crmEn.name);
        expect(wrapper.text()).toContain(crmEn.description);
    });

    it('shows Bengali for the crm and projects apps', () => {
        setLocale('bn');
        const wrapper = track(
            mount(AppStoreIndex, {
                props: indexProps([
                    app({ ...crmEn }),
                    app({ key: 'projects', name: 'Project Management', description: 'Plan client work, collaborate on tasks, track time and control project costs.' }),
                ]),
            }),
        );

        expect(wrapper.text()).toContain('CRM ও সেলস');
        expect(wrapper.text()).toContain('প্রজেক্ট ম্যানেজমেন্ট');
        expect(wrapper.text()).not.toContain(crmEn.description);
        expect(wrapper.text()).not.toContain('Plan client work');
    });

    it('translates features and release notes on the detail page', () => {
        setLocale('bn');
        const wrapper = track(
            mount(AppStoreShow, {
                props: { app: app({ ...crmEn }), dependencyNames: {} },
                global: { stubs: { AppActionPanel: true, AppSettingsPanel: true } },
            }),
        );

        expect(wrapper.text()).toContain('লিড যাচাই ও দায়িত্ব বণ্টন');
        expect(wrapper.text()).toContain('ভিজ্যুয়াল ডিল পাইপলাইন ও কার্যকলাপ');
        expect(wrapper.text()).toContain('CRM ও সেলসের প্রথম প্রকাশ।');
        expect(wrapper.text()).not.toContain(crmEn.releaseNotes);
    });

    it('falls back to the server text for an app without a translation', () => {
        setLocale('bn');
        const unknown = app({
            key: 'acme_widgets',
            name: 'Acme Widgets',
            description: 'Widgets for everyone.',
            features: ['Widget builder'],
            releaseNotes: 'First widgets.',
        });
        const index = track(mount(AppStoreIndex, { props: indexProps([unknown]) }));
        const show = track(
            mount(AppStoreShow, {
                props: { app: unknown, dependencyNames: {} },
                global: { stubs: { AppActionPanel: true, AppSettingsPanel: true } },
            }),
        );

        expect(index.text()).toContain('Acme Widgets');
        expect(index.text()).toContain('Widgets for everyone.');
        expect(show.text()).toContain('Widget builder');
        expect(show.text()).toContain('First widgets.');
    });
});
