import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.posts.view', 'social.posts.create', 'social.posts.schedule'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    saves: [] as { method: string; url: string }[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, unknown>;
        const submit = (method: string) => (url: string, options?: { onFinish?: () => void }) => {
            inertia.saves.push({ method, url });
            options?.onFinish?.();
        };
        form.clearErrors = () => {};
        form.reset = () => form;
        form.transform = () => form;
        form.post = submit('post');
        form.put = submit('put');

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
import AccountAvatar from '@/Modules/Social/Components/AccountAvatar.vue';
import TikTokSettings from '@/Modules/Social/Components/TikTokSettings.vue';
import TikTokSettingsSummary from '@/Modules/Social/Components/TikTokSettingsSummary.vue';
import { emptyTikTokOptions, fitToCreator, isPhotoPost, tikTokOptionsFrom, tikTokProblems } from '@/Modules/Social/Components/tiktokOptions';
import type { AccountOption, CreatorInfo, FormOptions, MediaRow, PostType, TikTokOptions } from '@/Modules/Social/Components/types';
import PostsForm from '@/Modules/Social/Pages/Posts/Form.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

const tiktok: AccountOption = {
    id: 'acc-tiktok', platform: 'tiktok', platformLabel: 'TikTok', name: 'Acme Creator', handle: null, status: 'connected', usable: true,
    capabilities: { publish: true, video: true, maxText: 2200, maxMedia: 1, requiresMedia: true, requiresVideo: true }, postTypes: ['post', 'video', 'reel'],
    avatarUrl: 'https://p16-sign-va.tiktokcdn.com/avatar.jpeg',
};
const clip: MediaRow = { id: 'm-clip', name: 'clip.mp4', kind: 'video', mime: 'video/mp4', sizeBytes: 5000, width: 1080, height: 1920, altText: null, url: '/media/m-clip', createdAt: null, durationMs: 65000 };
const photo: MediaRow = { id: 'm-photo', name: 'cake.jpg', kind: 'image', mime: 'image/jpeg', sizeBytes: 1000, width: 1080, height: 1080, altText: 'A cake', url: '/media/m-photo', createdAt: null };

const info: CreatorInfo = {
    privacyLevels: ['PUBLIC_TO_EVERYONE', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
    commentDisabled: false, duetDisabled: true, stitchDisabled: false, maxVideoSeconds: 60, nickname: 'acme.creator',
};

/** fetch answering the creator info (and an empty preflight), recording every request. */
function stubFetch(answer: unknown = { state: 'ok', options: info }): { calls: { url: string; body: unknown }[] } {
    const record = { calls: [] as { url: string; body: unknown }[] };
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
        record.calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : null });

        return new Response(JSON.stringify(url.includes('creator_info') ? answer : { previews: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }));

    return record;
}

function settings(options: TikTokOptions = emptyTikTokOptions(), over: Record<string, unknown> = {}) {
    const wrapper = track(mount(TikTokSettings, {
        props: {
            account: tiktok, postType: 'video' as PostType, media: [clip], modelValue: options,
            'onUpdate:modelValue': (value: TikTokOptions) => wrapper.setProps({ modelValue: value }),
            ...over,
        },
    }));

    return wrapper;
}

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    inertia.saves.splice(0);
    setLocale('en');
});

describe('tiktok option helpers', () => {
    it('starts with nothing chosen and reads stored options safely', () => {
        expect(emptyTikTokOptions().privacy_level).toBeNull();
        expect(tikTokOptionsFrom({ privacy_level: 'SELF_ONLY', brand_content: true })).toEqual({ ...emptyTikTokOptions(), privacy_level: 'SELF_ONLY' });
        expect(tikTokOptionsFrom({ privacy_level: 'NOPE' as never }).privacy_level).toBeNull();
    });

    it('drops what the account no longer allows and lists what still blocks posting', () => {
        const chosen = { ...emptyTikTokOptions(), privacy_level: 'MUTUAL_FOLLOW_FRIENDS' as const, allow_duet: true, allow_comment: true };
        expect(fitToCreator(chosen, info)).toEqual({ ...chosen, privacy_level: null, allow_duet: false });
        expect(tikTokProblems(emptyTikTokOptions(), info, [clip])).toEqual(['privacy', 'tooLong']);
        expect(tikTokProblems({ ...emptyTikTokOptions(), privacy_level: 'SELF_ONLY', disclose_commercial: true }, null, [])).toEqual(['brand']);
        expect(tikTokProblems({ ...emptyTikTokOptions(), privacy_level: 'SELF_ONLY', disclose_commercial: true, brand_content: true }, null, [])).toEqual(['brandedPrivate']);
        expect(isPhotoPost('video', [clip])).toBe(false);
        expect(isPhotoPost('post', [photo])).toBe(true);
    });
});

describe('account pictures', () => {
    it('shows the platform picture without a referrer, and initials when it is missing, unsafe or broken', async () => {
        const wrapper = track(mount(AccountAvatar, { props: { account: { name: 'Acme Creator', avatarUrl: tiktok.avatarUrl } } }));
        const img = wrapper.get('img');

        expect(img.attributes('src')).toBe(tiktok.avatarUrl);
        expect(img.attributes('referrerpolicy')).toBe('no-referrer');
        expect(img.attributes('alt')).toBe('Profile picture of Acme Creator');

        await img.trigger('error');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toBe('AC');

        const unsafe = track(mount(AccountAvatar, { props: { account: { name: 'Acme Creator', avatarUrl: 'javascript:alert(1)' } } }));
        expect(unsafe.find('img').exists()).toBe(false);
        const none = track(mount(AccountAvatar, { props: { account: { name: 'Acme Creator', avatarUrl: null } } }));
        expect(none.text()).toBe('AC');
    });
});

describe('TikTok settings card', () => {
    it('loads the account choices and preselects nothing', async () => {
        const record = stubFetch();
        const wrapper = settings();
        await flushPromises();

        expect(record.calls[0]!.url).toBe('/social.posts.creator_info?account=acc-tiktok');
        const select = wrapper.get('select');
        expect((select.element as HTMLSelectElement).value).toBe('');
        expect(select.findAll('option').map((option) => option.text())).toEqual(['Choose who can see it', 'Everyone', 'Followers', 'Only me']);
        expect(wrapper.text()).toContain('Choose who can see this post before you schedule or publish it.');
        expect(wrapper.text()).toContain('Posting as acme.creator.');
        // Interaction switches start off; Duet is turned off in the creator's own settings.
        const boxes = wrapper.findAll('input[type="checkbox"]');
        expect(boxes.every((box) => !(box.element as HTMLInputElement).checked)).toBe(true);
        expect(wrapper.get('[data-testid="tiktok-duet"] input').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Turned off in this account’s own TikTok settings.');
        // The 65-second clip is over this creator's 60 seconds.
        expect(wrapper.find('[data-testid="tiktok-too-long"]').exists()).toBe(true);

        await select.setValue('FOLLOWER_OF_CREATOR');
        expect(wrapper.props('modelValue').privacy_level).toBe('FOLLOWER_OF_CREATOR');
        expect(wrapper.text()).not.toContain('Choose who can see this post before you schedule or publish it.');
    });

    it('hides Duet and Stitch for a photo post', async () => {
        stubFetch();
        const wrapper = settings(emptyTikTokOptions(), { postType: 'photos', media: [photo] });
        await flushPromises();

        expect(wrapper.find('[data-testid="tiktok-comment"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tiktok-duet"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="tiktok-stitch"]').exists()).toBe(false);
    });

    it('asks for a brand choice once commercial content is disclosed, and keeps branded content public', async () => {
        stubFetch();
        const wrapper = settings({ ...emptyTikTokOptions(), privacy_level: 'SELF_ONLY' });
        await flushPromises();

        expect(wrapper.get('[data-testid="tiktok-consent"]').text()).toContain('By posting, you agree to TikTok’s Music Usage Confirmation.');

        await wrapper.get('[data-testid="tiktok-disclose"] input').setValue(true);
        expect(wrapper.find('[data-testid="tiktok-brand-required"]').exists()).toBe(true);

        await wrapper.get('[data-testid="tiktok-branded"] input').setValue(true);
        expect(wrapper.find('[data-testid="tiktok-brand-required"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Branded content cannot be private on TikTok.');
        expect(wrapper.get('option[value="SELF_ONLY"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="tiktok-consent"]').text()).toContain('Branded Content Policy and Music Usage Confirmation');

        // Switching the disclosure off clears the brand choices.
        await wrapper.get('[data-testid="tiktok-disclose"] input').setValue(false);
        expect(wrapper.props('modelValue')).toMatchObject({ disclose_commercial: false, brand_content: false, brand_organic: false });
    });

    it('clears a privacy level the account no longer offers and says so', async () => {
        stubFetch();
        const wrapper = settings({ ...emptyTikTokOptions(), privacy_level: 'MUTUAL_FOLLOW_FRIENDS' });
        await flushPromises();

        expect(wrapper.props('modelValue').privacy_level).toBeNull();
        expect(wrapper.text()).toContain('TikTok no longer offers the privacy level chosen before. Choose it again.');
    });

    it('shows the server message when TikTok cannot be asked, with a retry', async () => {
        const record = stubFetch({ state: 'unavailable', message: 'This account must be connected again before its posting settings can be loaded.' });
        const wrapper = settings();
        await flushPromises();

        expect(wrapper.get('[data-testid="tiktok-unavailable"]').text()).toContain('must be connected again');
        expect(wrapper.find('select').exists()).toBe(false);

        await wrapper.get('[data-testid="tiktok-unavailable"] button').trigger('click');
        await flushPromises();
        expect(record.calls.filter((call) => call.url.includes('creator_info'))).toHaveLength(2);
    });

    it('speaks Bengali', async () => {
        setLocale('bn');
        stubFetch();
        const wrapper = settings();
        await flushPromises();

        expect(wrapper.text()).toContain('টিকটক সেটিং');
        expect(wrapper.text()).toContain('শুধু আমি');
        expect(Object.keys(bn.social.tiktok).sort()).toEqual(Object.keys(en.social.tiktok).sort());
    });
});

describe('TikTok settings on the post page', () => {
    it('lists the chosen settings, or says an older post used the automatic ones', () => {
        const chosen = track(mount(TikTokSettingsSummary, { props: { options: { ...emptyTikTokOptions(), privacy_level: 'PUBLIC_TO_EVERYONE', allow_comment: true, disclose_commercial: true, brand_organic: true } } }));
        expect(chosen.get('[data-testid="tiktok-summary-privacy"]').text()).toBe('Everyone');
        expect(chosen.text()).toContain('Comment');
        expect(chosen.text()).toContain('Your brand');

        const legacy = track(mount(TikTokSettingsSummary, { props: { options: null } }));
        expect(legacy.find('[data-testid="tiktok-summary-automatic"]').exists()).toBe(true);
    });
});

describe('composer with a TikTok account', () => {
    const form = (): FormOptions => ({
        accounts: [tiktok], campaigns: [], categories: [], hashtagSets: [], media: [clip], approvalRequired: false, timezone: 'Asia/Dhaka',
        platforms: [{ key: 'tiktok', label: 'TikTok', available: true, unavailableCode: null, reason: null, capabilities: tiktok.capabilities }],
        limits: { maxMedia: 10, maxBody: 5000 },
    });
    const stubs = { Modal: defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' }) };

    beforeEach(() => {
        inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create', 'social.posts.schedule'];
    });

    it('shows the account picture and blocks publishing until the privacy level is chosen', async () => {
        vi.useFakeTimers();
        const record = stubFetch({ state: 'ok', options: { ...info, maxVideoSeconds: 600 } });
        const wrapper = track(mount(PostsForm, { props: { post: null, form: form() }, global: { stubs } }));

        expect(wrapper.get('[data-account="acc-tiktok"] img').attributes('src')).toBe(tiktok.avatarUrl);

        await wrapper.get('[data-account="acc-tiktok"] input').setValue(true);
        await wrapper.get('textarea').setValue('New drop');
        await wrapper.get('[data-testid="add-media"]').trigger('click');
        await wrapper.findAll('[data-testid="media-library"] input[type="checkbox"]')[0]!.setValue(true);
        await vi.runAllTimersAsync();
        await flushPromises();

        expect(wrapper.find('[data-tiktok-settings="acc-tiktok"]').exists()).toBe(true);
        expect(wrapper.get('[data-preview="tiktok"] [data-testid="account-avatar"]').attributes('src')).toBe(tiktok.avatarUrl);
        expect(wrapper.get('[data-testid="checklist"]').text()).toContain('Choose the TikTok settings for Acme Creator.');
        expect(wrapper.get('[data-testid="publish-now"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="schedule"]').attributes('disabled')).toBeDefined();

        await wrapper.get('[data-tiktok-settings="acc-tiktok"] select').setValue('SELF_ONLY');
        await vi.runAllTimersAsync();
        await flushPromises();

        expect(wrapper.get('[data-testid="checklist"]').text()).not.toContain('Choose the TikTok settings');
        expect(wrapper.get('[data-testid="publish-now"]').attributes('disabled')).toBeUndefined();

        const preflight = record.calls.filter((call) => call.url.includes('preflight')).at(-1)!.body as { targets: { options: TikTokOptions | null }[] };
        expect(preflight.targets[0]!.options).toMatchObject({ privacy_level: 'SELF_ONLY', allow_comment: false });
        vi.useRealTimers();
    });
});
