import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

/*
 * The blog in the Super Admin panel (docs/WEBSITE.md, ADR 0037): the editor's Markdown helpers, and the list and
 * editor pages mounted with the real catalogue and components. Inertia and the route table are stood in for.
 */
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { abilities: ['platform.website.view', 'platform.website.manage'] as string[] },
            errors: {},
        },
        url: '/website/blog',
    },
    sent: [] as Array<{ method: string; url: string; data: Record<string, unknown> }>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()),
    }),
    Head: defineComponent({ name: 'InertiaHeadStub', setup: () => () => null }),
    usePage: () => inertia.page,
    router: { on: () => () => {}, get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        const send = (method: string) => (url: string) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, JSON.parse(JSON.stringify(form[key]))]));

            inertia.sent.push({ method, url, data: data as Record<string, unknown> });
        };
        const form: Record<string, unknown> = reactive({
            ...JSON.parse(JSON.stringify(initial)),
            errors: {},
            processing: false,
            isDirty: false,
            put: send('put'),
            post: send('post'),
            clearErrors: () => {},
            setError: () => {},
            data: () => JSON.parse(JSON.stringify(initial)),
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

import { setLocale } from '@/Core/i18n';
import { insertBlock, isWritten, emptyWords, prefixLines, readingMinutes, slugify, wordCount, wrap } from '@/Platform/Components/Website/blogEditor';
import Edit from '@/Platform/Pages/Website/Blog/Edit.vue';
import Index from '@/Platform/Pages/Website/Blog/Index.vue';

const RAW_KEY = /platformWebsite\.[a-z]/;

afterEach(() => {
    setLocale('en');
    inertia.sent = [];
});

describe('the editor helpers', () => {
    it('makes an address the way the server does', () => {
        expect(slugify('Paying people on time: a 2026 guide!')).toBe('paying-people-on-time-a-2026-guide');
        expect(slugify('Café   déjà vu')).toBe('cafe-deja-vu');
        expect(slugify('সময়মতো বেতন')).toBe('');
    });

    it('counts words and minutes without the Markdown marks', () => {
        expect(wordCount('## Plan the month\n\n**Start** [early](https://example.com)')).toBe(5);
        expect(readingMinutes('word '.repeat(221), 'en')).toBe(2);
        expect(readingMinutes('শব্দ '.repeat(161), 'bn')).toBe(2);
        expect(readingMinutes('', 'en')).toBe(1);
    });

    it('wraps the selection, or a sample when nothing is selected', () => {
        expect(wrap('make this bold', 5, 9, '**', '**', 'x')).toEqual({ text: 'make **this** bold', start: 7, end: 11 });
        expect(wrap('', 0, 0, '*', '*', 'emphasis')).toEqual({ text: '*emphasis*', start: 1, end: 9 });
    });

    it('starts each selected line with a prefix, replacing the one it had', () => {
        expect(prefixLines('one\ntwo', 0, 7, (index) => `${index + 1}. `, 'x').text).toBe('1. one\n2. two');
        expect(prefixLines('- item', 0, 0, '## ', 'x').text).toBe('## item');
    });

    it('puts a block on lines of its own', () => {
        expect(insertBlock('before', 6, '| a |').text).toBe('before\n\n| a |');
        expect(insertBlock('', 0, '![x](/blog/media/a.png)').text).toBe('![x](/blog/media/a.png)');
    });

    it('knows when a language has been written in', () => {
        expect(isWritten(emptyWords())).toBe(false);
        expect(isWritten({ ...emptyWords(), faq: [{ q: 'Why?', a: '' }] })).toBe(true);
    });
});

const post = {
    id: 'p1',
    slug: 'paying-people-on-time',
    title: 'Paying people on time',
    locales: ['en', 'bn'] as Array<'en' | 'bn'>,
    kind: 'guide',
    state: 'published' as const,
    status: 'published' as const,
    featured: true,
    cover: null,
    coverFile: null,
    publishedAt: '2026-10-14T10:00:00+00:00',
    publishAt: '2026-10-14T16:00',
    updatedAt: '2026-10-14T10:00:00+00:00',
    editor: 'Nusrat',
    url: 'http://localhost/blog/paying-people-on-time',
    apps: ['payroll'],
    authorName: null,
    translations: {
        en: { title: 'Paying people on time', summary: 'Short.', body: '## Plan\n\nText.', cover_alt: null, meta_title: null, meta_description: null, faq: [] },
        bn: null,
    },
    urls: { en: 'http://localhost/blog/paying-people-on-time' },
};

const editorProps = {
    apps: [
        { module: 'payroll', name: 'Payroll', category: 'people' },
        { module: 'crm', name: 'CRM & Sales', category: 'sales' },
    ],
    kinds: ['guide', 'update', 'insight'],
    timezone: 'Asia/Dhaka',
    blogUrl: 'http://localhost/blog',
    limits: { apps: 6, questions: 10 },
};

describe('the pages', () => {
    it('lists articles with their state and languages, in both languages', () => {
        for (const locale of ['en', 'bn'] as const) {
            setLocale(locale);
            const wrapper = mount(Index, {
                props: {
                    posts: { data: [post], current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1, prev_page_url: null, next_page_url: null },
                    counts: { published: 1, scheduled: 0, draft: 0 },
                    filters: { status: 'all', search: '' },
                    blogUrl: 'http://localhost/blog',
                },
            });

            expect(wrapper.text()).toContain('Paying people on time');
            expect(wrapper.text()).not.toMatch(RAW_KEY);
            expect(wrapper.find('[data-testid="post-p1"]').exists()).toBe(true);
        }
    });

    it('saves the article it was given, both languages included', async () => {
        const wrapper = mount(Edit, { props: { ...editorProps, post } });

        expect(wrapper.text()).not.toMatch(RAW_KEY);
        expect(wrapper.find('[data-testid="snippet"]').text()).toContain('localhost › blog › paying-people-on-time');

        await wrapper.find('form').trigger('submit');

        expect(inertia.sent).toHaveLength(1);
        expect(inertia.sent[0]!.method).toBe('put');
        expect(inertia.sent[0]!.url).toBe('/platform.website.blog.update?post=p1');
        expect(inertia.sent[0]!.data).toMatchObject({
            slug: 'paying-people-on-time',
            status: 'published',
            featured: true,
            apps: ['payroll'],
            translations: { en: { title: 'Paying people on time' }, bn: { title: '' } },
        });
    });

    it('starts a new article empty, in English, with no raw keys in Bengali either', () => {
        setLocale('bn');
        const wrapper = mount(Edit, { props: { ...editorProps, post: null } });

        expect(wrapper.text()).not.toMatch(RAW_KEY);
        expect(wrapper.find('input[name="title"]').element.getAttribute('maxlength')).toBe('160');
    });
});
