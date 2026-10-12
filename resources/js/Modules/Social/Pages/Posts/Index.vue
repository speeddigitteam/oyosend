<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Download, Plus, Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SocialBadge from '../../Components/SocialBadge.vue';
import type { AccountOption, NamedRef, Paginated, PostStatus, PostSummary } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    posts: Paginated<PostSummary>;
    filters: { status: string | null; campaign_id: string | null; category_id: string | null; account_id: string | null; q: string | null };
    statuses: PostStatus[];
    campaigns: NamedRef[];
    categories: NamedRef[];
    accounts: AccountOption[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status ?? 'all');
const campaign = ref(props.filters.campaign_id ?? '');
const category = ref(props.filters.category_id ?? '');
const account = ref(props.filters.account_id ?? '');
const search = ref(props.filters.q ?? '');

const statusOptions = computed(() => [
    { value: 'all', label: t('social.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`social.status.post.${value}`) })),
]);
const campaignOptions = computed(() => [{ value: '', label: t('social.common.all') }, ...props.campaigns.map((item) => ({ value: item.id, label: item.name }))]);
const categoryOptions = computed(() => [{ value: '', label: t('social.common.all') }, ...props.categories.map((item) => ({ value: item.id, label: item.name }))]);
const accountOptions = computed(() => [{ value: '', label: t('social.common.all') }, ...props.accounts.map((item) => ({ value: item.id, label: `${item.name} (${item.platformLabel})` }))]);
const filtered = computed(() => status.value !== 'all' || campaign.value !== '' || category.value !== '' || account.value !== '' || search.value.trim() !== '');

const columns = computed<Column[]>(() => [
    { key: 'post', label: t('social.fields.post') },
    { key: 'status', label: t('social.fields.status') },
    { key: 'accounts', label: t('social.fields.accounts'), hideable: true },
    { key: 'at', label: t('social.fields.when'), hideable: true },
    { key: 'campaign', label: t('social.fields.campaign'), hideable: true, hiddenByDefault: true },
    { key: 'category', label: t('social.fields.category'), hideable: true, hiddenByDefault: true },
]);

function apply(): void {
    router.get(route('social.posts.index'), {
        status: status.value === 'all' ? undefined : status.value,
        campaign_id: campaign.value || undefined,
        category_id: category.value || undefined,
        account_id: account.value || undefined,
        q: search.value.trim() || undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'all';
    campaign.value = '';
    category.value = '';
    account.value = '';
    search.value = '';
    apply();
}

function title(post: PostSummary): string {
    return post.title ?? (post.excerpt !== '' ? post.excerpt : t('social.common.untitled'));
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.posts.title')" :description="t('social.posts.description')">
        <template #actions>
            <a
                :href="route('social.posts.export')"
                class="inline-flex h-[2.375rem] items-center justify-center gap-2 rounded-control border border-line-strong bg-control px-4 text-sm font-medium whitespace-nowrap text-ink hover:bg-control-hover"
            >
                <Download class="size-4" aria-hidden="true" />
                {{ t('social.posts.export') }}
            </a>
            <Button v-if="canCreate" variant="primary" :href="route('social.posts.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('social.posts.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('social.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('social.fields.status')" class="w-full sm:w-44" />
            <Select v-model="campaign" :options="campaignOptions" :label="t('social.fields.campaign')" class="w-full sm:w-44" />
            <Select v-model="category" :options="categoryOptions" :label="t('social.fields.category')" class="w-full sm:w-44" />
            <Select v-model="account" :options="accountOptions" :label="t('social.fields.account')" class="w-full sm:w-48" />
            <Button type="submit" size="sm">{{ t('social.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('social.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="posts.data" row-key="id" :caption="t('social.posts.caption')" storage-key="social-posts">
            <template #cell-post="{ row }">
                <Link :href="route('social.posts.show', { post: row.id })" class="text-ink hover:text-brand-text">{{ title(row) }}</Link>
                <span v-if="row.title && row.excerpt" class="block max-w-md truncate text-xs text-ink-subtle">{{ row.excerpt }}</span>
            </template>
            <template #cell-status="{ row }"><SocialBadge kind="post" :value="row.status" /></template>
            <template #cell-accounts="{ row }">
                <span v-if="row.targets.length === 0" class="text-ink-subtle">{{ t('social.common.dash') }}</span>
                <ul v-else class="grid gap-0.5 text-[0.8125rem] text-ink-muted">
                    <li v-for="target in row.targets" :key="target.id">{{ target.accountName }}</li>
                </ul>
            </template>
            <template #cell-at="{ row }">
                <span class="tabular text-ink-muted">{{ row.at ? format.date(row.at, 'datetime') : t('social.common.dash') }}</span>
            </template>
            <template #cell-campaign="{ row }"><span class="text-ink-muted">{{ row.campaign?.name ?? t('social.common.dash') }}</span></template>
            <template #cell-category="{ row }"><span class="text-ink-muted">{{ row.category?.name ?? t('social.common.dash') }}</span></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('social.common.noMatches') : t('social.posts.empty')"
                    :description="filtered ? t('social.common.noMatchesBody') : t('social.posts.emptyBody')"
                    compact
                >
                    <template #icon><Send /></template>
                    <Button v-if="canCreate && !filtered" size="sm" variant="primary" :href="route('social.posts.create')">{{ t('social.posts.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="posts" />
    </Card>
</template>
