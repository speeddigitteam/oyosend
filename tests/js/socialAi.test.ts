import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['social.posts.view', 'social.posts.create', 'social.posts.ai_assist'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    usePage: () => inertia.page,
    router: { get: vi.fn() },
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
import { mergeTags, requestSuggestion, scoreOf, share, type AiSuggestion } from '@/Modules/Social/Components/aiAssist';
import AiAssistPanel from '@/Modules/Social/Components/AiAssistPanel.vue';
import ExtraHashtags from '@/Modules/Social/Components/ExtraHashtags.vue';
import SentimentSummary from '@/Modules/Social/Components/SentimentSummary.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };

function suggestion(overrides: Partial<AiSuggestion> = {}): AiSuggestion {
    return {
        operation: 'caption', state: 'ok', message: '', retryable: false, aiGenerated: true, text: 'Eid sale starts today!',
        hashtags: [], maxLength: 280, replayed: false, settingsUrl: null, ...overrides,
    };
}

function respond(payload: Partial<AiSuggestion>, status = 200): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

/** Mounts the panel with real v-model bindings held in refs, the way the composer uses it. */
function mountPanel(initial: { body?: string; extra?: string[]; marked?: boolean; title?: string } = {}) {
    const body = ref(initial.body ?? '');
    const extra = ref<string[]>(initial.extra ?? []);
    const marked = ref(initial.marked ?? false);
    const Host = defineComponent({
        setup: () => () => h(AiAssistPanel, {
            body: body.value,
            'onUpdate:body': (value: string) => { body.value = value; },
            extraTags: extra.value,
            'onUpdate:extraTags': (value: string[]) => { extra.value = value; },
            marked: marked.value,
            'onUpdate:marked': (value: boolean) => { marked.value = value; },
            title: initial.title ?? '',
            linkUrl: 'https://example.com',
            accountIds: ['acc-1'],
        }),
    });

    return { wrapper: track(mount(Host)), body, extra, marked };
}

beforeEach(() => {
    setLocale('en');
    inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create', 'social.posts.ai_assist'];
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('aiAssist helpers', () => {
    it('merges tags case-insensitively with a leading # and a cap of 30', () => {
        expect(mergeTags(['#Eid'], ['eid', '#sale', '##Sale', ' ঈদ '])).toEqual(['#Eid', '#sale', '#ঈদ']);
        expect(mergeTags([], Array.from({ length: 40 }, (_, i) => `t${i}`))).toHaveLength(30);
        expect(mergeTags(['#a'], ['#', ''])).toEqual(['#a']);
    });

    it('computes shares and scores without dividing by zero', () => {
        expect(share(1, 4)).toBe(25);
        expect(share(0, 0)).toBe(0);
        expect(scoreOf(8000)).toBe(80);
        expect(scoreOf(-4550)).toBe(-45);
    });

    it('turns every transport failure into a state instead of throwing', async () => {
        const input = { title: '', notes: '', body: 'Hi', link_url: '', tone: 'friendly', language: 'same', count: 8, attempt: 1, account_ids: [] };

        respond({}, 422);
        expect((await requestSuggestion('/x', 'caption', input)).state).toBe('invalid');
        respond({}, 403);
        expect((await requestSuggestion('/x', 'caption', input)).state).toBe('forbidden');
        respond({}, 429);
        expect((await requestSuggestion('/x', 'caption', input)).state).toBe('refused');
        respond({}, 500);
        expect((await requestSuggestion('/x', 'caption', input)).state).toBe('error');
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        expect((await requestSuggestion('/x', 'caption', input)).retryable).toBe(true);
    });
});

describe('AiAssistPanel', () => {
    it('is hidden for a member without the AI assist permission', () => {
        inertia.page.props.auth.permissions = ['social.posts.view', 'social.posts.create'];
        const { wrapper } = mountPanel({ body: 'Hello' });

        expect(wrapper.find('[data-testid="ai-panel"]').exists()).toBe(false);
    });

    it('shows a suggestion with an AI-generated label and does not touch the post until accepted', async () => {
        const fetchMock = respond(suggestion());
        const { wrapper, body, marked } = mountPanel({ body: 'Eid sale', title: 'Eid' });

        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toBe('/social.posts.ai?operation=caption');
        const sent = JSON.parse(String(fetchMock.mock.calls[0][1].body));
        expect(sent).toMatchObject({ title: 'Eid', body: 'Eid sale', tone: 'friendly', language: 'same', account_ids: ['acc-1'], attempt: 1 });

        expect(wrapper.find('[data-testid="ai-suggestion"]').text()).toContain('AI-generated');
        expect(wrapper.find('[data-testid="ai-text"]').text()).toBe('Eid sale starts today!');
        // Suggestion only: nothing applied, nothing marked.
        expect(body.value).toBe('Eid sale');
        expect(marked.value).toBe(false);

        await wrapper.find('[data-testid="ai-accept"]').trigger('click');
        expect(body.value).toBe('Eid sale starts today!');
        expect(marked.value).toBe(true);
        expect(wrapper.find('[data-testid="ai-applied"]').exists()).toBe(true);
    });

    it('regenerates with a new attempt number and can undo an accepted text', async () => {
        const fetchMock = respond(suggestion());
        const { wrapper, body } = mountPanel({ body: 'Original text' });

        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="ai-regenerate"]').trigger('click');
        await flushPromises();

        const attempts = fetchMock.mock.calls.map((call) => JSON.parse(String(call[1].body)).attempt);
        expect(attempts).toEqual([1, 2]);

        await wrapper.find('[data-testid="ai-accept"]').trigger('click');
        expect(body.value).toBe('Eid sale starts today!');
        const undo = wrapper.findAll('button').find((button) => button.text() === 'Undo');
        await undo!.trigger('click');
        expect(body.value).toBe('Original text');
    });

    it('lets the person choose which hashtags to keep and merges them without duplicates', async () => {
        respond(suggestion({ operation: 'hashtags', text: '', hashtags: ['#eid', '#sale', '#saree'] }));
        const { wrapper, extra, marked } = mountPanel({ body: 'Eid sale', extra: ['#Eid'] });

        await wrapper.findAll('[role="radio"]')[1].trigger('click');
        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();

        const boxes = wrapper.findAll('[data-testid="ai-tags"] input[type="checkbox"]');
        expect(boxes).toHaveLength(3);
        await boxes[1].setValue(false);
        await wrapper.find('[data-testid="ai-accept"]').trigger('click');

        expect(extra.value).toEqual(['#Eid', '#saree']);
        expect(marked.value).toBe(true);
    });

    it('explains each unavailable state calmly and links to settings only when given', async () => {
        respond({ ...suggestion({ state: 'not_configured', text: '' }), settingsUrl: '/ai/settings' });
        const { wrapper, body } = mountPanel({ body: 'Hello' });

        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();

        const problem = wrapper.find('[data-testid="ai-problem"]');
        expect(problem.text()).toContain('AI is not set up yet');
        expect(problem.find('[data-testid="ai-settings-link"]').attributes('href')).toBe('/ai/settings');
        expect(wrapper.find('[data-testid="ai-suggestion"]').exists()).toBe(false);
        expect(body.value).toBe('Hello');

        respond(suggestion({ state: 'limit_reached', text: '' }));
        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="ai-problem"]').text()).toContain('AI allowance used up');
        expect(wrapper.find('[data-testid="ai-settings-link"]').exists()).toBe(false);
    });

    it('offers a retry for a rate limited answer', async () => {
        respond(suggestion({ state: 'refused', retryable: true, text: '' }));
        const { wrapper } = mountPanel({ body: 'Hello' });

        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="ai-problem"]').text()).toContain('Too many requests');
        expect(wrapper.find('[data-testid="ai-problem"]').findAll('button').map((button) => button.text())).toContain('Try again');
    });

    it('needs something to work on and a target language to translate', async () => {
        const { wrapper } = mountPanel({ body: '' });

        expect(wrapper.find('[data-testid="ai-run"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="ai-needs"]').exists()).toBe(true);
    });

    it('marks a draft that already carries AI suggestions', () => {
        const { wrapper } = mountPanel({ body: 'x', marked: true });

        expect(wrapper.find('[data-testid="ai-marked"]').text()).toContain('AI-assisted draft');
    });

    it('speaks Bengali', async () => {
        setLocale('bn');
        respond(suggestion({ state: 'disabled', text: '' }));
        const { wrapper } = mountPanel({ body: 'Hello' });

        expect(wrapper.text()).toContain('এআই সহায়তা');
        await wrapper.find('[data-testid="ai-run"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="ai-problem"]').text()).toContain('এখানে এআই বন্ধ আছে');
    });
});

describe('ExtraHashtags', () => {
    it('lists the added tags and removes one on click', async () => {
        const tags = ref(['#a', '#b']);
        const wrapper = track(mount(defineComponent({
            setup: () => () => h(ExtraHashtags, { modelValue: tags.value, 'onUpdate:modelValue': (value: string[]) => { tags.value = value; } }),
        })));

        await wrapper.findAll('button')[0].trigger('click');

        expect(tags.value).toEqual(['#b']);
    });

    it('renders nothing when there are no extra tags', () => {
        const wrapper = track(mount(ExtraHashtags, { props: { modelValue: [] } }));

        expect(wrapper.find('[data-testid="extra-tags"]').exists()).toBe(false);
    });
});

describe('SentimentSummary', () => {
    const summaryPayload = {
        total: 4,
        mix: { positive: 2, neutral: 0, negative: 1, mixed: 0, unknown: 1 },
        averageScoreBasisPoints: 2000,
        accounts: [{ accountId: 'a', name: 'Main Page', platform: 'facebook', total: 4, mix: { positive: 2, neutral: 0, negative: 1, mixed: 0, unknown: 1 }, averageScoreBasisPoints: 2000 }],
        dailyCap: 200,
        usedToday: 4,
    };

    it('shows the mix as text and per page', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(summaryPayload), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = track(mount(SentimentSummary, { props: { from: '2026-11-01', to: '2026-11-30' } }));
        await flushPromises();

        expect(String(fetchMock.mock.calls[0][0])).toBe('/social.analytics.sentiment?from=2026-11-01&to=2026-11-30');
        expect(wrapper.find('[data-testid="sentiment-legend"]').text()).toContain('Positive 2 (50%)');
        expect(wrapper.find('[data-testid="sentiment-average"]').text()).toContain('Positive');
        expect(wrapper.text()).toContain('Main Page');
    });

    it('says honestly when nothing was analysed and when loading failed', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ ...summaryPayload, total: 0, accounts: [] }), { status: 200 })));
        const empty = track(mount(SentimentSummary, { props: { from: '2026-11-01', to: '2026-11-30' } }));
        await flushPromises();
        expect(empty.find('[data-testid="sentiment-empty"]').exists()).toBe(true);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 500 })));
        const failed = track(mount(SentimentSummary, { props: { from: '2026-11-01', to: '2026-11-30' } }));
        await flushPromises();
        expect(failed.find('[data-testid="sentiment-error"]').exists()).toBe(true);
    });
});

describe('socialAi locale', () => {
    const keys = (value: unknown, prefix = ''): string[] => Object.entries(value as Record<string, unknown>).flatMap(([key, child]) => (
        typeof child === 'object' && child !== null ? keys(child, `${prefix}${key}.`) : [`${prefix}${key}`]
    ));

    it('has the same keys in English and Bengali', () => {
        const english = (en as unknown as Record<string, unknown>).socialAi;
        const bengali = (bn as unknown as Record<string, unknown>).socialAi;

        expect(keys(bengali).sort()).toEqual(keys(english).sort());
    });
});
