<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Hash, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { TabsContent, TabsRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CampaignDialog from '../../Components/CampaignDialog.vue';
import CategoryDialog from '../../Components/CategoryDialog.vue';
import HashtagSetDialog from '../../Components/HashtagSetDialog.vue';
import SocialBadge from '../../Components/SocialBadge.vue';
import SocialTabList from '../../Components/SocialTabList.vue';
import { displayTag } from '../../Components/socialFormat';
import type { CampaignRow, CampaignStatus, CategoryRow, HashtagSetRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    campaigns: CampaignRow[];
    categories: CategoryRow[];
    hashtagSets: HashtagSetRow[];
    campaignStatuses: CampaignStatus[];
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const tab = ref('campaigns');
const tabs = computed(() => ['campaigns', 'categories', 'hashtags'].map((value) => ({ value, label: t(`social.library.tabs.${value}`) })));

const campaignColumns = computed<Column[]>(() => [
    { key: 'name', label: t('social.fields.name') },
    { key: 'status', label: t('social.fields.status') },
    { key: 'dates', label: t('social.library.campaigns.dates'), hideable: true },
    { key: 'posts', label: t('social.library.posts'), align: 'right' },
    { key: 'actions', label: t('social.common.actions'), align: 'right' },
]);
const categoryColumns = computed<Column[]>(() => [
    { key: 'name', label: t('social.fields.name') },
    { key: 'posts', label: t('social.library.posts'), align: 'right' },
    { key: 'actions', label: t('social.common.actions'), align: 'right' },
]);
const hashtagColumns = computed<Column[]>(() => [
    { key: 'name', label: t('social.fields.name') },
    { key: 'tags', label: t('social.library.hashtags.tags') },
    { key: 'actions', label: t('social.common.actions'), align: 'right' },
]);

const campaignOpen = ref(false);
const editingCampaign = ref<CampaignRow | null>(null);
const categoryOpen = ref(false);
const editingCategory = ref<CategoryRow | null>(null);
const hashtagOpen = ref(false);
const editingSet = ref<HashtagSetRow | null>(null);

type Removal = { kind: 'campaign' | 'category' | 'hashtags'; id: string; name: string };
const removal = ref<Removal | null>(null);
const removeOpen = ref(false);
const busy = ref(false);

function openCampaign(row: CampaignRow | null): void {
    editingCampaign.value = row;
    campaignOpen.value = true;
}

function openCategory(row: CategoryRow | null): void {
    editingCategory.value = row;
    categoryOpen.value = true;
}

function openSet(row: HashtagSetRow | null): void {
    editingSet.value = row;
    hashtagOpen.value = true;
}

function askRemove(kind: Removal['kind'], row: { id: string; name: string }): void {
    removal.value = { kind, id: row.id, name: row.name };
    removeOpen.value = true;
}

function remove(): void {
    const item = removal.value;

    if (!item) {
        return;
    }

    const url = item.kind === 'campaign'
        ? route('social.library.campaigns.destroy', { campaign: item.id })
        : item.kind === 'category'
            ? route('social.library.categories.destroy', { category: item.id })
            : route('social.library.hashtag-sets.destroy', { set: item.id });

    busy.value = true;
    router.delete(url, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            removeOpen.value = false;
        },
    });
}

function dates(row: CampaignRow): string {
    if (row.startsOn && row.endsOn) {
        return t('social.library.campaigns.range', { from: format.date(row.startsOn), to: format.date(row.endsOn) });
    }

    if (row.startsOn) {
        return t('social.library.campaigns.from', { date: format.date(row.startsOn) });
    }

    return row.endsOn ? t('social.library.campaigns.until', { date: format.date(row.endsOn) }) : t('social.common.dash');
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.library.title')" :description="t('social.library.description')" />

    <TabsRoot v-model="tab" class="min-w-0">
        <SocialTabList :label="t('social.library.tabsLabel')" :items="tabs" />

        <TabsContent value="campaigns">
            <Card flush>
                <DataTable :columns="campaignColumns" :rows="campaigns" row-key="id" :caption="t('social.library.campaigns.caption')">
                    <template #cell-name="{ row }">
                        <span class="text-ink">{{ row.name }}</span>
                        <span v-if="row.description" class="block max-w-md truncate text-xs text-ink-subtle">{{ row.description }}</span>
                    </template>
                    <template #cell-status="{ row }"><SocialBadge kind="campaign" :value="row.status" /></template>
                    <template #cell-dates="{ row }"><span class="text-ink-muted">{{ dates(row) }}</span></template>
                    <template #cell-posts="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.posts) }}</span></template>
                    <template #cell-actions="{ row }">
                        <div v-if="canManage" class="flex justify-end gap-1">
                            <Button size="sm" variant="ghost" @click="openCampaign(row)">
                                <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.common.edit') }}
                            </Button>
                            <Button size="sm" variant="ghost" @click="askRemove('campaign', row)">
                                <template #icon><Trash2 class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.common.delete') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty>
                        <EmptyState :title="t('social.library.campaigns.empty')" :description="t('social.library.campaigns.emptyBody')" compact>
                            <template #icon><Hash /></template>
                            <Button v-if="canManage" size="sm" variant="primary" @click="openCampaign(null)">{{ t('social.library.campaigns.add') }}</Button>
                        </EmptyState>
                    </template>
                </DataTable>
                <div v-if="canManage && campaigns.length > 0" class="border-t border-line px-5 py-3">
                    <Button size="sm" @click="openCampaign(null)">
                        <template #icon><Plus class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.library.campaigns.add') }}
                    </Button>
                </div>
            </Card>
        </TabsContent>

        <TabsContent value="categories">
            <Card flush>
                <DataTable :columns="categoryColumns" :rows="categories" row-key="id" :caption="t('social.library.categories.caption')">
                    <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                    <template #cell-posts="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.posts) }}</span></template>
                    <template #cell-actions="{ row }">
                        <div v-if="canManage" class="flex justify-end gap-1">
                            <Button size="sm" variant="ghost" @click="openCategory(row)">
                                <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.common.edit') }}
                            </Button>
                            <Button size="sm" variant="ghost" @click="askRemove('category', row)">
                                <template #icon><Trash2 class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.common.delete') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty>
                        <EmptyState :title="t('social.library.categories.empty')" :description="t('social.library.categories.emptyBody')" compact>
                            <template #icon><Hash /></template>
                            <Button v-if="canManage" size="sm" variant="primary" @click="openCategory(null)">{{ t('social.library.categories.add') }}</Button>
                        </EmptyState>
                    </template>
                </DataTable>
                <div v-if="canManage && categories.length > 0" class="border-t border-line px-5 py-3">
                    <Button size="sm" @click="openCategory(null)">
                        <template #icon><Plus class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.library.categories.add') }}
                    </Button>
                </div>
            </Card>
        </TabsContent>

        <TabsContent value="hashtags">
            <Card flush>
                <DataTable :columns="hashtagColumns" :rows="hashtagSets" row-key="id" :caption="t('social.library.hashtags.caption')">
                    <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                    <template #cell-tags="{ row }">
                        <span class="block max-w-lg break-words text-ink-muted">{{ row.tags.map(displayTag).join(' ') }}</span>
                    </template>
                    <template #cell-actions="{ row }">
                        <div v-if="canManage" class="flex justify-end gap-1">
                            <Button size="sm" variant="ghost" @click="openSet(row)">
                                <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.common.edit') }}
                            </Button>
                            <Button size="sm" variant="ghost" @click="askRemove('hashtags', row)">
                                <template #icon><Trash2 class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.common.delete') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty>
                        <EmptyState :title="t('social.library.hashtags.empty')" :description="t('social.library.hashtags.emptyBody')" compact>
                            <template #icon><Hash /></template>
                            <Button v-if="canManage" size="sm" variant="primary" @click="openSet(null)">{{ t('social.library.hashtags.add') }}</Button>
                        </EmptyState>
                    </template>
                </DataTable>
                <div v-if="canManage && hashtagSets.length > 0" class="border-t border-line px-5 py-3">
                    <Button size="sm" @click="openSet(null)">
                        <template #icon><Plus class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.library.hashtags.add') }}
                    </Button>
                </div>
            </Card>
        </TabsContent>
    </TabsRoot>

    <CampaignDialog v-model:open="campaignOpen" :campaign="editingCampaign" :statuses="campaignStatuses" />
    <CategoryDialog v-model:open="categoryOpen" :category="editingCategory" />
    <HashtagSetDialog v-model:open="hashtagOpen" :set="editingSet" />
    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('social.library.deleteTitle')"
        :description="t(`social.library.deleteBody.${removal?.kind ?? 'campaign'}`, { name: removal?.name ?? '' })"
        :confirm-label="t('social.common.delete')"
        :loading="busy"
        @confirm="remove"
    />
</template>
