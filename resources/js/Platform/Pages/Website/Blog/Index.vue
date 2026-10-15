<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ExternalLink, ImageOff, NotebookPen, Plus, Star } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import WebsiteNav from '../../../Components/Website/WebsiteNav.vue';

defineOptions({ layout: PlatformLayout });

type Filter = 'all' | 'published' | 'scheduled' | 'draft';
type State = 'published' | 'scheduled' | 'draft';

interface Post {
    id: string;
    slug: string;
    title: string;
    locales: Array<'en' | 'bn'>;
    kind: string;
    state: State;
    featured: boolean;
    cover: string | null;
    publishedAt: string | null;
    updatedAt: string;
    editor: string | null;
    url: string | null;
}

const props = defineProps<{
    posts: Paginated<Post>;
    counts: Record<State, number>;
    filters: { status: Filter; search: string };
    blogUrl: string;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref<Filter>(props.filters.status);
const search = ref(props.filters.search);

const TONES: Record<State, BadgeTone> = { published: 'ok', scheduled: 'info', draft: 'neutral' };

const statuses = computed(() => [
    { value: 'all' as const, label: t('platformWebsite.blog.filter.all') },
    { value: 'published' as const, label: `${t('platformWebsite.blog.filter.published')} (${format.number(props.counts.published)})` },
    { value: 'scheduled' as const, label: `${t('platformWebsite.blog.filter.scheduled')} (${format.number(props.counts.scheduled)})` },
    { value: 'draft' as const, label: `${t('platformWebsite.blog.filter.draft')} (${format.number(props.counts.draft)})` },
]);

function apply(): void {
    router.get(
        route('platform.website.blog.index'),
        { status: status.value === 'all' ? undefined : status.value, search: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function when(post: Post): string {
    if (post.state === 'scheduled' && post.publishedAt) {
        return t('platformWebsite.blog.scheduledFor', { when: format.date(post.publishedAt, 'datetime') });
    }

    if (post.state === 'published' && post.publishedAt) {
        return t('platformWebsite.blog.publishedOn', { when: format.date(post.publishedAt) });
    }

    return post.editor
        ? t('platformWebsite.blog.editedBy', { name: post.editor, when: format.relative(post.updatedAt) })
        : t('platformWebsite.blog.edited', { when: format.relative(post.updatedAt) });
}
</script>

<template>
    <PageHeader :eyebrow="t('platformWebsite.eyebrow')" :title="t('platformWebsite.blog.title')" :description="t('platformWebsite.blog.description')">
        <template #actions>
            <!-- The website is not part of this app: a full page load, in a new tab. -->
            <Button :href="blogUrl" native target="_blank" rel="noopener">{{ t('platformWebsite.open') }}</Button>
            <Button variant="primary" :href="route('platform.website.blog.create')">
                <Plus class="size-4" aria-hidden="true" />
                {{ t('platformWebsite.blog.new') }}
            </Button>
        </template>
    </PageHeader>

    <WebsiteNav current="blog" />

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <SegmentedControl v-model="status" :options="statuses" :label="t('platformWebsite.blog.filter.label')" @update:model-value="apply" />
        <form class="flex min-w-60 flex-1 items-end gap-2 sm:max-w-md sm:flex-none" @submit.prevent="apply">
            <Input
                v-model="search"
                type="search"
                class="min-w-0 flex-1"
                :label="t('platformWebsite.blog.search')"
                :placeholder="t('platformWebsite.blog.searchPlaceholder')"
                maxlength="100"
            />
            <Button type="submit">{{ t('platformWebsite.blog.search') }}</Button>
        </form>
    </div>

    <Card v-if="posts.data.length === 0">
        <EmptyState :title="t('platformWebsite.blog.empty')" :description="t('platformWebsite.blog.emptyBody')" compact>
            <template #icon><NotebookPen /></template>
            <Button variant="primary" :href="route('platform.website.blog.create')">{{ t('platformWebsite.blog.new') }}</Button>
        </EmptyState>
    </Card>

    <Card v-else flush>
        <ul class="divide-y divide-line" data-testid="posts">
            <li v-for="post in posts.data" :key="post.id" class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3.5 sm:flex-nowrap sm:px-5" :data-testid="`post-${post.id}`">
                <div class="grid aspect-[16/10] w-20 shrink-0 place-items-center overflow-hidden rounded-control border border-line bg-brand-soft text-brand-text">
                    <img v-if="post.cover" :src="post.cover" alt="" class="size-full object-cover" loading="lazy" />
                    <ImageOff v-else class="size-5 opacity-70" aria-hidden="true" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Link :href="route('platform.website.blog.edit', { post: post.id })" class="text-[0.9375rem] font-semibold wrap-break-word text-ink hover:underline">
                            {{ post.title }}
                        </Link>
                        <Star v-if="post.featured" class="size-3.5 fill-current text-accent" :aria-label="t('platformWebsite.blog.featured')" />
                    </div>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.8125rem] text-ink-muted">
                        <span class="font-mono text-xs text-ink-subtle">/blog/{{ post.slug }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ t(`platformWebsite.blog.kinds.${post.kind}`) }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ when(post) }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <Badge v-for="locale in post.locales" :key="locale" tone="neutral" :title="t(`platformWebsite.blog.languages.${locale}`)">
                        {{ t(`platformWebsite.blog.languageShort.${locale}`) }}
                    </Badge>
                    <Badge :tone="TONES[post.state]" dot>{{ t(`platformWebsite.blog.state.${post.state}`) }}</Badge>
                </div>

                <div class="flex items-center gap-1">
                    <Button v-if="post.url" variant="ghost" size="sm" icon :href="post.url" native target="_blank" rel="noopener" :aria-label="t('platformWebsite.blog.view')" :title="t('platformWebsite.blog.view')">
                        <ExternalLink class="size-4" aria-hidden="true" />
                    </Button>
                    <Button size="sm" :href="route('platform.website.blog.edit', { post: post.id })">{{ t('platformWebsite.blog.edit') }}</Button>
                </div>
            </li>
        </ul>
    </Card>

    <Pagination v-if="posts.last_page > 1" class="mt-4" :meta="posts" />
</template>
