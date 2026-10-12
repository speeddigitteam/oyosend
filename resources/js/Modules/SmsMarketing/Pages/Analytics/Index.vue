<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChannelNotice from '../../Components/ChannelNotice.vue';
import SmsBadge from '../../Components/SmsBadge.vue';
import type { AnalyticsReport, CampaignStats, CampaignStatus, Standing } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    report: AnalyticsReport;
    standing: Standing;
    campaigns: { id: string; name: string; status: CampaignStatus; stats: CampaignStats }[];
    cost: { segmentAmount: number | null; currency: string | null };
    range: { from: string; to: string; timezone: string };
}>();

const { t } = useI18n();
const format = useFormat();

const from = ref(props.range.from);
const to = ref(props.range.to);
const invalidRange = computed(() => from.value === '' || to.value === '' || to.value < from.value);

const dayColumns = computed<Column[]>(() => [
    { key: 'date', label: t('smsMarketing.analytics.date') },
    { key: 'sent', label: t('smsMarketing.analytics.sentNoReceipt') },
    { key: 'delivered', label: t('smsMarketing.analytics.delivered') },
    { key: 'failed', label: t('smsMarketing.analytics.failed') },
    { key: 'blocked', label: t('smsMarketing.analytics.blocked') },
    { key: 'segments', label: t('smsMarketing.fields.segments') },
]);
const campaignColumns = computed<Column[]>(() => [
    { key: 'name', label: t('smsMarketing.fields.campaign') },
    { key: 'status', label: t('smsMarketing.fields.status') },
    { key: 'recipients', label: t('smsMarketing.show.stats.recipients') },
    { key: 'accepted', label: t('smsMarketing.show.stats.accepted') },
    { key: 'delivered', label: t('smsMarketing.show.stats.delivered') },
    { key: 'failed', label: t('smsMarketing.show.stats.failed') },
    { key: 'blocked', label: t('smsMarketing.show.stats.blocked') },
]);

const planLine = computed(() => {
    const { limit, used } = props.standing.plan;

    return limit === null
        ? t('smsMarketing.standing.unlimited', { used: format.number(used) })
        : t('smsMarketing.standing.of', { used: format.number(used), limit: format.number(limit) });
});
const spent = computed(() =>
    props.cost.segmentAmount === null || props.cost.currency === null
        ? t('smsMarketing.estimate.noRate')
        : format.money(props.cost.segmentAmount * props.report.totals.segments, props.cost.currency),
);

function apply(): void {
    router.get(route('sms_marketing.analytics.index'), { from: from.value, to: to.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="t('smsMarketing.analytics.title')" :description="t('smsMarketing.analytics.description')" />

    <ChannelNotice class="mb-3" :channel="standing.channel" />

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <Input v-model="from" type="date" :label="t('smsMarketing.analytics.from')" class="w-full sm:w-44" />
            <Input v-model="to" type="date" :label="t('smsMarketing.analytics.to')" class="w-full sm:w-44" />
            <Button type="submit" size="sm" :disabled="invalidRange">{{ t('smsMarketing.common.apply') }}</Button>
            <span class="text-xs text-ink-subtle">{{ t('smsMarketing.analytics.timezone', { timezone: range.timezone }) }}</span>
        </form>
    </Card>

    <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile :label="t('smsMarketing.analytics.handed')" :value="format.number(report.totals.handed)" />
        <StatTile :label="t('smsMarketing.analytics.delivered')" :value="format.number(report.totals.delivered)" />
        <StatTile
            :label="t('smsMarketing.analytics.deliveryRate')"
            :value="report.deliveryRate === null ? t('smsMarketing.standing.notReported') : `${format.number(report.deliveryRate)}%`"
            :hint="t('smsMarketing.analytics.deliveryRateHint')"
        />
        <StatTile :label="t('smsMarketing.analytics.failed')" :value="format.number(report.totals.failed)" :accent="report.totals.failed > 0" />
        <StatTile :label="t('smsMarketing.analytics.blocked')" :value="format.number(report.totals.blocked)" />
        <StatTile :label="t('smsMarketing.analytics.segments')" :value="format.number(report.totals.segments)" />
        <StatTile :label="t('smsMarketing.analytics.cost')" :value="spent" :hint="t('smsMarketing.analytics.costHint')" />
        <StatTile
            :label="t('smsMarketing.standing.credit')"
            :value="standing.credit.reported && standing.credit.balance !== null ? format.number(standing.credit.balance) : t('smsMarketing.standing.notReported')"
            :hint="t('smsMarketing.standing.creditHint')"
        />
    </div>

    <div class="mb-3 grid gap-3 sm:grid-cols-3">
        <StatTile :label="t('smsMarketing.standing.segmentsMonth')" :value="planLine" />
        <StatTile
            :label="t('smsMarketing.standing.dailyCap')"
            :value="t('smsMarketing.standing.of', { used: format.number(standing.dailyCap.used), limit: format.number(standing.dailyCap.cap) })"
        />
        <StatTile
            :label="t('smsMarketing.standing.contacts')"
            :value="
                standing.contacts.limit === null
                    ? format.number(standing.contacts.used)
                    : t('smsMarketing.standing.of', { used: format.number(standing.contacts.used), limit: format.number(standing.contacts.limit) })
            "
        />
    </div>

    <Card flush class="mb-3" :title="t('smsMarketing.analytics.perDay')">
        <DataTable :columns="dayColumns" :rows="report.daily" row-key="date" :caption="t('smsMarketing.analytics.perDay')">
            <template #cell-date="{ row }"><span class="tabular text-ink">{{ format.date(row.date, 'date') }}</span></template>
            <template v-for="key in ['sent', 'delivered', 'failed', 'blocked', 'segments']" :key="key" #[`cell-${key}`]="{ row }">
                <span class="tabular text-ink-muted">{{ format.number((row as unknown as Record<string, number>)[key] ?? 0) }}</span>
            </template>
            <template #empty>
                <EmptyState :title="t('smsMarketing.analytics.empty')" :description="t('smsMarketing.analytics.emptyBody')" compact />
            </template>
        </DataTable>
    </Card>

    <Card flush :title="t('smsMarketing.analytics.campaigns')">
        <DataTable :columns="campaignColumns" :rows="campaigns" row-key="id" :caption="t('smsMarketing.analytics.campaigns')">
            <template #cell-name="{ row }">
                <Link :href="route('sms_marketing.campaigns.show', { campaign: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
            </template>
            <template #cell-status="{ row }"><SmsBadge kind="campaign" :value="row.status" /></template>
            <template v-for="key in ['recipients', 'accepted', 'delivered', 'failed', 'blocked']" :key="key" #[`cell-${key}`]="{ row }">
                <span class="tabular text-ink-muted">{{ format.number((row.stats as unknown as Record<string, number>)[key] ?? 0) }}</span>
            </template>
            <template #empty>
                <EmptyState :title="t('smsMarketing.analytics.noCampaigns')" :description="t('smsMarketing.analytics.noCampaignsBody')" compact />
            </template>
        </DataTable>
    </Card>
</template>
