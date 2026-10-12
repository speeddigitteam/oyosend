<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { MailOpen, Plus } from 'lucide-vue-next';
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
import EmailBadge from '../../Components/EmailBadge.vue';
import { useEmailLabels } from '../../Components/useEmailLabels';
import type { CampaignStatus, CampaignSummary, ListOption, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaigns: Paginated<CampaignSummary>;
    filters: { status: string | null; list_id: string | null; q: string | null };
    statuses: CampaignStatus[];
    lists: ListOption[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const labels = useEmailLabels();

const status = ref(props.filters.status ?? 'all');
const list = ref(props.filters.list_id ?? '');
const search = ref(props.filters.q ?? '');

const statusOptions = computed(() => [
    { value: 'all', label: t('emailMarketing.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`emailMarketing.status.campaign.${value}`) })),
]);
const listOptions = computed(() => [{ value: '', label: t('emailMarketing.common.all') }, ...props.lists.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => status.value !== 'all' || list.value !== '' || search.value.trim() !== '');

const columns = computed<Column[]>(() => [
    { key: 'campaign', label: t('emailMarketing.fields.campaign') },
    { key: 'status', label: t('emailMarketing.fields.status') },
    { key: 'list', label: t('emailMarketing.fields.list'), hideable: true },
    { key: 'audience', label: t('emailMarketing.fields.audience'), hideable: true, align: 'right' },
    { key: 'sent', label: t('emailMarketing.fields.sent'), hideable: true, align: 'right' },
    { key: 'when', label: t('emailMarketing.fields.when'), hideable: true },
]);

function apply(): void {
    router.get(
        route('email_marketing.campaigns.index'),
        { status: status.value === 'all' ? undefined : status.value, list_id: list.value || undefined, q: search.value.trim() || undefined },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    status.value = 'all';
    list.value = '';
    search.value = '';
    apply();
}

function when(campaign: CampaignSummary): string | null {
    return campaign.finishedAt ?? campaign.scheduledFor ?? campaign.startedAt;
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.campaigns.title')" :description="t('emailMarketing.campaigns.description')">
        <template #actions>
            <Button v-if="canCreate" variant="primary" :href="route('email_marketing.campaigns.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('emailMarketing.campaigns.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('emailMarketing.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('emailMarketing.fields.status')" class="w-full sm:w-44" />
            <Select v-model="list" :options="listOptions" :label="t('emailMarketing.fields.list')" class="w-full sm:w-48" />
            <Button type="submit" size="sm">{{ t('emailMarketing.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('emailMarketing.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="campaigns.data" row-key="id" :caption="t('emailMarketing.campaigns.caption')" storage-key="email-marketing-campaigns">
            <template #cell-campaign="{ row }">
                <Link :href="route('email_marketing.campaigns.show', { campaign: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
                <span class="block max-w-md truncate text-xs text-ink-subtle">{{ row.subject }}</span>
            </template>
            <template #cell-status="{ row }">
                <EmailBadge kind="campaign" :value="row.status" />
                <span v-if="row.status === 'paused' && row.pausedReason" class="mt-1 block text-xs text-ink-subtle">
                    {{ labels.pause(row.pausedReason) }}
                </span>
            </template>
            <template #cell-list="{ row }">
                <span class="text-ink-muted">{{ row.listName ?? t('emailMarketing.common.dash') }}</span>
                <span v-if="row.segmentName" class="block text-xs text-ink-subtle">{{ row.segmentName }}</span>
            </template>
            <template #cell-audience="{ row }">
                <span class="tabular text-ink-muted">{{ row.audienceTotal > 0 ? format.number(row.audienceTotal) : t('emailMarketing.common.dash') }}</span>
            </template>
            <template #cell-sent="{ row }">
                <span class="tabular text-ink-muted">{{ row.counts.planned > 0 ? format.number(row.counts.sent) : t('emailMarketing.common.dash') }}</span>
            </template>
            <template #cell-when="{ row }">
                <span class="tabular text-ink-muted">{{ when(row) ? format.date(when(row), 'datetime') : t('emailMarketing.common.dash') }}</span>
            </template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('emailMarketing.common.noMatches') : t('emailMarketing.campaigns.empty')"
                    :description="filtered ? t('emailMarketing.common.noMatchesBody') : t('emailMarketing.campaigns.emptyBody')"
                    compact
                >
                    <template #icon><MailOpen /></template>
                    <Button v-if="canCreate && !filtered" size="sm" variant="primary" :href="route('email_marketing.campaigns.create')">
                        {{ t('emailMarketing.campaigns.add') }}
                    </Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="campaigns" />
    </Card>
</template>
