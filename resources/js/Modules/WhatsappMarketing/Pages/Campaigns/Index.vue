<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Megaphone, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChannelBanner from '../../Components/ChannelBanner.vue';
import type { CampaignRow, ChannelInfo, Paginated } from '../../Components/types';
import { campaignStatusTone } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaigns: Paginated<CampaignRow>;
    filters: { status: string | null };
    statuses: string[];
    channel: ChannelInfo;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const statusOptions = computed(() => [
    { value: '', label: t('whatsapp.campaigns.anyStatus') },
    ...props.statuses.map((value) => ({ value, label: t(`whatsapp.campaignStatus.${value}`) })),
]);

function filter(value: string | number | null | undefined): void {
    router.get(route('whatsapp_marketing.campaigns.index'), value ? { status: String(value) } : {}, { preserveState: true, preserveScroll: true });
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.campaigns.columns.name') },
    { key: 'status', label: t('whatsapp.campaigns.columns.status') },
    { key: 'template', label: t('whatsapp.campaigns.columns.template'), hideable: true },
    { key: 'list', label: t('whatsapp.campaigns.columns.list'), hideable: true },
    { key: 'recipients', label: t('whatsapp.campaigns.columns.recipients') },
    { key: 'when', label: t('whatsapp.campaigns.columns.when'), hideable: true },
]);

function whenText(campaign: CampaignRow): string {
    if (campaign.status === 'scheduled' && campaign.scheduledAt) {
        return t('whatsapp.campaigns.scheduledFor', { when: format.date(campaign.scheduledAt, 'datetime') });
    }

    if (campaign.completedAt) {
        return t('whatsapp.campaigns.finishedAt', { when: format.date(campaign.completedAt, 'datetime') });
    }

    if (campaign.startedAt) {
        return t('whatsapp.campaigns.startedAt', { when: format.date(campaign.startedAt, 'datetime') });
    }

    return t('whatsapp.campaigns.createdAt', { when: format.date(campaign.createdAt, 'date') });
}
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.campaigns.title')" :description="t('whatsapp.campaigns.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" :href="route('whatsapp_marketing.campaigns.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.campaigns.new') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="campaigns" />
    <ChannelBanner :channel="channel" />

    <Card class="mb-3">
        <Select :model-value="filters.status ?? ''" class="max-w-xs" :options="statusOptions" :label="t('whatsapp.campaigns.status')" @update:model-value="filter" />
    </Card>

    <Card flush>
        <DataTable :columns="columns" :rows="campaigns.data" row-key="id" :caption="t('whatsapp.campaigns.caption')" storage-key="whatsapp-campaigns">
            <template #cell-name="{ row }">
                <Link :href="route('whatsapp_marketing.campaigns.show', { campaign: row.id })" class="font-medium text-ink wrap-anywhere hover:underline">{{ row.name }}</Link>
                <p v-if="row.owner" class="text-xs text-ink-subtle">{{ row.owner.name }}</p>
            </template>
            <template #cell-status="{ row }">
                <Badge :tone="campaignStatusTone(row.status)" dot>{{ t(`whatsapp.campaignStatus.${row.status}`) }}</Badge>
            </template>
            <template #cell-template="{ row }"><span class="text-ink-muted wrap-anywhere">{{ row.templateName }}</span></template>
            <template #cell-list="{ row }"><span class="text-ink-muted wrap-anywhere">{{ row.listName }}</span></template>
            <template #cell-recipients="{ row }">
                <span class="tabular text-ink">{{ row.totalRecipients > 0 ? format.number(row.totalRecipients) : t('whatsapp.common.none') }}</span>
            </template>
            <template #cell-when="{ row }"><span class="tabular text-ink-muted">{{ whenText(row) }}</span></template>
            <template #empty>
                <EmptyState :title="t(filters.status ? 'whatsapp.campaigns.emptyFiltered' : 'whatsapp.campaigns.empty')" :description="filters.status ? undefined : t('whatsapp.campaigns.emptyBody')">
                    <template #icon><Megaphone /></template>
                    <Button v-if="canManage && !filters.status" variant="primary" :href="route('whatsapp_marketing.campaigns.create')">{{ t('whatsapp.campaigns.new') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="campaigns" />
    </Card>
</template>
