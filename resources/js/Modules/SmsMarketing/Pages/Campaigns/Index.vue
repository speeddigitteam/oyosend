<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Plus, Smartphone } from 'lucide-vue-next';
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
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChannelNotice from '../../Components/ChannelNotice.vue';
import SmsBadge from '../../Components/SmsBadge.vue';
import type { CampaignRow, CampaignStatus, Paginated, Standing } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaigns: Paginated<CampaignRow>;
    filters: { status: string | null; q: string | null };
    statuses: CampaignStatus[];
    standing: Standing;
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status ?? 'all');
const search = ref(props.filters.q ?? '');
const filtered = computed(() => status.value !== 'all' || search.value.trim() !== '');

const statusOptions = computed(() => [
    { value: 'all', label: t('smsMarketing.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`smsMarketing.status.campaign.${value}`) })),
]);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('smsMarketing.fields.campaign') },
    { key: 'status', label: t('smsMarketing.fields.status') },
    { key: 'progress', label: t('smsMarketing.fields.progress'), hideable: true },
    { key: 'when', label: t('smsMarketing.fields.when'), hideable: true },
    { key: 'owner', label: t('smsMarketing.fields.owner'), hideable: true, hiddenByDefault: true },
]);

const allowance = computed(() => {
    const { limit, used } = props.standing.plan;

    return limit === null
        ? t('smsMarketing.standing.unlimited', { used: format.number(used) })
        : t('smsMarketing.standing.of', { used: format.number(used), limit: format.number(limit) });
});

function apply(): void {
    router.get(
        route('sms_marketing.campaigns.index'),
        { status: status.value === 'all' ? undefined : status.value, q: search.value.trim() || undefined },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    status.value = 'all';
    search.value = '';
    apply();
}

function holdText(row: CampaignRow): string | null {
    return row.status === 'sending' && row.holdReason ? t(`smsMarketing.hold.${row.holdReason}`) : null;
}
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="t('smsMarketing.campaigns.title')" :description="t('smsMarketing.campaigns.description')">
        <template #actions>
            <Button v-if="canCreate" variant="primary" :href="route('sms_marketing.campaigns.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.campaigns.add') }}
            </Button>
        </template>
    </PageHeader>

    <ChannelNotice class="mb-3" :channel="standing.channel" />

    <div class="mb-3 grid gap-3 sm:grid-cols-3">
        <StatTile :label="t('smsMarketing.standing.segmentsMonth')" :value="allowance" />
        <StatTile
            :label="t('smsMarketing.standing.dailyCap')"
            :value="t('smsMarketing.standing.of', { used: format.number(standing.dailyCap.used), limit: format.number(standing.dailyCap.cap) })"
        />
        <StatTile
            :label="t('smsMarketing.standing.credit')"
            :value="standing.credit.reported && standing.credit.balance !== null ? format.number(standing.credit.balance) : t('smsMarketing.standing.notReported')"
            :hint="t('smsMarketing.standing.creditHint')"
        />
    </div>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('smsMarketing.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('smsMarketing.fields.status')" class="w-full sm:w-44" />
            <Button type="submit" size="sm">{{ t('smsMarketing.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('smsMarketing.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="campaigns.data" row-key="id" :caption="t('smsMarketing.campaigns.caption')" storage-key="sms-marketing-campaigns">
            <template #cell-name="{ row }">
                <Link :href="route('sms_marketing.campaigns.show', { campaign: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
            </template>
            <template #cell-status="{ row }">
                <SmsBadge kind="campaign" :value="row.status" />
                <span v-if="holdText(row)" class="mt-1 block text-xs text-warn">{{ holdText(row) }}</span>
            </template>
            <template #cell-progress="{ row }">
                <span v-if="row.stats.recipients === 0" class="text-ink-subtle">{{ t('smsMarketing.common.dash') }}</span>
                <span v-else class="tabular text-[0.8125rem] text-ink-muted">
                    {{
                        t('smsMarketing.campaigns.progress', {
                            recipients: format.number(row.stats.recipients),
                            sent: format.number(row.stats.accepted),
                            failed: format.number(row.stats.failed),
                            blocked: format.number(row.stats.blocked),
                        })
                    }}
                </span>
            </template>
            <template #cell-when="{ row }">
                <span class="tabular text-ink-muted">
                    {{ format.date(row.scheduledFor ?? row.startedAt ?? row.createdAt, 'datetime') }}
                </span>
            </template>
            <template #cell-owner="{ row }"><span class="text-ink-muted">{{ row.owner?.name ?? t('smsMarketing.common.dash') }}</span></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('smsMarketing.common.noMatches') : t('smsMarketing.campaigns.empty')"
                    :description="filtered ? t('smsMarketing.common.noMatchesBody') : t('smsMarketing.campaigns.emptyBody')"
                    compact
                >
                    <template #icon><Smartphone /></template>
                    <Button v-if="canCreate && !filtered" size="sm" variant="primary" :href="route('sms_marketing.campaigns.create')">
                        {{ t('smsMarketing.campaigns.add') }}
                    </Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="campaigns" />
    </Card>
</template>
