import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.posts.view', 'social.posts.create'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, unknown>;
        form.clearErrors = () => {};
        form.reset = () => form;
        form.transform = () => form;
        form.post = vi.fn();
        form.put = vi.fn();

        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();

        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import MediaCompatBadges from '@/Modules/Social/Components/MediaCompatBadges.vue';
import PostTypePicker from '@/Modules/Social/Components/PostTypePicker.vue';
import { clockParts, requestPreflight, typeAvailability } from '@/Modules/Social/Components/preflight';
import type { AccountOption, CompatBadge, FormOptions, MediaRow, PostPreview, PostType } from '@/Modules/Social/Components/types';
import PostsForm from '@/Modules/Social/Pages/Posts/Form.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

const ALL: Record<PostType, string | null> = { post: null, photos: null, video: null, reel: null };

describe('post type picker', () => {
    it('offers the four types as one radio group and lets a type be chosen', async () => {
        const wrapper = track(mount(PostTypePicker, { props: { modelValue: 'post', availability: ALL } }));
        const radios = wrapper.findAll('input[type="radio"]');

        expect(radios.map((radio) => (radio.element as HTMLInputElement).value)).toEqual(['post', 'photos', 'video', 'reel']);
        expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true);

        await radios[3]!.setValue();

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['reel']);
    });

    it('disables a type no chosen account can publish and says why', () => {
        const wrapper = track(mount(PostTypePicker, {
            props: { modelValue: 'post', availability: { ...ALL, reel: 'Not available for Company', video: 'Not available for Company' } },
        }));
        const reel = wrapper.findAll('input[type="radio"]')[3]!.element as HTMLInputElement;

        expect(reel.disabled).toBe(true);
        expect(wrapper.text()).toContain('Not available for Company');
    });
});

describe('media compatibility badges', () => {
    const compat: CompatBadge[] = [
        { platform: 'facebook', platformLabel: 'Facebook Pages', code: 'video_ok', tone: 'ok' },
        { platform: 'facebook', platformLabel: 'Facebook Pages', code: 'reel_too_long', tone: 'danger' },
        { platform: 'linkedin', platformLabel: 'LinkedIn', code: 'unsupported', tone: 'muted' },
        { platform: 'x', platformLabel: 'X', code: 'not_a_known_code', tone: 'warn' },
    ];

    it('words each verdict per platform and ignores a code it cannot word', () => {
        const wrapper = track(mount(MediaCompatBadges, { props: { compat } }));

        expect(wrapper.text()).toContain('Facebook Pages: too long for Reels');
        expect(wrapper.text()).toContain('LinkedIn: not supported');
        expect(wrapper.text()).not.toContain('not_a_known_code');
    });

    it('can be narrowed to the chosen accounts platforms and limited', () => {
        const only = track(mount(MediaCompatBadges, { props: { compat, platforms: ['facebook'] } }));
        const limited = track(mount(MediaCompatBadges, { props: { compat, max: 1 } }));

        expect(only.findAll('li')).toHaveLength(2);
        expect(only.text()).not.toContain('LinkedIn');
        expect(limited.findAll('li')).toHaveLength(1);
    });

    it('words the same verdict in Bengali', () => {
        expect(bn.socialPublishing.compat.reel_ready).not.toBe(en.socialPublishing.compat.reel_ready);
        expect(Object.keys(bn.socialPublishing.compat).sort()).toEqual(Object.keys(en.socialPublishing.compat).sort());
    });
});

describe('preflight helpers', () => {
    it('lists, per type, the accounts that cannot publish it', () => {
        const result = typeAvailability([
            { name: 'Brand Page', postTypes: ['post', 'photos', 'video', 'reel'] },
            { name: 'Company', postTypes: ['post'] },
            { name: 'Legacy' },
        ]);

        expect(result.post).toEqual([]);
        expect(result.photos).toEqual(['Company', 'Legacy']);
        expect(result.reel).toEqual(['Company', 'Legacy']);
    });

    it('splits a length into minutes and seconds', () => {
        expect(clockParts(42500)).toEqual({ minutes: 0, seconds: 43 });
        expect(clockParts(125000)).toEqual({ minutes: 2, seconds: 5 });
        expect(clockParts(-5)).toEqual({ minutes: 0, seconds: 0 });
    });

    it('sends the draft as JSON with the CSRF token and returns the previews, or null when unavailable', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ previews: [{ accountId: 'a1' }] }) });
        vi.stubGlobal('fetch', fetchMock);
        const input = { post_type: 'reel' as PostType, body: 'x', link_url: '', hashtag_set_id: '', targets: [], media_ids: [] };

        await expect(requestPreflight('/p', input)).resolves.toEqual([{ accountId: 'a1' }]);
        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
        expect(JSON.parse(init.body as string).post_type).toBe('reel');

        fetchMock.mockResolvedValueOnce({ ok: false, json: () => Promise.resolve({}) });
        await expect(requestPreflight('/p', input)).resolves.toBeNull();
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await expect(requestPreflight('/p', input)).resolves.toBeNull();
    });
});

describe('post composer', () => {
    const account = (over: Partial<AccountOption>): AccountOption => ({
        id: 'a1', platform: 'facebook', platformLabel: 'Facebook Pages', name: 'Brand Page', handle: null, status: 'connected', usable: true,
        capabilities: { publish: true, images: true, video: true, maxText: 63206, maxMedia: 10 },
        postTypes: ['post', 'photos', 'video', 'reel'], ...over,
    });
    const video = (over: Partial<MediaRow>): MediaRow => ({
        id: 'm1', name: 'clip.mp4', kind: 'video', mime: 'video/mp4', sizeBytes: 1000, width: 1080, height: 1920, durationMs: 125000, probe: 'read',
        altText: null, url: '/media/m1/file', createdAt: null,
        compat: [{ platform: 'facebook', platformLabel: 'Facebook Pages', code: 'reel_ready', tone: 'ok' }], ...over,
    });
    const photo = video({ id: 'm2', name: 'a.png', kind: 'image', mime: 'image/png', durationMs: null, probe: null, compat: [] });
    const options = (accounts: AccountOption[]): FormOptions => ({
        accounts, campaigns: [], categories: [], hashtagSets: [], media: [video({}), photo], approvalRequired: false, timezone: 'Asia/Dhaka',
        platforms: [{ key: 'facebook', label: 'Facebook Pages', available: true, unavailableCode: null, reason: null, capabilities: {} }],
        limits: { maxMedia: 10, maxBody: 5000 },
    });
    const preview = (over: Partial<PostPreview>): PostPreview => ({
        targetId: 't', accountId: 'a1', platform: 'facebook', platformLabel: 'Facebook Pages', accountName: 'Brand Page', accountStatus: 'connected',
        text: 'x', length: 1, maxLength: 63206, mediaCount: 1, capabilities: {}, issues: [], warnings: [], postType: 'post', ...over,
    });

    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the server checks per account, the checklist, the clock of a video and its Reels badge', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ previews: [preview({ issues: ['A Reel can be at most 90 seconds; this video is longer.'], warnings: ['Reels are vertical (9:16).'] })] }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(PostsForm, {
            props: {
                form: options([account({})]),
                post: {
                    id: 'p1', title: null, excerpt: '', status: 'draft', postType: 'reel', scheduledFor: null, publishedAt: null, at: null, campaign: null, category: null,
                    createdAt: null, body: 'Caption', linkUrl: null, hashtags: [], hashtagSetId: null, scheduledForLocal: null, owner: null, submittedAt: null,
                    approvedAt: null, rejectedAt: null, rejectionReason: null, media: [video({})], targets: [{
                        id: 't1', accountId: 'a1', platform: 'facebook', platformLabel: 'Facebook Pages', accountName: 'Brand Page', accountStatus: 'connected',
                        bodyOverride: null, status: 'pending', attempts: 0, nextAttemptAt: null, publishedAt: null, externalUrl: null, lastError: null,
                        lastErrorCode: null, needsManualCheck: false, canRetry: false, attemptLog: [],
                    }], comments: [], previews: [], approval: { required: false, submittedBy: null, approvedBy: null, mineToApprove: false },
                    abilities: { edit: true, delete: true, submit: true, approve: false, reject: false, schedule: true, reschedule: false, unschedule: false, retry: false, comment: true },
                    now: '2030-01-01T00:00:00Z',
                },
            },
            global: { stubs: { TenantLayout: false } },
        }));

        await flushPromises();

        expect(fetchMock).toHaveBeenCalled();
        const body = JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string);
        expect(body.post_type).toBe('reel');
        // `options` is null for a platform without per-post settings (TikTok sends its chosen settings).
        expect(body.targets).toEqual([{ account_id: 'a1', body_override: null, options: null }]);
        expect(body.media_ids).toEqual(['m1']);

        expect(wrapper.find('[data-testid="account-issues"]').text()).toContain('at most 90 seconds');
        expect(wrapper.find('[data-testid="account-warnings"]').text()).toContain('vertical');
        expect(wrapper.find('[data-testid="checklist"]').text()).toContain('1 accounts cannot publish this yet');
        expect(wrapper.text()).toContain('Length 2:05');
        expect(wrapper.find('[data-testid="compat"]').text()).toContain('Reels-ready');
        // A video post takes one video: the photo is not selectable.
        const checkboxes = wrapper.findAll('input[type="checkbox"]').filter((box) => (box.element as HTMLInputElement).disabled);
        expect(checkboxes.length).toBeGreaterThan(0);
    });

    it('keeps working, and says the check is unavailable, when the server cannot be asked', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        const wrapper = track(mount(PostsForm, { props: { form: options([account({})]), post: null } }));
        const accountBox = wrapper.findAll('input[type="checkbox"]')[0]!;

        await accountBox.setValue(true);
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        expect(wrapper.find('[data-testid="checklist"]').text()).toContain('The check is not available right now');
    });
});

describe('post page', () => {
    const target = (over: Record<string, unknown>) => ({
        id: 't1', accountId: 'a1', platform: 'facebook', platformLabel: 'Facebook Pages', accountName: 'Brand Page', accountStatus: 'connected',
        bodyOverride: null, status: 'pending', attempts: 1, nextAttemptAt: null, publishedAt: null, externalUrl: null, lastError: null,
        lastErrorCode: null, needsManualCheck: false, canRetry: false, attemptLog: [], ...over,
    });
    const detail = (targets: unknown[], status = 'publishing') => ({
        id: 'p1', title: 'Launch', excerpt: '', status, postType: 'reel', scheduledFor: null, publishedAt: null, at: null, campaign: null, category: null,
        createdAt: '2030-01-01T00:00:00Z', body: 'Caption', linkUrl: null, hashtags: [], hashtagSetId: null, scheduledForLocal: null, owner: null,
        submittedAt: null, approvedAt: null, rejectedAt: null, rejectionReason: null, media: [], targets, comments: [], previews: [],
        approval: { required: false, submittedBy: null, approvedBy: null, mineToApprove: false },
        abilities: { edit: false, delete: false, submit: false, approve: false, reject: false, schedule: false, reschedule: false, unschedule: false, retry: true, comment: false },
        now: '2030-01-01T00:00:00Z',
    });

    it('explains a video that is processing and follows it without a reload button', async () => {
        const { default: PostsShow } = await import('@/Modules/Social/Pages/Posts/Show.vue');
        const wrapper = track(mount(PostsShow, {
            props: { post: detail([target({ status: 'processing', remoteState: 'processing', processingSince: '2030-01-01T00:00:00Z' })]) as never },
        }));

        expect(wrapper.find('[data-testid="processing"]').text()).toContain('The platform is processing the video');
        expect(wrapper.find('[data-testid="processing"]').text()).toContain('no need to publish it again');
        expect(wrapper.find('[data-testid="post-type"]').text()).toBe('Reel');
    });

    it('turns a missing permission into a calm prompt to connect the account again', async () => {
        const { default: PostsShow } = await import('@/Modules/Social/Pages/Posts/Show.vue');
        const wrapper = track(mount(PostsShow, {
            props: {
                post: detail([target({
                    status: 'failed', lastErrorCode: 'facebook_permission_missing', accountStatus: 'needs_reauthorization',
                    lastError: 'Facebook says this connection is missing a permission needed to publish.',
                })], 'failed') as never,
            },
        }));
        const prompt = wrapper.find('[data-testid="reconnect"]');

        expect(prompt.text()).toContain('This account needs to be connected again');
        expect(prompt.find('a').attributes('href')).toBe('/social.accounts.index');
    });

    it('shows no reconnect prompt for an ordinary failure', async () => {
        const { default: PostsShow } = await import('@/Modules/Social/Pages/Posts/Show.vue');
        const wrapper = track(mount(PostsShow, {
            props: { post: detail([target({ status: 'failed', lastErrorCode: 'video_processing_failed', lastError: 'Could not process.' })], 'failed') as never },
        }));

        expect(wrapper.find('[data-testid="reconnect"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Could not process.');
    });
});
