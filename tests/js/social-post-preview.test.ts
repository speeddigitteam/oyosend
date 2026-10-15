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
    posts: [] as { url: string; data: unknown }[],
    saves: [] as { method: string; url: string }[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: {
        get: vi.fn(),
        post: vi.fn((url: string, data: unknown) => { inertia.posts.push({ url, data }); }),
        put: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
    },
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, unknown>;
        const submit = (method: string) => (url: string, options?: { onSuccess?: (page: unknown) => void; onFinish?: () => void }) => {
            inertia.saves.push({ method, url });
            // The store redirects to the new post's page.
            options?.onSuccess?.({ props: { post: { id: 'new-post' } } });
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
import ComposerPreview from '@/Modules/Social/Components/ComposerPreview.vue';
import {
    charCount,
    composedText,
    countedText,
    excerpt,
    initialsOf,
    layoutFor,
    linkDomain,
    previewTabs,
    targetState,
    titleFromText,
} from '@/Modules/Social/Components/preview/postPreview';
import type { AccountOption, Capabilities, FormOptions, MediaRow, PostPreview } from '@/Modules/Social/Components/types';
import PostsForm from '@/Modules/Social/Pages/Posts/Form.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    inertia.posts.splice(0);
    inertia.saves.splice(0);
    inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create'];
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
});

const CAPS: Record<string, Capabilities> = {
    facebook: { publish: true, images: true, video: true, maxText: 63206, maxMedia: 10 },
    instagram: { publish: true, images: true, video: true, maxText: 2200, maxMedia: 10, requiresMedia: true },
    linkedin: { publish: true, maxText: 3000, maxMedia: 0 },
    x: { publish: true, maxText: 280, maxMedia: 0 },
    youtube: { publish: true, video: true, maxText: 5000, maxMedia: 1, requiresMedia: true, requiresVideo: true },
    tiktok: { publish: true, video: true, maxText: 2200, maxMedia: 1, requiresMedia: true, requiresVideo: true },
    pinterest: { publish: true, images: true, video: true, maxText: 500, maxMedia: 1, requiresMedia: true },
};

const LABELS: Record<string, string> = {
    facebook: 'Facebook Pages', instagram: 'Instagram', linkedin: 'LinkedIn', x: 'X', youtube: 'YouTube', tiktok: 'TikTok', pinterest: 'Pinterest',
};

function account(platform: string, over: Partial<AccountOption> = {}): AccountOption {
    return {
        id: `acc-${platform}`, platform, platformLabel: LABELS[platform] ?? platform, name: `Acme ${LABELS[platform] ?? platform}`, handle: null,
        status: 'connected', usable: true, capabilities: CAPS[platform] ?? { publish: true }, postTypes: ['post', 'photos', 'video', 'reel'], ...over,
    };
}

const photo: MediaRow = { id: 'm-photo', name: 'cake.jpg', kind: 'image', mime: 'image/jpeg', sizeBytes: 1000, width: 1080, height: 1080, altText: 'A cake', url: '/media/m-photo', createdAt: null };
const clip: MediaRow = { id: 'm-clip', name: 'clip.mp4', kind: 'video', mime: 'video/mp4', sizeBytes: 5000, width: 1080, height: 1920, altText: null, url: '/media/m-clip', createdAt: null, durationMs: 65000 };

describe('preview helpers', () => {
    it('uses the account wording when it is set, else the main text, then the hashtags', () => {
        expect(composedText('Main text', '  Own wording  ', ['#eid'])).toBe('Own wording\n\n#eid');
        expect(composedText('  Main text ', '   ', [])).toBe('Main text');
        expect(composedText('Main text', null, ['#eid', '#sale'])).toBe('Main text\n\n#eid #sale');
        expect(composedText('', undefined, ['#eid'])).toBe('#eid');
    });

    it('splits a short-text post at its counted limit, counting an emoji once', () => {
        const fits = countedText('Hello', 280);
        expect(fits).toEqual({ within: 'Hello', over: '', length: 5, limit: 280 });

        const long = countedText(`${'a'.repeat(278)}\u{1F370}bcd`, 280);
        expect(long.length).toBe(282);
        expect(charCount(long.within)).toBe(280);
        expect(long.within).toBe(`${'a'.repeat(278)}\u{1F370}b`);
        expect(long.over).toBe('cd');

        expect(countedText('anything', 0)).toEqual({ within: 'anything', over: '', length: 8, limit: 0 });
    });

    it('takes a video title from the first line, cut to 100 characters, and the rest as the description', () => {
        expect(titleFromText('\n  Eid sale opens  \nEverything is 20 percent off.\nSee you there.')).toEqual({
            title: 'Eid sale opens',
            description: 'Everything is 20 percent off.\nSee you there.',
            titleCut: false,
        });

        const long = titleFromText(`${'x'.repeat(120)}\nrest`);
        expect(long.title).toHaveLength(100);
        expect(long.titleCut).toBe(true);
        expect(long.description).toBe('rest');

        expect(titleFromText('   \n  ')).toEqual({ title: '', description: '', titleCut: false });
    });

    it('makes one tab per ticked platform, in a fixed order, grouping accounts of the same platform', () => {
        const tabs = previewTabs([
            account('x'),
            account('facebook', { id: 'fb-1' }),
            account('youtube'),
            account('facebook', { id: 'fb-2', name: 'Second Page' }),
        ]);

        expect(tabs.map((tab) => tab.platform)).toEqual(['facebook', 'x', 'youtube']);
        expect(tabs[0]!.accounts.map((item) => item.id)).toEqual(['fb-1', 'fb-2']);
        expect(previewTabs([])).toEqual([]);
        expect(layoutFor('mastodon')).toBe('feed');
        expect(layoutFor('tiktok')).toBe('tiktok');
    });

    it('folds a long caption on a word, and prints only real web links as a domain', () => {
        expect(excerpt('short', 125)).toEqual({ text: 'short', truncated: false });
        const folded = excerpt('word '.repeat(40), 125);
        expect(folded.truncated).toBe(true);
        expect(charCount(folded.text)).toBeLessThanOrEqual(125);
        expect(folded.text.endsWith(' ')).toBe(false);

        expect(linkDomain('https://www.example.com/eid?x=1')).toBe('example.com');
        expect(linkDomain('javascript:alert(1)')).toBeNull();
        expect(linkDomain('not a link')).toBeNull();
        expect(initialsOf('Acme bakery shop')).toBe('AB');
        expect(initialsOf('  ')).toBe('?');
    });

    it('blocks the preview when the media miss what the platform needs or the account cannot publish', () => {
        expect(targetState(account('youtube'), [photo], null)).toMatchObject({ blocked: true, requirements: ['needsVideo'] });
        expect(targetState(account('youtube'), [clip], null)).toMatchObject({ blocked: false, requirements: [] });
        expect(targetState(account('instagram'), [], null)).toMatchObject({ blocked: true, requirements: ['needsMedia'] });
        expect(targetState(account('facebook', { usable: false }), [], null).blocked).toBe(true);

        const server = { issues: ['Images must be JPEG.'], warnings: ['Square works best.'] } as PostPreview;
        expect(targetState(account('facebook'), [photo], server)).toEqual({
            blocked: false, requirements: [], issues: ['Images must be JPEG.'], warnings: ['Square works best.'],
        });
    });

    it('has every preview string in both languages', () => {
        for (const key of Object.keys(en.social.preview.reactions)) {
            expect(typeof (bn.social.preview.reactions as Record<string, string>)[key]).toBe('string');
        }

        expect(Object.keys(bn.social.composer).sort()).toEqual(Object.keys(en.social.composer).sort());
        expect(Object.keys(bn.social.preview).sort()).toEqual(Object.keys(en.social.preview).sort());

        for (const text of [...Object.values(bn.social.composer), ...Object.values(bn.social.preview)]) {
            if (typeof text === 'string') {
                expect(text.replace(/\{\w+\}/gu, '')).not.toMatch(/[0-9]/u);
            }
        }
    });
});

describe('composer preview', () => {
    const base = {
        media: [] as MediaRow[],
        linkUrl: '',
        checks: [] as PostPreview[] | null,
        checking: false,
        unusable: {} as Record<string, string | null>,
    };

    it('shows a tab only for the platforms that were ticked', () => {
        const accounts = [account('linkedin'), account('facebook')];
        const wrapper = track(mount(ComposerPreview, {
            props: { ...base, accounts, texts: { 'acc-linkedin': 'Hello', 'acc-facebook': 'Hello' } },
        }));

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs.map((tab) => tab.attributes('data-preview-tab'))).toEqual(['facebook', 'linkedin']);
        expect(wrapper.find('[role="tablist"]').attributes('aria-label')).toBe('Platforms');
        expect(tabs[0]!.attributes('aria-selected')).toBe('true');
        expect(wrapper.find('[data-preview="facebook"]').exists()).toBe(true);
        expect(wrapper.find('[data-preview-tab="x"]').exists()).toBe(false);
    });

    it('says to choose an account when none is ticked', () => {
        const wrapper = track(mount(ComposerPreview, { props: { ...base, accounts: [], texts: {} } }));

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="preview-none"]').text()).toBe('Choose an account to see the preview.');
    });

    it('draws the text the account would publish, as text, with the author and "Just now"', () => {
        const wrapper = track(mount(ComposerPreview, {
            props: { ...base, accounts: [account('facebook')], texts: { 'acc-facebook': 'Own <b>wording</b>' }, linkUrl: 'https://www.acme.test/eid' },
        }));

        expect(wrapper.get('[data-testid="preview-text"]').text()).toBe('Own <b>wording</b>');
        expect(wrapper.find('[data-testid="preview-text"] b').exists()).toBe(false);
        expect(wrapper.get('[data-testid="preview-author"]').text()).toContain('Acme Facebook Pages');
        expect(wrapper.get('[data-testid="preview-author"]').text()).toContain('Just now');
        expect(wrapper.get('[data-testid="preview-link"]').text()).toBe('acme.test');
    });

    it('marks the part of an X post past the limit and counts it', () => {
        const text = 'a'.repeat(290);
        const wrapper = track(mount(ComposerPreview, { props: { ...base, accounts: [account('x')], texts: { 'acc-x': text } } }));

        expect(wrapper.get('[data-testid="preview-over"]').text()).toBe('a'.repeat(10));
        expect(wrapper.get('[data-testid="preview-count"]').text()).toBe('290 of 280 characters');
        expect(wrapper.get('[data-testid="preview-over-note"]').text()).toBe('10 characters over the limit.');
    });

    it('titles a YouTube video with the first line of the text', () => {
        const wrapper = track(mount(ComposerPreview, {
            props: { ...base, accounts: [account('youtube')], texts: { 'acc-youtube': 'Eid sale opens\nEverything is off.' }, media: [clip] },
        }));

        expect(wrapper.find('[data-preview="youtube"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="preview-title"]').text()).toBe('Eid sale opens');
        expect(wrapper.get('[data-preview="youtube"]').text()).toContain('Everything is off.');
        expect(wrapper.text()).toContain('1:05');
    });

    it('shows the reason instead of a preview when the platform cannot take the post', () => {
        const wrapper = track(mount(ComposerPreview, {
            props: {
                ...base,
                accounts: [account('youtube')],
                texts: { 'acc-youtube': 'Eid sale' },
                media: [photo],
                checks: [{ accountId: 'acc-youtube', issues: ['This platform publishes video only. Add a video.'], warnings: [] } as unknown as PostPreview],
            },
        }));

        const blocked = wrapper.get('[data-testid="preview-blocked"]');
        expect(blocked.text()).toContain('This post cannot be published to YouTube yet.');
        expect(blocked.text()).toContain('Needs a video');
        expect(blocked.text()).toContain('publishes video only');
        expect(wrapper.find('[data-preview="youtube"]').exists()).toBe(false);
    });

    it('shows why an account that cannot publish is not previewed', () => {
        const wrapper = track(mount(ComposerPreview, {
            props: {
                ...base,
                accounts: [account('facebook', { usable: false, status: 'needs_reauthorization' })],
                texts: { 'acc-facebook': 'Hello' },
                unusable: { 'acc-facebook': 'This account must be connected again before it can publish.' },
            },
        }));

        expect(wrapper.get('[data-testid="preview-blocked"]').text()).toContain('must be connected again');
        expect(wrapper.find('[data-preview="facebook"]').exists()).toBe(false);
    });

    it('lists other server issues above the preview, and counts the extra files of a carousel', () => {
        const wrapper = track(mount(ComposerPreview, {
            props: {
                ...base,
                accounts: [account('instagram')],
                texts: { 'acc-instagram': 'Fresh' },
                media: [photo, { ...photo, id: 'm-2' }, { ...photo, id: 'm-3' }],
                checks: [{ accountId: 'acc-instagram', issues: ['Images must be JPEG.'], warnings: ['Square works best.'] } as unknown as PostPreview],
            },
        }));

        expect(wrapper.get('[data-testid="preview-issues"]').text()).toContain('Images must be JPEG.');
        expect(wrapper.find('[data-preview="instagram"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="preview-carousel"]').text()).toBe('1 of 3');
        expect(wrapper.get('[data-testid="preview-warnings"]').text()).toContain('Square works best.');
    });

    it('offers an account switcher when two accounts share a platform', async () => {
        const wrapper = track(mount(ComposerPreview, {
            props: {
                ...base,
                accounts: [account('facebook', { id: 'fb-1', name: 'First Page' }), account('facebook', { id: 'fb-2', name: 'Second Page' })],
                texts: { 'fb-1': 'One', 'fb-2': 'Two' },
            },
        }));

        expect(wrapper.findAll('[role="tab"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="preview-text"]').text()).toBe('One');

        await wrapper.get('select').setValue('fb-2');

        expect(wrapper.get('[data-testid="preview-text"]').text()).toBe('Two');
        expect(wrapper.get('[data-testid="preview-author"]').text()).toContain('Second Page');
    });

    it('folds a long Instagram caption behind a "more" toggle', async () => {
        const wrapper = track(mount(ComposerPreview, {
            props: { ...base, accounts: [account('instagram')], texts: { 'acc-instagram': 'word '.repeat(60).trim() }, media: [photo] },
        }));
        const toggle = wrapper.get('[data-testid="preview-more"]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toBe('more');

        await toggle.trigger('click');

        expect(wrapper.get('[data-testid="preview-more"]').attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="preview-text"]').text()).toContain('word '.repeat(59).trim());
    });

    it('speaks Bengali with Bengali digits', () => {
        setLocale('bn');
        inertia.page.props.app.numerals = 'beng';
        const wrapper = track(mount(ComposerPreview, { props: { ...base, accounts: [account('x')], texts: { 'acc-x': 'a'.repeat(12) } } }));

        expect(wrapper.text()).toContain('এইমাত্র');
        expect(wrapper.get('[data-testid="preview-count"]').text()).toBe('২৮০ এর মধ্যে ১২ অক্ষর');
    });
});

describe('post composer page', () => {
    const options = (accounts: AccountOption[], over: Partial<FormOptions> = {}): FormOptions => ({
        accounts, campaigns: [], categories: [], hashtagSets: [], media: [photo, clip], approvalRequired: false, timezone: 'Asia/Dhaka',
        platforms: accounts.map((item) => ({ key: item.platform, label: item.platformLabel, available: true, unavailableCode: null, reason: null, capabilities: item.capabilities })),
        limits: { maxMedia: 10, maxBody: 5000 },
        ...over,
    });
    const stubs = { Modal: defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' }) };

    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    });

    it('previews the ticked accounts with their own wording', async () => {
        const wrapper = track(mount(PostsForm, { props: { post: null, form: options([account('facebook'), account('x')]) }, global: { stubs } }));
        const boxes = wrapper.findAll('[data-testid="account-picker"] input[type="checkbox"]');

        expect(wrapper.find('[data-testid="preview-none"]').exists()).toBe(true);

        await boxes[1]!.setValue(true);
        await wrapper.get('textarea').setValue('Fresh bread today');

        expect(wrapper.findAll('[role="tab"]').map((tab) => tab.attributes('data-preview-tab'))).toEqual(['x']);
        expect(wrapper.get('[data-testid="preview-text"]').text()).toBe('Fresh bread today');

        await wrapper.get('[data-account-settings="acc-x"] textarea').setValue('Shorter for X');

        expect(wrapper.get('[data-testid="preview-text"]').text()).toBe('Shorter for X');
        expect(wrapper.get('[data-account-settings="acc-x"] [data-testid="length"]').text()).toBe('13 of 280 characters');
    });

    it('shows chosen media as thumbnails that can be removed, and opens the library to add more', async () => {
        const wrapper = track(mount(PostsForm, { props: { post: null, form: options([account('facebook')]) }, global: { stubs } }));
        const add = wrapper.get('[data-testid="add-media"]');

        expect(add.attributes('aria-expanded')).toBe('false');
        expect(add.text()).toBe('Add photos or videos');

        await add.trigger('click');
        expect(wrapper.get('[data-testid="add-media"]').attributes('aria-expanded')).toBe('true');

        await wrapper.findAll('[data-testid="media-library"] input[type="checkbox"]')[0]!.setValue(true);

        const chosen = wrapper.get('[data-testid="chosen-media"]');
        expect(chosen.find('img').attributes('alt')).toBe('A cake');
        expect(wrapper.get('[data-testid="add-media"]').text()).toBe('Add more');

        await chosen.get('button[type="button"]').trigger('click');

        expect(wrapper.get('[data-testid="chosen-media"]').find('img').exists()).toBe(false);
    });

    it('offers only saving a draft to a member who may not schedule', () => {
        const wrapper = track(mount(PostsForm, { props: { post: null, form: options([account('facebook')]) }, global: { stubs } }));

        expect(wrapper.find('[data-testid="save-draft"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="publish-now"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="schedule"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="submit-approval"]').exists()).toBe(false);
    });

    it('publishes now by saving the draft and then queueing the saved post', async () => {
        inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create', 'social.posts.schedule'];
        const wrapper = track(mount(PostsForm, { props: { post: null, form: options([account('facebook')]) }, global: { stubs } }));

        expect(wrapper.get('[data-testid="publish-now"]').attributes('disabled')).toBeDefined();

        await wrapper.findAll('[data-testid="account-picker"] input[type="checkbox"]')[0]!.setValue(true);
        await wrapper.get('textarea').setValue('Fresh bread');
        await flushPromises();
        await wrapper.get('[data-testid="publish-now"]').trigger('click');

        expect(inertia.saves).toEqual([{ method: 'post', url: '/social.posts.store' }]);
        expect(inertia.posts).toEqual([{ url: '/social.posts.schedule?post=new-post', data: { publish_now: true } }]);
    });

    it('schedules for a chosen time through the dialog', async () => {
        inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create', 'social.posts.schedule'];
        const wrapper = track(mount(PostsForm, { props: { post: null, form: options([account('facebook')]) }, global: { stubs } }));

        await wrapper.findAll('[data-testid="account-picker"] input[type="checkbox"]')[0]!.setValue(true);
        await wrapper.get('textarea').setValue('Fresh bread');
        await flushPromises();
        await wrapper.get('[data-testid="schedule"]').trigger('click');

        const when = wrapper.findAll('input[type="datetime-local"]').at(-1)!;
        await when.setValue('2030-01-02T09:30');
        await wrapper.findAll('form').at(-1)!.trigger('submit');

        expect(inertia.saves).toEqual([{ method: 'post', url: '/social.posts.store' }]);
        expect(inertia.posts).toEqual([{ url: '/social.posts.schedule?post=new-post', data: { scheduled_for: '2030-01-02T09:30' } }]);
    });

    it('submits for approval instead when the workspace requires it', async () => {
        inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create', 'social.posts.schedule'];
        const wrapper = track(mount(PostsForm, { props: { post: null, form: options([account('facebook')], { approvalRequired: true }) }, global: { stubs } }));

        expect(wrapper.find('[data-testid="publish-now"]').exists()).toBe(false);

        await wrapper.findAll('[data-testid="account-picker"] input[type="checkbox"]')[0]!.setValue(true);
        await wrapper.get('textarea').setValue('Fresh bread');
        await flushPromises();
        await wrapper.get('[data-testid="submit-approval"]').trigger('click');

        expect(inertia.posts).toEqual([{ url: '/social.posts.submit?post=new-post', data: {} }]);
    });
});
