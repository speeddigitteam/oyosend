<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Bold,
    ExternalLink,
    Eye,
    Heading2,
    Heading3,
    ImagePlus,
    Italic,
    Link2,
    List,
    ListOrdered,
    Plus,
    Quote,
    Table,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import {
    emptyWords,
    type Edit,
    insertBlock,
    isWritten,
    type Locale,
    prefixLines,
    readingMinutes,
    slugify,
    wordCount,
    type Words,
    wrap,
} from '../../../Components/Website/blogEditor';
import WebsiteNav from '../../../Components/Website/WebsiteNav.vue';

defineOptions({ layout: PlatformLayout });

type State = 'published' | 'scheduled' | 'draft';

interface ServerWords {
    title: string;
    summary: string;
    body: string;
    cover_alt: string | null;
    meta_title: string | null;
    meta_description: string | null;
    faq: Array<{ q: string; a: string }>;
}

interface Post {
    id: string;
    slug: string;
    kind: string;
    state: State;
    status: 'draft' | 'published';
    featured: boolean;
    cover: string | null;
    coverFile: string | null;
    publishedAt: string | null;
    publishAt: string | null;
    updatedAt: string;
    apps: string[];
    authorName: string | null;
    translations: Record<Locale, ServerWords | null>;
    urls: Partial<Record<Locale, string>>;
}

interface Upload {
    file: string;
    url: string;
    width: number;
    height: number;
}

const props = defineProps<{
    post: Post | null;
    apps: Array<{ module: string; name: string; category: string }>;
    kinds: string[];
    timezone: string;
    blogUrl: string;
    limits: { apps: number; questions: number };
}>();

const { t } = useI18n();
const format = useFormat();
const LOCALES: Locale[] = ['en', 'bn'];

function wordsFrom(server: ServerWords | null | undefined): Words {
    return server
        ? {
              title: server.title,
              summary: server.summary,
              body: server.body,
              cover_alt: server.cover_alt ?? '',
              meta_title: server.meta_title ?? '',
              meta_description: server.meta_description ?? '',
              faq: server.faq.map((item) => ({ ...item })),
          }
        : emptyWords();
}

const form = useForm({
    slug: props.post?.slug ?? '',
    kind: props.post?.kind ?? 'guide',
    status: props.post?.status ?? 'draft',
    publish_at: props.post?.publishAt ?? '',
    featured: props.post?.featured ?? false,
    apps: [...(props.post?.apps ?? [])],
    author_name: props.post?.authorName ?? '',
    cover: props.post?.coverFile ?? null,
    translations: { en: wordsFrom(props.post?.translations.en), bn: wordsFrom(props.post?.translations.bn) } as Record<Locale, Words>,
});

const language = ref<Locale>(props.post && !props.post.translations.en && props.post.translations.bn ? 'bn' : 'en');
const words = computed(() => form.translations[language.value]);
const coverUrl = ref<string | null>(props.post?.cover ?? null);
const isLive = computed(() => props.post?.state === 'published');

function error(path: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[path];
}

function hasErrors(locale: Locale): boolean {
    return Object.keys(form.errors).some((key) => key.startsWith(`translations.${locale}`));
}

const kindOptions = computed(() => props.kinds.map((kind) => ({ value: kind, label: t(`platformWebsite.blog.kinds.${kind}`) })));

/** Whether the chosen time is still ahead: the main button then schedules rather than publishes. */
const scheduling = computed(() => form.publish_at !== '' && new Date(form.publish_at).getTime() > Date.now());

const primaryLabel = computed(() => {
    if (scheduling.value) {
        return t('platformWebsite.blog.editor.publish.schedule');
    }

    return isLive.value ? t('platformWebsite.blog.editor.publish.update') : t('platformWebsite.blog.editor.publish.publish');
});

function save(status: 'draft' | 'published'): void {
    form.status = status;

    const options = { preserveScroll: true };

    if (props.post) {
        form.put(route('platform.website.blog.update', { post: props.post.id }), options);
    } else {
        form.post(route('platform.website.blog.store'), options);
    }
}

// The address.
function slugFromTitle(): void {
    form.slug = slugify(form.translations.en.title || form.translations.bn.title);
}

// Writing: the toolbar works on the article's own textarea, keeping the selection where the change put it.
const bodyBox = ref<HTMLElement | null>(null);

function textarea(): HTMLTextAreaElement | null {
    return bodyBox.value?.querySelector('textarea') ?? null;
}

async function apply(change: (text: string, start: number, end: number) => Edit): Promise<void> {
    const element = textarea();
    const text = words.value.body;
    const start = element?.selectionStart ?? text.length;
    const end = element?.selectionEnd ?? text.length;
    const edit = change(text, start, end);

    words.value.body = edit.text;
    await nextTick();
    element?.focus();
    element?.setSelectionRange(edit.start, edit.end);
}

const sample = (key: string): string => t(`platformWebsite.blog.editor.sample.${key}`);

const tools = computed(() => [
    { key: 'h2', icon: Heading2, run: () => apply((text, s, e) => prefixLines(text, s, e, '## ', sample('heading'))) },
    { key: 'h3', icon: Heading3, run: () => apply((text, s, e) => prefixLines(text, s, e, '### ', sample('heading'))) },
    { key: 'bold', icon: Bold, run: () => apply((text, s, e) => wrap(text, s, e, '**', '**', sample('bold'))) },
    { key: 'italic', icon: Italic, run: () => apply((text, s, e) => wrap(text, s, e, '*', '*', sample('italic'))) },
    { key: 'link', icon: Link2, run: () => apply((text, s, e) => wrap(text, s, e, '[', '](https://)', sample('link'))) },
    { key: 'bullets', icon: List, run: () => apply((text, s, e) => prefixLines(text, s, e, '- ', sample('item'))) },
    { key: 'numbers', icon: ListOrdered, run: () => apply((text, s, e) => prefixLines(text, s, e, (index) => `${index + 1}. `, sample('item'))) },
    { key: 'quote', icon: Quote, run: () => apply((text, s, e) => prefixLines(text, s, e, '> ', sample('quote'))) },
    {
        key: 'table',
        icon: Table,
        run: () =>
            apply((text, s) =>
                insertBlock(
                    text,
                    s,
                    `| ${sample('column')} | ${sample('column')} |\n| --- | --- |\n| ${sample('cell')} | ${sample('cell')} |`,
                ),
            ),
    },
]);

const wordTotal = computed(() => wordCount(words.value.body));
const minutes = computed(() => readingMinutes(words.value.body, language.value));

// Pictures: uploaded on their own, straight away; the server redraws and keeps them.
const uploading = ref<'cover' | 'body' | null>(null);
const uploadError = ref<string | null>(null);
const coverInput = ref<HTMLInputElement | null>(null);
const bodyImageInput = ref<HTMLInputElement | null>(null);

function headers(json: boolean): Record<string, string> {
    const result: Record<string, string> = { Accept: json ? 'application/json' : 'text/html', 'X-Requested-With': 'XMLHttpRequest' };
    const match = typeof document === 'undefined' ? null : document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    if (match) {
        result['X-XSRF-TOKEN'] = decodeURIComponent(match[1]!);
    }

    return result;
}

async function upload(file: File, target: 'cover' | 'body'): Promise<Upload | null> {
    const data = new FormData();
    data.append('file', file);
    uploading.value = target;
    uploadError.value = null;

    try {
        const response = await fetch(route('platform.website.blog.media'), { method: 'POST', credentials: 'same-origin', headers: headers(true), body: data });
        const payload = (await response.json().catch(() => null)) as (Upload & { errors?: Record<string, string[]> }) | null;

        if (!response.ok || !payload) {
            uploadError.value = payload?.errors?.file?.[0] ?? t('platformWebsite.blog.editor.uploadFailed');

            return null;
        }

        return payload;
    } catch {
        uploadError.value = t('platformWebsite.blog.editor.uploadFailed');

        return null;
    } finally {
        uploading.value = null;
    }
}

async function chooseCover(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    const kept = await upload(file, 'cover');

    if (kept) {
        form.cover = kept.file;
        coverUrl.value = kept.url;
    }
}

function removeCover(): void {
    form.cover = null;
    coverUrl.value = null;
}

async function chooseBodyImage(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    const kept = await upload(file, 'body');

    if (kept) {
        await apply((text, s) => insertBlock(text, s, `![${sample('picture')}](${kept.url})`));
    }
}

// Questions.
function addQuestion(): void {
    if (words.value.faq.length < props.limits.questions) {
        words.value.faq.push({ q: '', a: '' });
    }
}

// Apps.
function toggleApp(module: string, on: boolean): void {
    form.apps = on ? [...new Set([...form.apps, module])] : form.apps.filter((item) => item !== module);
}

// How it shows in search results.
const snippet = computed(() => {
    const host = (() => {
        try {
            return new URL(props.blogUrl).host;
        } catch {
            return '';
        }
    })();
    const description = words.value.meta_description || words.value.summary;

    return {
        title: words.value.meta_title || words.value.title,
        address: [host, ...(language.value === 'bn' ? ['bn'] : []), 'blog', form.slug || '…'].join(' › '),
        description: description.length > 160 ? `${description.slice(0, 157)}…` : description,
    };
});

// The preview: the website draws the article from what is in the editor; shown in a frame that may run nothing.
const previewLanguage = ref<Locale | null>(null);
const previewHtml = ref('');
const previewState = ref<'idle' | 'loading' | 'failed'>('idle');
const previewBox = ref<HTMLElement | null>(null);

async function preview(locale: Locale): Promise<void> {
    previewLanguage.value = locale;
    previewState.value = 'loading';
    form.clearErrors();

    try {
        const response = await fetch(route('platform.website.blog.preview'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { ...headers(false), 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...form.data(), id: props.post?.id ?? null, locale }),
        });

        if (!response.ok) {
            const payload = (await response.json().catch(() => null)) as { errors?: Record<string, string[]> } | null;

            for (const [key, messages] of Object.entries(payload?.errors ?? {})) {
                form.setError(key as never, messages[0] ?? '');
            }

            previewState.value = 'failed';

            return;
        }

        previewHtml.value = await response.text();
        previewState.value = 'idle';
        await nextTick();
        previewBox.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch {
        previewState.value = 'failed';
    }
}

function closePreview(): void {
    previewLanguage.value = null;
    previewHtml.value = '';
}

// Deleting.
const deleting = ref(false);
const removing = ref(false);

function destroy(): void {
    if (!props.post) {
        return;
    }

    removing.value = true;
    router.delete(route('platform.website.blog.destroy', { post: props.post.id }), {
        onFinish: () => {
            removing.value = false;
            deleting.value = false;
        },
    });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('platformWebsite.eyebrow')"
        :title="post ? t('platformWebsite.blog.editor.editTitle') : t('platformWebsite.blog.editor.createTitle')"
        :description="t('platformWebsite.blog.editor.languageHint')"
    >
        <template #actions>
            <Badge v-if="form.isDirty" tone="warn" dot>{{ t('platformWebsite.blog.editor.unsaved') }}</Badge>
            <Button :href="route('platform.website.blog.index')">{{ t('platformWebsite.blog.editor.back') }}</Button>
        </template>
    </PageHeader>

    <WebsiteNav current="blog" />

    <!-- The preview, as the website draws it. -->
    <div v-if="previewLanguage" ref="previewBox" class="mb-3 scroll-mt-20">
        <Card :title="t('platformWebsite.blog.editor.preview.title', { language: t(`platformWebsite.blog.languages.${previewLanguage}`) })" :description="t('platformWebsite.blog.editor.preview.description')">
            <template #actions>
                <Button variant="ghost" size="sm" icon :aria-label="t('common.actions.close')" @click="closePreview"><X class="size-4" aria-hidden="true" /></Button>
            </template>
            <div v-if="previewState === 'loading'" class="flex h-40 items-center justify-center gap-2 text-sm text-ink-muted" role="status">
                <Spinner class="size-4" />{{ t('platformWebsite.blog.editor.preview.loading') }}
            </div>
            <p v-else-if="previewState === 'failed'" class="text-sm text-danger" role="alert">{{ t('platformWebsite.blog.editor.preview.failed') }}</p>
            <!-- No script, no forms, no same-origin access: the page is only looked at. -->
            <iframe
                v-else
                :srcdoc="previewHtml"
                sandbox=""
                :title="t('platformWebsite.blog.editor.preview.frame')"
                class="h-[75vh] w-full rounded-card border border-line bg-canvas"
                data-testid="preview"
            />
        </Card>
    </div>

    <form novalidate class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_22rem]" @submit.prevent="save('published')">
        <div class="grid min-w-0 content-start gap-3">
            <Card>
                <!-- One version per language; a dot marks the one with a problem. -->
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div role="tablist" :aria-label="t('platformWebsite.blog.editor.language')" class="flex gap-1 rounded-control bg-hover p-1">
                        <button
                            v-for="locale in LOCALES"
                            :key="locale"
                            type="button"
                            role="tab"
                            :aria-selected="language === locale"
                            :class="[
                                'inline-flex h-8 items-center gap-2 rounded-[calc(var(--radius-control)-2px)] px-3 text-[0.8125rem] font-medium transition-colors',
                                language === locale ? 'bg-active text-ink shadow-sm' : 'text-ink-muted hover:text-ink',
                            ]"
                            @click="language = locale"
                        >
                            {{ t(`platformWebsite.blog.languages.${locale}`) }}
                            <span
                                :class="['size-1.5 rounded-full', hasErrors(locale) ? 'bg-danger' : isWritten(form.translations[locale]) ? 'bg-ok' : 'bg-line-strong']"
                                aria-hidden="true"
                            />
                            <span class="sr-only">{{ isWritten(form.translations[locale]) ? t('platformWebsite.blog.editor.written') : t('platformWebsite.blog.editor.notWritten') }}</span>
                        </button>
                    </div>
                    <Button size="sm" :loading="previewState === 'loading' && previewLanguage === language" @click="preview(language)">
                        <Eye class="size-4" aria-hidden="true" />
                        {{ t('platformWebsite.blog.editor.preview.button') }}
                    </Button>
                </div>

                <div :key="language" class="grid gap-4" role="tabpanel" :lang="language">
                    <Input
                        v-model="words.title"
                        name="title"
                        :label="t('platformWebsite.blog.editor.title')"
                        :error="error(`translations.${language}.title`)"
                        maxlength="160"
                        control-class="text-[1.0625rem] font-semibold"
                    />
                    <Textarea
                        v-model="words.summary"
                        name="summary"
                        :rows="3"
                        :label="t('platformWebsite.blog.editor.summary')"
                        :hint="t('platformWebsite.blog.editor.summaryHint')"
                        :error="error(`translations.${language}.summary`)"
                        maxlength="400"
                    >
                        <template #aside>
                            <span class="tabular text-xs text-ink-subtle">{{ t('platformWebsite.blog.editor.seo.count', { count: format.number(words.summary.length), max: format.number(400) }) }}</span>
                        </template>
                    </Textarea>

                    <div ref="bodyBox" class="grid gap-2">
                        <div role="toolbar" :aria-label="t('platformWebsite.blog.editor.toolbar.label')" class="flex flex-wrap items-center gap-0.5 rounded-control border border-line bg-control-sunken p-1">
                            <Button
                                v-for="tool in tools"
                                :key="tool.key"
                                variant="ghost"
                                size="sm"
                                icon
                                :aria-label="t(`platformWebsite.blog.editor.toolbar.${tool.key}`)"
                                :title="t(`platformWebsite.blog.editor.toolbar.${tool.key}`)"
                                @click="tool.run"
                            >
                                <component :is="tool.icon" class="size-4" aria-hidden="true" />
                            </Button>
                            <span class="mx-1 h-5 w-px bg-line" aria-hidden="true" />
                            <Button
                                variant="ghost"
                                size="sm"
                                icon
                                :loading="uploading === 'body'"
                                :aria-label="t('platformWebsite.blog.editor.toolbar.image')"
                                :title="t('platformWebsite.blog.editor.toolbar.image')"
                                @click="bodyImageInput?.click()"
                            >
                                <ImagePlus class="size-4" aria-hidden="true" />
                            </Button>
                            <input ref="bodyImageInput" type="file" class="sr-only" tabindex="-1" accept="image/png,image/jpeg,image/webp" :aria-label="t('platformWebsite.blog.editor.toolbar.image')" @change="chooseBodyImage" />
                            <span class="tabular ml-auto px-2 text-xs text-ink-subtle">
                                {{ t('platformWebsite.blog.editor.words', { count: format.number(wordTotal) }) }} ·
                                {{ t('platformWebsite.blog.editor.minutes', { count: format.number(minutes) }) }}
                            </span>
                        </div>
                        <Textarea
                            v-model="words.body"
                            name="body"
                            :rows="22"
                            :label="t('platformWebsite.blog.editor.body')"
                            :hint="t('platformWebsite.blog.editor.bodyHint')"
                            :error="error(`translations.${language}.body`)"
                            control-class="leading-relaxed"
                            maxlength="100000"
                        />
                        <p v-if="uploadError" class="text-[0.8125rem] text-danger" role="alert">{{ uploadError }}</p>
                        <details class="rounded-control border border-line px-3 py-2 text-[0.8125rem] text-ink-muted">
                            <summary class="font-medium text-ink">{{ t('platformWebsite.blog.editor.help.title') }}</summary>
                            <ul class="mt-2 grid list-disc gap-1 pl-5">
                                <li v-for="item in ['headings', 'emphasis', 'links', 'lists', 'pictures', 'html']" :key="item">{{ t(`platformWebsite.blog.editor.help.${item}`) }}</li>
                            </ul>
                        </details>
                    </div>
                </div>
            </Card>

            <Card :title="t('platformWebsite.blog.editor.faq.title')" :description="t('platformWebsite.blog.editor.faq.description')">
                <p v-if="words.faq.length === 0" class="text-sm text-ink-subtle">{{ t('platformWebsite.blog.editor.faq.empty') }}</p>
                <ol v-else class="grid gap-3" :lang="language">
                    <li v-for="(item, index) in words.faq" :key="index" class="grid gap-3 rounded-card border border-line p-3">
                        <div class="flex items-start gap-2">
                            <Input v-model="item.q" class="flex-1" :label="t('platformWebsite.blog.editor.faq.question', { n: format.number(index + 1) })" maxlength="200" :error="error(`translations.${language}.faq.${index}`)" />
                            <Button
                                class="mt-6"
                                variant="ghost"
                                size="sm"
                                icon
                                :aria-label="t('platformWebsite.blog.editor.faq.remove', { n: format.number(index + 1) })"
                                @click="words.faq.splice(index, 1)"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                            </Button>
                        </div>
                        <Textarea v-model="item.a" :rows="2" :label="t('platformWebsite.blog.editor.faq.answer')" maxlength="1000" />
                    </li>
                </ol>
                <Button class="mt-3" size="sm" :disabled="words.faq.length >= limits.questions" @click="addQuestion">
                    <Plus class="size-4" aria-hidden="true" />{{ t('platformWebsite.blog.editor.faq.add') }}
                </Button>
            </Card>

            <Card :title="t('platformWebsite.blog.editor.seo.title')" :description="t('platformWebsite.blog.editor.seo.description')">
                <div class="grid gap-4 lg:grid-cols-2" :lang="language">
                    <div class="grid content-start gap-4">
                        <Input v-model="words.meta_title" :label="t('platformWebsite.blog.editor.seo.metaTitle')" :error="error(`translations.${language}.meta_title`)" maxlength="70" optional>
                            <template #aside>
                                <span class="tabular text-xs text-ink-subtle">{{ t('platformWebsite.blog.editor.seo.count', { count: format.number(words.meta_title.length), max: format.number(70) }) }}</span>
                            </template>
                        </Input>
                        <Textarea v-model="words.meta_description" :rows="3" :label="t('platformWebsite.blog.editor.seo.metaDescription')" :error="error(`translations.${language}.meta_description`)" maxlength="170" optional>
                            <template #aside>
                                <span class="tabular text-xs text-ink-subtle">{{ t('platformWebsite.blog.editor.seo.count', { count: format.number(words.meta_description.length), max: format.number(170) }) }}</span>
                            </template>
                        </Textarea>
                    </div>
                    <figure class="glass-well rounded-card p-4" data-testid="snippet">
                        <figcaption class="eyebrow">{{ t('platformWebsite.blog.editor.seo.preview') }}</figcaption>
                        <p class="mt-3 truncate text-xs text-ink-subtle">{{ snippet.address }}</p>
                        <p class="mt-1 line-clamp-2 text-[1.0625rem] leading-snug font-medium text-info">{{ snippet.title || '…' }}</p>
                        <p class="mt-1 line-clamp-3 text-[0.8125rem] leading-relaxed text-ink-muted">{{ snippet.description || '…' }}</p>
                    </figure>
                </div>
            </Card>
        </div>

        <aside class="grid content-start gap-3">
            <Card :title="t('platformWebsite.blog.editor.publish.title')">
                <template #actions>
                    <Badge v-if="post" :tone="post.state === 'published' ? 'ok' : post.state === 'scheduled' ? 'info' : 'neutral'" dot>
                        {{ t(`platformWebsite.blog.state.${post.state}`) }}
                    </Badge>
                </template>
                <div class="grid gap-4">
                    <p class="text-[0.8125rem] text-ink-muted">
                        <template v-if="post?.state === 'published' && post.publishedAt">{{ t('platformWebsite.blog.editor.publish.liveNote', { when: format.date(post.publishedAt, 'datetime') }) }}</template>
                        <template v-else-if="post?.state === 'scheduled' && post.publishedAt">{{ t('platformWebsite.blog.editor.publish.scheduledNote', { when: format.date(post.publishedAt, 'datetime') }) }}</template>
                        <template v-else>{{ t('platformWebsite.blog.editor.publish.draftNote') }}</template>
                    </p>
                    <Input
                        v-model="form.publish_at"
                        type="datetime-local"
                        :label="t('platformWebsite.blog.editor.publish.at')"
                        :hint="t('platformWebsite.blog.editor.publish.atHint', { zone: timezone })"
                        :error="form.errors.publish_at"
                        optional
                    />
                    <Checkbox v-model="form.featured" :label="t('platformWebsite.blog.editor.publish.featured')" :description="t('platformWebsite.blog.editor.publish.featuredHint')" />
                    <p v-if="error('translations')" class="text-[0.8125rem] text-danger" role="alert">{{ error('translations') }}</p>
                    <div class="grid gap-2">
                        <Button type="submit" variant="primary" block :loading="form.processing && form.status === 'published'" :disabled="form.processing">{{ primaryLabel }}</Button>
                        <Button v-if="!isLive && post?.state !== 'scheduled'" block :loading="form.processing && form.status === 'draft'" :disabled="form.processing" @click="save('draft')">
                            {{ t('platformWebsite.blog.editor.publish.saveDraft') }}
                        </Button>
                        <Button v-else variant="ghost" block :disabled="form.processing" @click="save('draft')">{{ t('platformWebsite.blog.editor.publish.unpublish') }}</Button>
                    </div>
                    <ul v-if="post && isLive" class="grid gap-1 border-t border-line pt-3">
                        <li v-for="(url, locale) in post.urls" :key="locale">
                            <a :href="url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-[0.8125rem] font-medium text-brand-text hover:underline">
                                <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('platformWebsite.blog.view') }} · {{ t(`platformWebsite.blog.languages.${locale}`) }}
                            </a>
                        </li>
                    </ul>
                </div>
            </Card>

            <Card :title="t('platformWebsite.blog.editor.address.title')">
                <div class="grid gap-3">
                    <Input
                        v-model="form.slug"
                        :label="t('platformWebsite.blog.editor.address.slug')"
                        :hint="isLive ? t('platformWebsite.blog.editor.address.liveWarning') : t('platformWebsite.blog.editor.address.slugHint')"
                        :error="form.errors.slug"
                        maxlength="120"
                        mono
                        optional
                    >
                        <template #leading><span class="font-mono text-xs text-ink-subtle">/blog/</span></template>
                    </Input>
                    <Button size="sm" :disabled="!form.translations.en.title && !form.translations.bn.title" @click="slugFromTitle">{{ t('platformWebsite.blog.editor.address.fromTitle') }}</Button>
                    <Select v-model="form.kind" :options="kindOptions" :label="t('platformWebsite.blog.editor.kind')" :error="form.errors.kind" />
                    <Input v-model="form.author_name" :label="t('platformWebsite.blog.editor.author.label')" :hint="t('platformWebsite.blog.editor.author.hint')" :error="form.errors.author_name" maxlength="120" optional />
                </div>
            </Card>

            <Card :title="t('platformWebsite.blog.editor.cover.title')" :description="t('platformWebsite.blog.editor.cover.description')">
                <div class="grid gap-3">
                    <div class="grid aspect-[2/1] place-items-center overflow-hidden rounded-control border border-line bg-brand-soft">
                        <img v-if="coverUrl" :src="coverUrl" alt="" class="size-full object-cover" />
                        <ImagePlus v-else class="size-7 text-brand-text opacity-70" aria-hidden="true" />
                    </div>
                    <input ref="coverInput" type="file" class="sr-only" tabindex="-1" accept="image/png,image/jpeg,image/webp" :aria-label="t('platformWebsite.blog.editor.cover.upload')" @change="chooseCover" />
                    <p v-if="form.errors.cover" class="text-[0.8125rem] text-danger" role="alert">{{ form.errors.cover }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" :loading="uploading === 'cover'" @click="coverInput?.click()">
                            {{ coverUrl ? t('platformWebsite.blog.editor.cover.replace') : t('platformWebsite.blog.editor.cover.upload') }}
                        </Button>
                        <Button v-if="coverUrl" size="sm" variant="ghost" @click="removeCover">{{ t('platformWebsite.blog.editor.cover.remove') }}</Button>
                    </div>
                    <Input
                        v-if="coverUrl"
                        v-model="words.cover_alt"
                        :lang="language"
                        :label="t('platformWebsite.blog.editor.cover.alt')"
                        :hint="t('platformWebsite.blog.editor.cover.altHint', { language: t(`platformWebsite.blog.languages.${language}`) })"
                        :error="error(`translations.${language}.cover_alt`)"
                        maxlength="200"
                        optional
                    />
                </div>
            </Card>

            <Card :title="t('platformWebsite.blog.editor.apps.title')" :description="t('platformWebsite.blog.editor.apps.description', { max: format.number(limits.apps) })">
                <ul class="grid max-h-72 gap-1 overflow-y-auto pr-1">
                    <li v-for="app in apps" :key="app.module">
                        <Checkbox
                            :model-value="form.apps.includes(app.module)"
                            :label="app.name"
                            :disabled="!form.apps.includes(app.module) && form.apps.length >= limits.apps"
                            @update:model-value="(on: boolean) => toggleApp(app.module, on)"
                        />
                    </li>
                </ul>
                <p v-if="form.errors.apps" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ form.errors.apps }}</p>
            </Card>

            <Button v-if="post" variant="ghost" class="justify-self-start text-danger" @click="deleting = true">
                <Trash2 class="size-4" aria-hidden="true" />{{ t('platformWebsite.blog.editor.delete.button') }}
            </Button>
        </aside>
    </form>

    <ConfirmDialog
        :open="deleting"
        tone="danger"
        :title="t('platformWebsite.blog.editor.delete.title')"
        :description="t('platformWebsite.blog.editor.delete.body')"
        :confirm-label="t('platformWebsite.blog.editor.delete.confirm')"
        :loading="removing"
        @update:open="(open) => (deleting = open)"
        @confirm="destroy"
    />
</template>
