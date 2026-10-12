import { mount, type VueWrapper } from '@vue/test-utils';
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
            auth: { permissions: ['social.posts.view'] as string[] },
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
import SocialOverviewWidget from '@/Modules/Social/Components/SocialOverviewWidget.vue';
import {
    dayKey,
    displayTag,
    isLocalDateTime,
    isReported,
    kbToMb,
    lengthState,
    monthGrid,
    safeUrl,
    shiftMonth,
    sizeParts,
    splitTags,
} from '@/Modules/Social/Components/socialFormat';
import type { AccountOption, AccountRow, AnalyticsReport, FormOptions, PlatformInfo, PostDetail, PostSummary } from '@/Modules/Social/Components/types';
import AccountsIndex from '@/Modules/Social/Pages/Accounts/Index.vue';
import AnalyticsIndex from '@/Modules/Social/Pages/Analytics/Index.vue';
import CalendarIndex from '@/Modules/Social/Pages/Calendar/Index.vue';
import PostsForm from '@/Modules/Social/Pages/Posts/Form.vue';
import PostsShow from '@/Modules/Social/Pages/Posts/Show.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const facebook: PlatformInfo = {
    key: 'facebook', label: 'Facebook Pages', available: true, unavailableCode: null, reason: null,
    capabilities: { publish: true, schedule: true, analytics: true, comments: false, images: true, video: false, maxText: 63206, maxMedia: 1 },
};
const instagram: PlatformInfo = {
    key: 'instagram', label: 'Instagram', available: false, unavailableCode: 'not_implemented',
    reason: 'Publishing to Instagram needs a public media address and app review, which this build does not provide.',
    capabilities: { publish: false, schedule: false, analytics: false, comments: false, images: false, video: false, maxText: 0, maxMedia: 0 },
};
const linkedin: PlatformInfo = {
    key: 'linkedin', label: 'LinkedIn', available: true, unavailableCode: null, reason: null,
    capabilities: { publish: true, schedule: true, analytics: false, comments: false, images: false, video: false, maxText: 3000, maxMedia: 0 },
};

function account(overrides: Partial<AccountRow> = {}): AccountRow {
    return {
        id: 'acc-1', platform: 'facebook', platformLabel: 'Facebook Pages', name: 'Acme Bakery', handle: null, avatarUrl: null, type: 'page',
        status: 'connected', statusReason: null, needsReauthorization: false, platformAvailable: true, capabilities: facebook.capabilities,
        connectedAt: '2026-10-01T04:00:00+00:00', lastUsedAt: null, tokenExpiresAt: null, canRefresh: true, ...overrides,
    };
}

function summary(overrides: Partial<PostSummary> = {}): PostSummary {
    return {
        id: 'post-1', title: 'Eid sale', excerpt: 'Everything is 20 percent off', status: 'scheduled',
        scheduledFor: '2026-10-20T04:30:00+00:00', publishedAt: null, at: '2026-10-20T04:30:00+00:00',
        campaign: null, category: null, targets: [], createdAt: '2026-10-01T04:00:00+00:00', ...overrides,
    };
}

function detail(overrides: Partial<PostDetail> = {}): PostDetail {
    const base = summary();

    return {
        ...base,
        status: 'draft',
        targets: [],
        body: 'Everything is 20 percent off this week.',
        linkUrl: null,
        hashtags: [],
        hashtagSetId: null,
        scheduledForLocal: null,
        owner: { id: 'm-1', name: 'Nadia Karim' },
        submittedAt: null,
        approvedAt: null,
        rejectedAt: null,
        rejectionReason: null,
        media: [],
        comments: [],
        previews: [],
        approval: { required: false, submittedBy: null, approvedBy: null, mineToApprove: false },
        abilities: {
            edit: false, delete: false, submit: false, approve: false, reject: false, schedule: false,
            reschedule: false, unschedule: false, retry: false, comment: false,
        },
        now: '2026-10-11T04:00:00+00:00',
        ...overrides,
    };
}

function formOptions(overrides: Partial<FormOptions> = {}): FormOptions {
    const option = (id: string, platform: PlatformInfo, name: string, usable: boolean, status: AccountOption['status'] = 'connected'): AccountOption => ({
        id, platform: platform.key, platformLabel: platform.label, name, handle: null, status, usable, capabilities: platform.capabilities,
    });

    return {
        accounts: [option('acc-1', facebook, 'Acme Bakery', true), option('acc-2', linkedin, 'Nadia Karim', false, 'needs_reauthorization')],
        campaigns: [{ id: 'camp-1', name: 'Eid 2026' }],
        categories: [{ id: 'cat-1', name: 'Offers' }],
        hashtagSets: [{ id: 'set-1', name: 'Bakery', tags: ['#bakery', '#eid'] }],
        media: [],
        approvalRequired: false,
        timezone: 'Asia/Dhaka',
        platforms: [facebook, linkedin, instagram],
        limits: { maxMedia: 10, maxBody: 5000 },
        ...overrides,
    };
}

function report(overrides: Partial<AnalyticsReport['engagement']> = {}, platformsWithAnalytics: string[] = ['facebook']): AnalyticsReport {
    return {
        from: '2026-09-12T00:00:00+00:00',
        to: '2026-10-11T00:00:00+00:00',
        publishing: { published: 12, failed: 1, pending: 3, byPlatform: [{ platform: 'facebook', label: 'Facebook Pages', published: 12, failed: 1 }] },
        engagement: {
            reportedPosts: 0,
            capturedAt: null,
            totals: { impressions: null, reach: null, likes: null, comments: null, shares: null, clicks: null },
            byPlatform: [],
            topPosts: [],
            ...overrides,
        },
        platformsWithAnalytics,
    };
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['social.posts.view'];
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('social helpers', () => {
    it('finds the day of an instant in the workspace timezone', () => {
        expect(dayKey('2026-10-20T20:30:00+00:00', 'Asia/Dhaka')).toBe('2026-10-21');
        expect(dayKey('2026-10-20T20:30:00+00:00', 'UTC')).toBe('2026-10-20');
        expect(dayKey('not a date', 'UTC')).toBe('');
    });

    it('lays a month out in whole weeks starting on Sunday', () => {
        const weeks = monthGrid('2026-10');

        expect(weeks.every((week) => week.length === 7)).toBe(true);
        expect(weeks[0]![0]!.date).toBe('2026-09-27');
        expect(weeks[0]![0]!.inMonth).toBe(false);
        expect(weeks.flat().filter((day) => day.inMonth)).toHaveLength(31);
        expect(weeks.at(-1)!.at(-1)!.date >= '2026-10-31').toBe(true);
        expect(monthGrid('nonsense')).toEqual([]);
    });

    it('moves between months across a year', () => {
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
        expect(shiftMonth('2026-10', 0)).toBe('2026-10');
    });

    it('only links to http and https addresses', () => {
        expect(safeUrl('https://example.com/post/1')).toBe('https://example.com/post/1');
        expect(safeUrl('javascript:alert(1)')).toBeNull();
        expect(safeUrl('data:text/html,hi')).toBeNull();
        expect(safeUrl('/relative')).toBeNull();
        expect(safeUrl(null)).toBeNull();
    });

    it('splits and displays hashtags', () => {
        expect(splitTags('#one two,#three;  four')).toEqual(['#one', 'two', '#three', 'four']);
        expect(displayTag('one')).toBe('#one');
        expect(displayTag('##one')).toBe('#one');
    });

    it('judges a length against a platform limit', () => {
        expect(lengthState(10, 280)).toBe('ok');
        expect(lengthState(260, 280)).toBe('near');
        expect(lengthState(281, 280)).toBe('over');
        expect(lengthState(5000, 0)).toBe('none');
    });

    it('accepts only the shape the server expects for a planned time', () => {
        expect(isLocalDateTime('2026-10-20T14:30')).toBe(true);
        expect(isLocalDateTime('2026-10-20 14:30')).toBe(false);
        expect(isLocalDateTime('')).toBe(false);
    });

    it('never counts a missing figure as zero', () => {
        expect(isReported(0)).toBe(true);
        expect(isReported(null)).toBe(false);
        expect(isReported(undefined)).toBe(false);
    });

    it('converts sizes', () => {
        expect(sizeParts(500)).toEqual({ unit: 'kb', value: 1 });
        expect(sizeParts(2048)).toEqual({ unit: 'kb', value: 2 });
        expect(sizeParts(1_572_864)).toEqual({ unit: 'mb', value: 1.5 });
        expect(kbToMb(51200)).toBe(50);
    });
});

describe('content calendar', () => {
    const calendarProps = {
        month: '2026-10', from: '2026-10-01', to: '2026-10-31', timezone: 'Asia/Dhaka',
        filters: { campaign_id: null, account_id: null }, campaigns: [], accounts: [], canCreate: true, canSchedule: true,
    };

    it('puts a post on the day it goes out in workspace time', () => {
        // 20:30 UTC is already the 21st in Dhaka.
        const wrapper = track(mount(CalendarIndex, {
            props: { ...calendarProps, posts: [summary({ at: '2026-10-20T20:30:00+00:00', scheduledFor: '2026-10-20T20:30:00+00:00' })] },
        }));

        expect(wrapper.get('[data-testid="calendar-title"]').text()).toBe('October 2026');
        const cells = wrapper.findAll('[data-testid="calendar-grid"] > div:not(:first-child) > div');
        const holder = cells.find((cell) => cell.text().includes('Eid sale'));
        expect(holder).toBeTruthy();
        expect(holder!.text().startsWith('21')).toBe(true);
        expect(wrapper.find('a[href="/social.posts.show?post=post-1"]').exists()).toBe(true);
    });

    it('moves to the next month and keeps the filters', () => {
        const wrapper = track(mount(CalendarIndex, { props: { ...calendarProps, posts: [] } }));
        const next = wrapper.findAll('button').find((button) => button.text() === 'Next month');
        next!.trigger('click');

        expect(inertia.router.get).toHaveBeenCalledWith('/social.calendar.index', { month: '2026-11', campaign_id: undefined, account_id: undefined }, expect.anything());
    });

    it('offers a new post only to someone who can create one', () => {
        const allowed = track(mount(CalendarIndex, { props: { ...calendarProps, posts: [] } }));
        expect(allowed.text()).toContain('New post');

        const denied = track(mount(CalendarIndex, { props: { ...calendarProps, canCreate: false, posts: [] } }));
        expect(denied.find('a[href="/social.posts.create"]').exists()).toBe(false);
    });
});

describe('connected accounts', () => {
    it('shows why a platform is unavailable and offers no connect control for it', () => {
        const wrapper = track(mount(AccountsIndex, {
            props: {
                accounts: [], platforms: [facebook, instagram], limit: { limit: 3, used: 0 }, canManage: true,
                redirectUris: [{ platform: 'facebook', uri: 'https://acme.example.test/social/accounts/callback/facebook' }],
            },
            global: { stubs },
        }));

        const blocked = wrapper.get('[data-testid="platform-instagram"]');
        expect(blocked.get('[data-testid="reason"]').text()).toContain('app review');
        expect(blocked.text()).toContain('Not available yet');
        expect(blocked.findAll('button')).toHaveLength(0);

        const open = wrapper.get('[data-testid="platform-facebook"]');
        expect(open.text()).toContain('Connect Facebook Pages');
        expect(open.text()).toContain('Video publishing is not available.');
        expect(wrapper.text()).toContain('/social/accounts/callback/facebook');
    });

    it('starts the connection on the server and never handles a token', () => {
        const wrapper = track(mount(AccountsIndex, {
            props: { accounts: [], platforms: [facebook], limit: { limit: null, used: 0 }, canManage: true, redirectUris: [] },
            global: { stubs },
        }));
        wrapper.get('[data-testid="platform-facebook"] button').trigger('click');

        expect(inertia.router.post).toHaveBeenCalledWith('/social.accounts.connect?platform=facebook', {}, expect.anything());
    });

    it('asks for a reconnection and disables connecting when the plan has no free slot', () => {
        const wrapper = track(mount(AccountsIndex, {
            props: {
                accounts: [account({ status: 'needs_reauthorization', needsReauthorization: true, statusReason: 'The platform rejected the saved access.' })],
                platforms: [facebook], limit: { limit: 1, used: 1 }, canManage: true, redirectUris: [],
            },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="attention"]').text()).toContain('1 accounts must be connected again');
        expect(wrapper.get('[data-testid="accounts"]').text()).toContain('Connect again');
        expect(wrapper.find('[data-testid="seats-full"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="platform-facebook"] button').attributes('disabled')).toBeDefined();
    });

    it('hides every management control from a viewer', () => {
        const wrapper = track(mount(AccountsIndex, {
            props: { accounts: [account()], platforms: [facebook], limit: { limit: 5, used: 1 }, canManage: false, redirectUris: [] },
            global: { stubs },
        }));

        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Redirect addresses');
    });
});

describe('reports', () => {
    it('shows not reported, never zero, for a figure the platform did not return', () => {
        const wrapper = track(mount(AnalyticsIndex, {
            props: {
                report: report({
                    reportedPosts: 2,
                    capturedAt: '2026-10-11T02:00:00+00:00',
                    totals: { impressions: null, reach: null, likes: 40, comments: 0, shares: 3, clicks: null },
                }),
                range: { from: '2026-09-12', to: '2026-10-11', timezone: 'Asia/Dhaka' },
            },
        }));

        const totals = wrapper.get('[data-testid="totals"]').text();
        expect(totals.match(/Not reported/g)).toHaveLength(3);
        expect(totals).toContain('40');
        // A reported zero is still a zero.
        expect(wrapper.get('[data-testid="totals"]').findAll('.figure').map((node) => node.text())).toContain('0');
        expect(wrapper.get('[data-testid="captured"]').text()).toContain('2 published posts');
    });

    it('explains an empty report instead of printing zeros', () => {
        const none = track(mount(AnalyticsIndex, {
            props: { report: report({}, []), range: { from: '2026-09-12', to: '2026-10-11', timezone: 'Asia/Dhaka' } },
        }));
        expect(none.find('[data-testid="no-platform"]').exists()).toBe(true);
        expect(none.find('[data-testid="totals"]').exists()).toBe(false);

        const waiting = track(mount(AnalyticsIndex, {
            props: { report: report(), range: { from: '2026-09-12', to: '2026-10-11', timezone: 'Asia/Dhaka' } },
        }));
        expect(waiting.find('[data-testid="no-figures"]').exists()).toBe(true);
        expect(waiting.get('[data-testid="no-figures"]').text()).toContain('Facebook Pages');
        expect(waiting.find('[data-testid="totals"]').exists()).toBe(false);
        // Publishing counts come from this application's own records and are always shown.
        expect(waiting.get('[data-testid="publishing"]').text()).toContain('12');
    });
});

describe('post detail', () => {
    it('shows only the actions the server allows', () => {
        inertia.page.props.auth.permissions = ['social.posts.view'];
        const viewer = track(mount(PostsShow, { props: { post: detail() }, global: { stubs } }));
        expect(viewer.findAll('button')).toHaveLength(0);
        expect(viewer.text()).not.toContain('Edit');

        const editor = track(mount(PostsShow, {
            props: { post: detail({ abilities: { ...detail().abilities, edit: true, schedule: true, delete: true } }) },
            global: { stubs },
        }));
        const labels = editor.findAll('button, a').map((node) => node.text());
        expect(labels).toContain('Edit');
        expect(labels).toContain('Schedule');
        expect(labels).toContain('Delete');
        expect(labels).not.toContain('Approve');
    });

    it('explains four-eyes approval to someone who cannot approve their own post', () => {
        const wrapper = track(mount(PostsShow, {
            props: { post: detail({ status: 'pending_approval', approval: { required: true, submittedBy: 'm-1', approvedBy: null, mineToApprove: false } }) },
            global: { stubs },
        }));

        expect(wrapper.find('[data-testid="four-eyes"]').exists()).toBe(true);
        expect(wrapper.findAll('button').map((button) => button.text())).not.toContain('Approve');
    });

    it('shows a failed account with its reason, the manual check warning and a retry control', () => {
        const wrapper = track(mount(PostsShow, {
            props: {
                post: detail({
                    status: 'failed',
                    abilities: { ...detail().abilities, retry: true },
                    targets: [{
                        id: 't-1', accountId: 'acc-1', platform: 'facebook', platformLabel: 'Facebook Pages', accountName: 'Acme Bakery',
                        accountStatus: 'connected', bodyOverride: null, status: 'failed', attempts: 1, nextAttemptAt: null, publishedAt: null,
                        externalUrl: null, lastError: 'The platform did not confirm the post.', lastErrorCode: 'outcome_unknown',
                        needsManualCheck: true, canRetry: true,
                        attemptLog: [{ attempt: 1, outcome: 'failed', errorCode: 'outcome_unknown', message: 'Timed out after sending.', at: '2026-10-11T03:00:00+00:00' }],
                    }],
                }),
            },
            global: { stubs },
        }));

        const target = wrapper.get('[data-testid="target"]');
        expect(target.text()).toContain('The platform did not confirm the post.');
        expect(target.get('[data-testid="manual-check"]').text()).toContain('Check the account on the platform');
        const retry = target.findAll('button').find((button) => button.text() === 'Try again');
        retry!.trigger('click');
        expect(inertia.router.post).toHaveBeenCalledWith('/social.posts.targets.retry?post=post-1&target=t-1', {}, expect.anything());
    });

    it('never renders a link that is not http or https', () => {
        const wrapper = track(mount(PostsShow, {
            props: {
                post: detail({
                    linkUrl: 'javascript:alert(1)',
                    targets: [{
                        id: 't-1', accountId: 'acc-1', platform: 'x', platformLabel: 'X', accountName: 'Acme', accountStatus: 'connected',
                        bodyOverride: null, status: 'published', attempts: 1, nextAttemptAt: null, publishedAt: '2026-10-11T03:00:00+00:00',
                        externalUrl: 'javascript:alert(2)', lastError: null, lastErrorCode: null, needsManualCheck: false, canRetry: false, attemptLog: [],
                    }],
                }),
            },
            global: { stubs },
        }));

        expect(wrapper.findAll('a').filter((link) => (link.attributes('href') ?? '').toLowerCase().startsWith('javascript:'))).toHaveLength(0);
        expect(wrapper.html()).not.toContain('href="javascript:');
        expect(wrapper.text()).not.toContain('View on the platform');
    });
});

describe('post form', () => {
    it('lets the person choose only accounts that can publish and says why not for the others', () => {
        const wrapper = track(mount(PostsForm, { props: { post: null, form: formOptions() }, global: { stubs } }));
        const boxes = wrapper.findAll('input[type="checkbox"]');

        expect(boxes).toHaveLength(2);
        expect(boxes[0]!.attributes('disabled')).toBeUndefined();
        expect(boxes[1]!.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('must be connected again');
    });

    it('counts characters against the platform limit including hashtags', async () => {
        const wrapper = track(mount(PostsForm, { props: { post: null, form: formOptions() }, global: { stubs } }));
        await wrapper.findAll('input[type="checkbox"]')[0]!.setValue(true);
        await wrapper.get('textarea').setValue('Hello');

        expect(wrapper.get('[data-testid="length"]').text()).toBe('5 of 63,206 characters');
    });

    it('sends targets, wording variants and media ids as drafts', async () => {
        const wrapper = track(mount(PostsForm, {
            props: { post: null, form: formOptions({ media: [{ id: 'm-1', name: 'cake.jpg', kind: 'image', mime: 'image/jpeg', sizeBytes: 1000, width: 10, height: 10, altText: null, url: '/social/media/m-1/file', createdAt: null }] }) },
            global: { stubs },
        }));

        const boxes = wrapper.findAll('input[type="checkbox"]');
        await boxes[0]!.setValue(true);
        await boxes[2]!.setValue(true);
        await wrapper.get('textarea').setValue('  Fresh bread  ');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toMatchObject({
            method: 'post',
            url: '/social.posts.store',
            data: { body: '  Fresh bread  ', targets: [{ account_id: 'acc-1', body_override: null }], media_ids: ['m-1'] },
        });
    });

    it('warns before saving a change to a queued post', () => {
        const wrapper = track(mount(PostsForm, { props: { post: detail({ status: 'scheduled' }), form: formOptions() }, global: { stubs } }));

        expect(wrapper.find('[data-testid="returns-to-draft"]').exists()).toBe(true);
    });
});

describe('social widget', () => {
    it('shows the queue, the approvals and the next post', () => {
        inertia.page.props.auth.permissions = ['social.posts.view'];
        const wrapper = track(mount(SocialOverviewWidget, {
            props: { data: { scheduled: 7, awaitingApproval: 2, failed: 1, accountsNeedingAttention: 0, nextScheduledFor: '2026-10-20T04:30:00+00:00' } },
        }));

        expect(wrapper.text()).toContain('7');
        expect(wrapper.text()).toContain('Waiting for approval');
        expect(wrapper.get('[data-testid="social-next"]').text()).toContain('Next post goes out');
    });

    it('says so when nothing is scheduled, and speaks Bengali', () => {
        const data = { scheduled: 0, awaitingApproval: 0, failed: 0, accountsNeedingAttention: 3, nextScheduledFor: null };
        const english = track(mount(SocialOverviewWidget, { props: { data } }));
        expect(english.get('[data-testid="social-next"]').text()).toBe('Nothing is scheduled.');

        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const bengali = track(mount(SocialOverviewWidget, { props: { data } }));
        expect(bengali.text()).toContain('৩');
        expect(bengali.get('[data-testid="social-next"]').text()).toBe('কিছু নির্ধারিত নেই।');
    });
});

describe('social messages', () => {
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
        const pattern = /\bt\(\s*'(social\.[\w.]+)'/g;
        const used = new Set<string>();

        for (const file of files(join(process.cwd(), 'resources/js/Modules/Social')).filter((path) => path.endsWith('.vue') || path.endsWith('.ts'))) {
            for (const match of readFileSync(file, 'utf8').matchAll(pattern)) {
                used.add(match[1]!);
            }
        }

        expect(used.size).toBeGreaterThan(150);

        const missing = [...used].filter((key) => typeof lookup(en, key) !== 'string' || typeof lookup(bn, key) !== 'string');
        expect(missing).toEqual([]);
    });

    it('defines every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'social.status.post': ['draft', 'pending_approval', 'approved', 'scheduled', 'publishing', 'published', 'partially_failed', 'failed'],
            'social.status.target': ['pending', 'publishing', 'published', 'failed'],
            'social.status.account': ['connected', 'needs_reauthorization', 'disconnected'],
            'social.status.attempt': ['success', 'retry', 'failed', 'deferred'],
            'social.status.campaign': ['active', 'archived'],
            'social.status.mediaKind': ['image', 'video'],
            'social.platformNames': ['facebook', 'linkedin', 'x'],
            'social.calendar.months': ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'],
            'social.calendar.weekdays': ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
            'social.schedule.schedule': ['title', 'description', 'submit'],
            'social.schedule.reschedule': ['title', 'description', 'submit'],
            'social.imports.mode': ['draft', 'schedule'],
            'social.imports.modeHint': ['draft', 'schedule'],
            'social.library.tabs': ['campaigns', 'categories', 'hashtags'],
            'social.library.deleteBody': ['campaign', 'category', 'hashtags'],
            'social.analytics.figures': ['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'],
            'social.units': ['kb', 'mb'],
            'social.widget.overview': ['scheduled', 'awaitingApproval', 'failed', 'accountsNeedingAttention', 'next', 'nothingNext'],
            'social.accounts.availability': ['available', 'not_configured', 'not_implemented'],
        };

        for (const [family, keys] of Object.entries(families)) {
            for (const key of keys) {
                expect(typeof lookup(en, `${family}.${key}`), `en ${family}.${key}`).toBe('string');
                expect(typeof lookup(bn, `${family}.${key}`), `bn ${family}.${key}`).toBe('string');
            }
        }
    });

    it('labels the module, permissions, limits, sidebar entries, automation and app text the server announces', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/Social/SocialServiceProvider.php'), 'utf8');
        const permissions = [...manifest.matchAll(/new PermissionDefinition\('(social\.[\w.]+)'/g)].map((match) => match[1]!);
        const groups = new Set([...manifest.matchAll(/new PermissionDefinition\('social\.[\w.]+', '(\w+)'/g)].map((match) => match[1]!));
        const nav = [...manifest.matchAll(/new NavigationItem\('(social\w+)'/g)].map((match) => match[1]!);

        expect(permissions).toHaveLength(16);
        expect(nav).toHaveLength(8);
        expect([...groups].sort()).toEqual([
            'social_accounts', 'social_analytics', 'social_imports', 'social_library', 'social_media', 'social_posts', 'social_settings',
        ]);

        for (const messages of [en, bn]) {
            expect(typeof messages.catalogue.modules.social).toBe('string');
            expect(typeof messages.appStore.catalog.social.name).toBe('string');
            expect(messages.appStore.catalog.social.features).toHaveLength(6);

            for (const limit of ['social.scheduled_posts_per_month', 'social.connected_accounts']) {
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
            expect(Object.keys(automation.triggers).filter((key) => key.startsWith('social.'))).toHaveLength(5);
            expect(Object.keys(automation.actions).filter((key) => key.startsWith('social.'))).toHaveLength(1);
        }
    });

    it('keeps the English app text identical to the manifest', () => {
        const manifest = readFileSync(join(process.cwd(), 'app/Modules/Social/SocialServiceProvider.php'), 'utf8');
        const text = en.appStore.catalog.social;

        expect(manifest).toContain(`name: '${text.name}'`);
        expect(manifest).toContain(`description: '${text.description}'`);
        expect(manifest).toContain(`releaseNotes: '${text.releaseNotes}'`);

        for (const feature of text.features) {
            expect(manifest).toContain(`'${feature}'`);
        }
    });
});
