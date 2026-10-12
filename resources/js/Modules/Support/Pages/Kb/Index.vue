<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { BookOpen, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import ArticleFormDialog from '../../Components/ArticleFormDialog.vue';
import SupportNav from '../../Components/SupportNav.vue';
import type { ArticleStatus, Option } from '../../Components/types';

defineOptions({ layout: TenantLayout });

interface ArticleRow {
    id: string;
    title: string;
    slug: string;
    summary: string | null;
    status: ArticleStatus;
    category: Option | null;
    version: number;
    publishedAt: string | null;
    updatedAt: string;
}

interface CategoryRow extends Option {
    slug: string;
    position: number;
    articleCount: number;
}

const props = defineProps<{
    articles: Paginated<ArticleRow>;
    categories: CategoryRow[];
    filters: { search: string; categoryId: string | null; status: string | null };
    statuses: ArticleStatus[];
    canManage: boolean;
    canPublish: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const draft = reactive({ search: props.filters.search, status: props.filters.status ?? '' });
const statusOptions = computed(() => [{ value: '', label: t('support.kb.anyStatus') }, ...props.statuses.map((value) => ({ value, label: t(`support.articleStatus.${value}`) }))]);
const categoryOptions = computed<Option[]>(() => props.categories.map(({ id, name }) => ({ id, name })));
const hasFilters = computed(() => props.filters.search !== '' || props.filters.categoryId !== null || props.filters.status !== null);

function visit(extra: Record<string, string | null> = {}): void {
    const query: Record<string, string> = {};
    const values: Record<string, string | null> = {
        search: draft.search,
        status: draft.status,
        category_id: props.filters.categoryId,
        ...extra,
    };

    for (const [key, value] of Object.entries(values)) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    router.get(route('support.kb.index'), query, { preserveState: true });
}

watch(() => draft.status, () => visit());

// Article creation.
const articleOpen = ref(false);

// Categories.
const categoryOpen = ref(false);
const editingCategory = ref<CategoryRow | null>(null);
const removingCategory = ref<CategoryRow | null>(null);
const removePending = ref(false);
const categoryForm = useForm({ name: '', position: '0' });

function openCategory(category: CategoryRow | null): void {
    editingCategory.value = category;
    categoryForm.defaults({ name: category?.name ?? '', position: String(category?.position ?? 0) }).reset();
    categoryForm.clearErrors();
    categoryOpen.value = true;
}

function saveCategory(): void {
    const options = { preserveScroll: true, onSuccess: () => { categoryOpen.value = false; } };

    if (editingCategory.value) {
        categoryForm.put(route('support.kb.categories.update', { category: editingCategory.value.id }), options);
    } else {
        categoryForm.post(route('support.kb.categories.store'), options);
    }
}

function removeCategory(): void {
    if (!removingCategory.value) {
        return;
    }

    removePending.value = true;
    router.delete(route('support.kb.categories.destroy', { category: removingCategory.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            removingCategory.value = null;
        },
        onFinish: () => {
            removePending.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.kb.title')" :description="t('support.kb.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="articleOpen = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.kb.newArticle') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="kb" />

    <div class="grid gap-3 lg:grid-cols-4">
        <div class="grid content-start gap-3">
            <Card :title="t('support.kb.categories')" flush>
                <template v-if="canManage" #actions>
                    <Button icon size="sm" variant="ghost" :aria-label="t('support.kb.addCategory')" @click="openCategory(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    </Button>
                </template>
                <ul class="divide-y divide-line">
                    <li>
                        <Link
                            :href="route('support.kb.index')"
                            :class="['flex items-center justify-between gap-2 px-5 py-2.5 text-sm hover:bg-hover', filters.categoryId === null ? 'font-semibold text-brand-text' : 'text-ink']"
                            :aria-current="filters.categoryId === null ? 'true' : undefined"
                        >
                            {{ t('support.kb.allArticles') }}
                        </Link>
                    </li>
                    <li v-for="category in categories" :key="category.id" class="flex items-center">
                        <Link
                            :href="route('support.kb.index', { category_id: category.id })"
                            :class="['flex min-w-0 flex-1 items-center justify-between gap-2 px-5 py-2.5 text-sm hover:bg-hover', filters.categoryId === category.id ? 'font-semibold text-brand-text' : 'text-ink']"
                            :aria-current="filters.categoryId === category.id ? 'true' : undefined"
                        >
                            <span class="wrap-anywhere">{{ category.name }}</span>
                            <span class="tabular text-xs text-ink-subtle">{{ format.number(category.articleCount) }}</span>
                        </Link>
                        <template v-if="canManage">
                            <Button icon size="sm" variant="ghost" :aria-label="t('support.kb.editCategory', { name: category.name })" @click="openCategory(category)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                            </Button>
                            <Button icon size="sm" variant="ghost" :aria-label="t('support.kb.deleteCategory', { name: category.name })" @click="removingCategory = category">
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            </Button>
                        </template>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="grid content-start gap-3 lg:col-span-3">
            <Card>
                <form role="search" class="grid gap-3 sm:grid-cols-3" @submit.prevent="visit()">
                    <Input v-model="draft.search" class="sm:col-span-2" :label="t('support.kb.search')" maxlength="100">
                        <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    </Input>
                    <Select v-if="canManage" v-model="draft.status" :options="statusOptions" :label="t('support.fields.status')" />
                    <div class="flex items-center gap-2 sm:col-span-3">
                        <Button type="submit" size="sm" variant="primary">{{ t('support.filters.apply') }}</Button>
                        <Button v-if="hasFilters" size="sm" variant="ghost" :href="route('support.kb.index')">{{ t('support.filters.clear') }}</Button>
                    </div>
                </form>
            </Card>

            <Card v-if="articles.data.length === 0" flush>
                <EmptyState :title="t(hasFilters ? 'support.kb.emptyFiltered' : 'support.kb.empty')" :description="hasFilters ? undefined : t('support.kb.emptyBody')">
                    <template #icon><BookOpen /></template>
                </EmptyState>
            </Card>
            <ul v-else class="grid gap-3" :aria-label="t('support.kb.articles')">
                <li v-for="article in articles.data" :key="article.id">
                    <Link :href="route('support.kb.articles.show', { article: article.id })" class="glass block rounded-card px-5 py-4 transition-colors hover:bg-hover">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-display text-base font-semibold text-ink wrap-anywhere">{{ article.title }}</span>
                            <Badge v-if="canManage" :tone="article.status === 'published' ? 'ok' : article.status === 'draft' ? 'warn' : 'neutral'">
                                {{ t(`support.articleStatus.${article.status}`) }}
                            </Badge>
                        </span>
                        <span v-if="article.summary" class="mt-1 block text-sm text-ink-muted wrap-anywhere">{{ article.summary }}</span>
                        <span class="tabular mt-2 block text-xs text-ink-subtle">
                            <template v-if="article.category">{{ article.category.name }} · </template>{{ t('support.kb.updated', { time: format.date(article.updatedAt, 'date') }) }}
                        </span>
                    </Link>
                </li>
            </ul>
            <Pagination :meta="articles" />
        </div>
    </div>

    <ArticleFormDialog v-if="canManage" v-model:open="articleOpen" :article="null" :categories="categoryOptions" :default-category-id="filters.categoryId" />

    <Modal v-model:open="categoryOpen" size="sm" :title="t(editingCategory ? 'support.kb.editCategoryTitle' : 'support.kb.addCategory')" :persistent="categoryForm.processing">
        <form id="support-kb-category-form" class="grid gap-4" @submit.prevent="saveCategory">
            <Input v-model="categoryForm.name" :label="t('support.fields.name')" :error="categoryForm.errors.name" required maxlength="120" />
            <Input v-model="categoryForm.position" type="number" min="0" max="1000" :label="t('support.kb.position')" :hint="t('support.kb.positionHint')" :error="categoryForm.errors.position" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="categoryForm.processing" @click="categoryOpen = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-kb-category-form" type="submit" variant="primary" :loading="categoryForm.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="removingCategory !== null"
        :title="t('support.kb.deleteCategoryTitle')"
        :description="t('support.kb.deleteCategoryBody', { name: removingCategory?.name ?? '' })"
        :confirm-label="t('support.common.delete')"
        :loading="removePending"
        @update:open="!$event && !removePending && (removingCategory = null)"
        @confirm="removeCategory"
    />
</template>
