<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Archive, ArrowLeft, Eye, EyeOff, History, Pencil } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ArticleFormDialog from '../../Components/ArticleFormDialog.vue';
import SupportNav from '../../Components/SupportNav.vue';
import type { ArticleStatus, Option } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    article: {
        id: string;
        title: string;
        slug: string;
        summary: string | null;
        status: ArticleStatus;
        category: Option | null;
        categoryId: string | null;
        version: number;
        publishedAt: string | null;
        updatedAt: string;
        body: string;
        author: string | null;
    };
    versions: { version: number; title: string; editedBy: string | null; createdAt: string; isCurrent: boolean }[];
    categories: Option[];
    abilities: { edit: boolean; publish: boolean; archive: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const editOpen = ref(false);
const pending = ref(false);

function post(name: string, params: Record<string, string | number> = {}): void {
    pending.value = true;
    router.post(route(name, { article: props.article.id, ...params }), {}, { preserveScroll: true, onFinish: () => (pending.value = false) });
}
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="article.title" :description="article.summary ?? undefined">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('support.kb.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('support.kb.back') }}
            </Button>
            <Button v-if="abilities.edit" size="sm" @click="editOpen = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('support.common.edit') }}
            </Button>
            <Button v-if="abilities.publish && article.status !== 'published'" size="sm" variant="primary" :loading="pending" @click="post('support.kb.articles.publish')">
                <template #icon><Eye class="size-4" aria-hidden="true" /></template>
                {{ t('support.kb.publish') }}
            </Button>
            <Button v-if="abilities.publish && article.status !== 'draft'" size="sm" :loading="pending" @click="post('support.kb.articles.unpublish')">
                <template #icon><EyeOff class="size-4" aria-hidden="true" /></template>
                {{ t('support.kb.unpublish') }}
            </Button>
            <Button v-if="abilities.archive && article.status !== 'archived'" size="sm" :loading="pending" @click="post('support.kb.articles.archive')">
                <template #icon><Archive class="size-4" aria-hidden="true" /></template>
                {{ t('support.kb.archive') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="kb" />

    <div class="grid gap-3 lg:grid-cols-3">
        <Card class="lg:col-span-2">
            <div class="mb-4 flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                <Badge :tone="article.status === 'published' ? 'ok' : article.status === 'draft' ? 'warn' : 'neutral'">{{ t(`support.articleStatus.${article.status}`) }}</Badge>
                <span v-if="article.category">{{ article.category.name }}</span>
                <span v-if="article.author">{{ t('support.kb.by', { name: article.author }) }}</span>
                <span class="tabular">{{ t('support.kb.versionNumber', { version: format.number(article.version) }) }}</span>
                <span class="tabular">{{ t('support.kb.updated', { time: format.date(article.updatedAt, 'datetime') }) }}</span>
            </div>
            <div class="text-sm leading-relaxed whitespace-pre-wrap wrap-anywhere text-ink">{{ article.body }}</div>
        </Card>

        <Card v-if="abilities.edit" :title="t('support.kb.versions')">
            <ul class="grid gap-2">
                <li v-for="version in versions" :key="version.version" class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-hover px-3 py-2 text-sm">
                    <span class="min-w-0">
                        <span class="tabular font-medium text-ink">{{ t('support.kb.versionNumber', { version: format.number(version.version) }) }}</span>
                        <Badge v-if="version.isCurrent" tone="brand" class="ms-2">{{ t('support.kb.current') }}</Badge>
                        <span class="tabular block text-xs text-ink-subtle">
                            {{ version.editedBy ?? t('support.timeline.system') }} · {{ format.date(version.createdAt, 'datetime') }}
                        </span>
                    </span>
                    <Button v-if="!version.isCurrent" size="sm" variant="ghost" :disabled="pending" @click="post('support.kb.articles.restore', { version: version.version })">
                        <template #icon><History class="size-4" aria-hidden="true" /></template>
                        {{ t('support.kb.restore') }}
                    </Button>
                </li>
            </ul>
        </Card>
    </div>

    <ArticleFormDialog
        v-if="abilities.edit"
        v-model:open="editOpen"
        :article="{ id: article.id, title: article.title, summary: article.summary, body: article.body, categoryId: article.categoryId }"
        :categories="categories"
    />
</template>
