import { mount, type VueWrapper } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.comments.view', 'social.comments.reply', 'social.comments.moderate', 'social.comments.manage_rules'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    submissions: [] as { method: string; url: string; data: Record<string, unknown> }[],
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
        form.data = () => Object.fromEntries(Object.keys(initial).map((key) => [key, JSON.parse(JSON.stringify(form[key] ?? null))]));
        const submit = (method: string, url: string, options?: { onSuccess?: () => void }) => {
            inertia.submissions.push({ method, url, data: transform(form.data()) });
            options?.onSuccess?.();
        };
        form.post = (url: string, options?: { onSuccess?: () => void }) => submit('post', url, options);
        form.put = (url: string, options?: { onSuccess?: () => void }) => submit('put', url, options);
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
import SocialCommentsWidget from '@/Modules/Social/Components/comments/SocialCommentsWidget.vue';
import type { AudienceComment, CommentPage, CommentRuleData } from '@/Modules/Social/Components/comments/commentTypes';
import CommentsIndex from '@/Modules/Social/Pages/Comments/Index.vue';
import CommentRules from '@/Modules/Social/Pages/Comments/Rules.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /></div>' });
const stubs = { Modal: modalStub, ConfirmDialog: true };

function comment(overrides: Partial<AudienceComment> = {}): AudienceComment {
    return {
        id: 'c1', accountId: 'a1', accountName: 'Acme Bakery', platform: 'facebook', platformLabel: 'Facebook Pages',
        authorName: 'Rahim Uddin', isFromPage: false, message: 'দাম কত? <script>alert(1)</script>', commentedAt: '2026-10-11T04:00:00+00:00',
        unread: true, replied: false, repliedAt: null, replyCount: 0, hidden: false, deleted: false, needsAttention: false, attentionReason: null,
        handled: false, tags: ['vip'], assignee: null, post: { id: 'p1', title: 'Eid sale' }, externalPostId: '1_2', permalink: null,
        likeCount: 0, likedByPage: false, hasAttachment: false, sentiment: null, isReply: false, parentId: null, lastError: null, source: 'webhook',
        ...overrides,
    };
}

const counts = { open: 1, unread: 1, needs_attention: 0, replied: 0, hidden: 0, mine: 0, handled: 0 };

function indexProps(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        comments: { data: [comment()], prev_page_url: null, next_page_url: null, from: 1, to: 1, total: 1 },
        filters: { status: 'open', account_id: null, post_id: null, q: null },
        counts,
        thread: null,
        accounts: [{ id: 'a1', name: 'Acme Bakery', platform: 'facebook' }],
        posts: [],
        members: [{ id: 'm1', name: 'Nadia Karim' }],
        can: { reply: true, moderate: true, rules: true },
        limits: { reply: 2000, note: 2000, tag: 40, bulk: 50 },
        hasSources: true,
        ...overrides,
    };
}

beforeEach(() => {
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('comments inbox', () => {
    it('shows comment text as plain text, never as markup', () => {
        const wrapper = track(mount(CommentsIndex, { props: indexProps() as never, global: { stubs } }));

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.get('[data-testid="comment-row-c1"]').text()).toContain('<script>alert(1)</script>');
    });

    it('asks the server for another status and opens a thread, marking it read', async () => {
        const wrapper = track(mount(CommentsIndex, { props: indexProps() as never, global: { stubs } }));

        await wrapper.get('[data-testid="status-unread"]').trigger('click');
        expect(inertia.router.get).toHaveBeenCalledWith('/social.comments.index', expect.objectContaining({ status: 'unread' }), expect.anything());

        await wrapper.get('[data-testid="comment-row-c1"] button').trigger('click');
        expect(inertia.router.get).toHaveBeenLastCalledWith('/social.comments.index', expect.objectContaining({ comment: 'c1' }), expect.anything());
        expect(inertia.router.post).toHaveBeenCalledWith('/social.comments.read?comment=c1', {}, expect.anything());
    });

    it('offers bulk actions only for the rights the person has', async () => {
        const wrapper = track(mount(CommentsIndex, { props: indexProps({ can: { reply: false, moderate: false, rules: false } }) as never, global: { stubs } }));
        await wrapper.get('input[type="checkbox"]').setValue(true);

        expect(wrapper.text()).toContain('Mark read');
        expect(wrapper.text()).not.toContain('Hide');
        expect(wrapper.text()).not.toContain('Rules and Pages');
    });

    it('renders the thread with reply, moderation and notes for a person who may', () => {
        const thread = { focus: 'c1', root: 'c1', comments: [comment(), comment({ id: 'c2', isFromPage: true, isReply: true, message: 'Thanks!', authorName: null, unread: false })], notes: [{ id: 'n1', body: 'Check stock', at: '2026-10-11T05:00:00+00:00', author: { id: 'm1', name: 'Nadia Karim' } }] };
        const wrapper = track(mount(CommentsIndex, { props: indexProps({ thread }) as never, global: { stubs } }));
        const panel = wrapper.get('[data-testid="comment-thread"]');

        expect(panel.text()).toContain('Thanks!');
        expect(panel.text()).toContain('Send reply');
        expect(panel.text()).toContain('Check stock');
        expect(panel.text()).toContain('Delete');
    });

    it('speaks Bengali', () => {
        setLocale('bn');
        const wrapper = track(mount(CommentsIndex, { props: indexProps() as never, global: { stubs } }));

        expect(wrapper.text()).toContain('মন্তব্য');
        expect(wrapper.get('[data-testid="status-needs_attention"]').text()).toContain('নজর দরকার');
    });

    it('shows the dashboard widget counts', () => {
        const wrapper = track(mount(SocialCommentsWidget, { props: { data: { unhandled: 5, unread: 2, needsAttention: 1 } } }));

        expect(wrapper.text()).toContain('5');
        expect(wrapper.text()).toContain('Waiting');
    });
});

describe('comment rules page', () => {
    const page: CommentPage = {
        accountId: 'a1', name: 'Acme Bakery', platform: 'facebook', platformLabel: 'Facebook Pages', avatarUrl: null, supported: true,
        connected: true, enabled: false, owned: false, webhookState: 'none', lastEventAt: null, lastPolledAt: null, errorCode: null, privateReplies: false,
    };
    const rule: CommentRuleData = {
        id: 'r1', name: 'Price questions', enabled: true, matchMode: 'any', matchType: 'word', keywords: ['price', 'দাম'], accountId: null, postId: null,
        campaignId: null, actions: [{ type: 'reply' }], replyTemplates: ['Hi {first_name}'], hourlyCap: 20, cooldownMinutes: 60, quietStart: null,
        quietEnd: null, priority: 100, matchedCount: 3, lastMatchedAt: null, actor: { id: 'm1', name: 'Nadia Karim' }, standing: 'actor_inactive',
    };
    const props = () => ({
        rules: [rule], pages: [page], paused: false, pausedAt: null, pageHourlyCap: 60,
        options: { accounts: [{ id: 'a1', name: 'Acme Bakery', platform: 'facebook' }], posts: [], campaigns: [], members: [{ id: 'm1', name: 'Nadia Karim' }], actions: ['reply', 'hide', 'assign', 'tag', 'review'], variables: ['first_name', 'page_name'] },
        limits: { keywords: 50, keyword: 60, template: 500, templates: 5, tag: 40, rules: 50, previewText: 2000 },
    });

    it('lists pages and rules, warns about a rule whose member can no longer act and pauses everything', async () => {
        const wrapper = track(mount(CommentRules, { props: props() as never, global: { stubs } }));

        expect(wrapper.get('[data-testid="page-a1"]').text()).toContain('Turn on');
        expect(wrapper.get('[data-testid="rule-r1"]').text()).toContain('no longer active');
        expect(wrapper.get('[data-testid="rule-r1"]').text()).toContain('price, দাম');

        await wrapper.get('[data-testid="kill-switch"] button.bg-danger, [data-testid="kill-switch"] button:last-of-type').trigger('click');
        expect(inertia.router.put).toHaveBeenCalledWith('/social.comments.automation', { paused: true }, expect.anything());

        await wrapper.get('[data-testid="page-a1"] button').trigger('click');
        expect(inertia.router.post).toHaveBeenCalledWith('/social.comments.sources.enable?account=a1', {}, expect.anything());
    });

    it('saves a new rule through the dialog with only the replies it needs', async () => {
        const wrapper = track(mount(CommentRules, { props: { ...props(), rules: [] } as never, global: { stubs } }));
        const open = wrapper.findAll('button').find((button) => button.text().includes('New rule'));
        await open!.trigger('click');
        await wrapper.get('#rule-keyword').setValue('delivery');
        await wrapper.get('#rule-keyword').trigger('keydown', { key: 'Enter' });
        await wrapper.get('input[maxlength="120"]').setValue('Delivery');
        await wrapper.get('[data-testid="rule-test-box"]').element.closest('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

        const sent = inertia.submissions.at(-1)!;
        expect(sent.method).toBe('post');
        expect(sent.url).toBe('/social.comments.rules.store');
        expect(sent.data.keywords).toEqual(['delivery']);
        expect(sent.data.reply_templates).toEqual([]);
    });
});

describe('comment messages', () => {
    function files(directory: string): string[] {
        return readdirSync(directory).flatMap((name) => {
            const path = join(directory, name);

            return statSync(path).isDirectory() ? files(path) : [path];
        });
    }

    function lookup(tree: unknown, path: string): unknown {
        return path.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), tree);
    }

    it('defines every static key the comment pages use, in both languages', () => {
        const roots = [join(process.cwd(), 'resources/js/Modules/Social/Pages/Comments'), join(process.cwd(), 'resources/js/Modules/Social/Components/comments')];
        const used = new Set<string>();

        for (const root of roots) {
            for (const file of files(root).filter((path) => path.endsWith('.vue') || path.endsWith('.ts'))) {
                for (const match of readFileSync(file, 'utf8').matchAll(/\bt\(\s*'((?:socialComments|social)\.[\w.]+)'/g)) {
                    used.add(match[1]!);
                }
            }
        }

        expect(used.size).toBeGreaterThan(80);
        expect([...used].filter((key) => typeof lookup(en, key) !== 'string' || typeof lookup(bn, key) !== 'string')).toEqual([]);
    });

    it('defines every dynamic key family', () => {
        const families: Record<string, string[]> = {
            'socialComments.status': ['open', 'unread', 'needs_attention', 'replied', 'hidden', 'mine', 'handled', 'deleted'],
            'socialComments.rule.action': ['reply', 'hide', 'assign', 'tag', 'review', 'private_reply'],
            'socialComments.rule.actionHint': ['reply', 'hide', 'assign', 'tag', 'review', 'private_reply'],
            'socialComments.rules.standing': ['module_not_entitled', 'actor_missing', 'actor_inactive', 'permission_missing', 'permission_scope'],
            'socialComments.pages.state': ['unsupported', 'reconnect', 'off', 'live', 'polling'],
            'socialComments.pages.hint': ['unsupported', 'reconnect', 'off', 'live', 'polling'],
            'socialComments.rule.note': ['no_template', 'one_of_templates'],
        };

        for (const [family, keys] of Object.entries(families)) {
            for (const key of keys) {
                expect(typeof lookup(en, `${family}.${key}`), `en ${family}.${key}`).toBe('string');
                expect(typeof lookup(bn, `${family}.${key}`), `bn ${family}.${key}`).toBe('string');
            }
        }
    });

    it('has the same keys in English and Bengali, with the same placeholders', () => {
        const flatten = (tree: unknown, prefix = ''): Record<string, string> =>
            Object.entries(tree as Record<string, unknown>).reduce<Record<string, string>>((all, [key, value]) => (
                typeof value === 'string' ? { ...all, [`${prefix}${key}`]: value } : { ...all, ...flatten(value, `${prefix}${key}.`) }
            ), {});
        const english = flatten((en as Record<string, unknown>).socialComments);
        const bengali = flatten((bn as Record<string, unknown>).socialComments);
        const holes = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();

        expect(Object.keys(bengali).sort()).toEqual(Object.keys(english).sort());

        for (const [key, text] of Object.entries(english)) {
            expect(holes(bengali[key]!), key).toEqual(holes(text));
        }
    });
});
