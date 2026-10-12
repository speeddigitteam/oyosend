<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { useWhatsappFormat } from '../../Components/useWhatsappFormat';
import { campaignStatusTone } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

interface CampaignFigures {
    id: string;
    name: string;
    status: string;
    startedAt: string | null;
    recipients: number;
    queued: number;
    sent: number;
    delivered: number;
    read: number;
    failed: number;
    blocked: number;
    skipped: number;
    deliveredBps: number | null;
    readBps: number | null;
}

interface DailyRow {
    date: string;
    newConversations: number;
    inbound: number;
    outbound: number;
    campaign: number;
    windowsCustomer: number;
    windowsBusiness: number;
}

const props = defineProps<{
    days: number;
    overview: {
        conversations: { new: number; inbound: number; outbound: number; campaign: number; open: number; unassigned: number; firstResponseAverage: number | null };
        windows: { customer: number; business: number; used: number; limit: number | null };
        delivery: { sent: number; delivered: number; read: number; failed: number; blocked: number; deliveredBps: number | null; readBps: number | null };
        providerReported: { billable: number; notBillable: number; notReported: number; byCategory: Record<string, number> };
        cost: null;
        campaigns: CampaignFigures[];
    };
    daily: DailyRow[];
}>();

const { t, te } = useI18n();
const format = useFormat();
const { percent, duration } = useWhatsappFormat();

const rangeOptions = computed(() => [
    { value: '7', label: t('whatsapp.analytics.days', { count: format.number(7) }) },
    { value: '30', label: t('whatsapp.analytics.days', { count: format.number(30) }) },
    { value: '90', label: t('whatsapp.analytics.days', { count: format.number(90) }) },
]);

function setDays(value: string): void {
    router.get(route('whatsapp_marketing.analytics.index'), { days: value }, { preserveState: true, preserveScroll: true });
}

const firstResponse = computed(() => {
    const minutes = props.overview.conversations.firstResponseAverage;

    return minutes === null ? t('whatsapp.common.none') : duration(Math.floor(minutes / 60), minutes % 60);
});
const windowsUsed = computed(() => {
    const { used, limit } = props.overview.windows;

    return limit === null ? t('whatsapp.analytics.windowsUnlimited', { used: format.number(used) }) : t('whatsapp.analytics.windowsOf', { used: format.number(used), limit: format.number(limit) });
});
const categories = computed(() => Object.entries(props.overview.providerReported.byCategory));

function categoryLabel(code: string): string {
    return te(`whatsapp.templateCategory.${code}`) ? t(`whatsapp.templateCategory.${code}`) : code;
}

const campaignColumns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.analytics.columns.campaign') },
    { key: 'status', label: t('whatsapp.analytics.columns.status') },
    { key: 'recipients', label: t('whatsapp.analytics.columns.recipients') },
    { key: 'sent', label: t('whatsapp.analytics.columns.sent'), hideable: true },
    { key: 'delivered', label: t('whatsapp.analytics.columns.delivered') },
    { key: 'read', label: t('whatsapp.analytics.columns.read'), hideable: true },
    { key: 'failed', label: t('whatsapp.analytics.columns.failed'), hideable: true },
]);
const dailyMax = computed(() => Math.max(1, ...props.daily.map((row) => row.inbound + row.outbound + row.campaign)));
const hasActivity = computed(() => props.daily.some((row) => row.inbound + row.outbound + row.campaign + row.newConversations > 0));
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.analytics.title')" :description="t('whatsapp.analytics.description')">
        <template #actions>
            <SegmentedControl :model-value="String(days)" :options="rangeOptions" :label="t('whatsapp.analytics.range')" @update:model-value="setDays" />
        </template>
    </PageHeader>
    <WhatsappNav current="analytics" />

    <section class="mb-4" :aria-label="t('whatsapp.analytics.conversations')">
        <h2 class="eyebrow mb-2">{{ t('whatsapp.analytics.conversations') }}</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatTile :label="t('whatsapp.analytics.newConversations')" :value="format.number(overview.conversations.new)" />
            <StatTile :label="t('whatsapp.analytics.inbound')" :value="format.number(overview.conversations.inbound)" />
            <StatTile :label="t('whatsapp.analytics.outbound')" :value="format.number(overview.conversations.outbound)" :hint="t('whatsapp.analytics.outboundHint', { count: format.number(overview.conversations.campaign) })" />
            <StatTile :label="t('whatsapp.analytics.firstResponse')" :value="firstResponse" :hint="t('whatsapp.analytics.firstResponseHint')" />
            <StatTile :label="t('whatsapp.analytics.openNow')" :value="format.number(overview.conversations.open)" />
            <StatTile :label="t('whatsapp.analytics.unassignedNow')" :value="format.number(overview.conversations.unassigned)" />
            <StatTile :label="t('whatsapp.analytics.windowsUsed')" :value="windowsUsed" :hint="t('whatsapp.analytics.windowsHint')" />
            <StatTile
                :label="t('whatsapp.analytics.windowsOpened')"
                :value="format.number(overview.windows.customer + overview.windows.business)"
                :hint="t('whatsapp.analytics.windowsSplit', { customer: format.number(overview.windows.customer), business: format.number(overview.windows.business) })"
            />
        </div>
    </section>

    <section class="mb-4" :aria-label="t('whatsapp.analytics.delivery')">
        <h2 class="eyebrow mb-2">{{ t('whatsapp.analytics.delivery') }}</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatTile :label="t('whatsapp.analytics.sent')" :value="format.number(overview.delivery.sent)" />
            <StatTile :label="t('whatsapp.analytics.delivered')" :value="format.number(overview.delivery.delivered)" :hint="t('whatsapp.analytics.deliveredRate', { rate: percent(overview.delivery.deliveredBps) })" />
            <StatTile :label="t('whatsapp.analytics.read')" :value="format.number(overview.delivery.read)" :hint="t('whatsapp.analytics.readRate', { rate: percent(overview.delivery.readBps) })" />
            <StatTile :label="t('whatsapp.analytics.failed')" :value="format.number(overview.delivery.failed + overview.delivery.blocked)" :hint="t('whatsapp.analytics.failedHint', { blocked: format.number(overview.delivery.blocked) })" />
        </div>
    </section>

    <Card class="mb-4" :title="t('whatsapp.analytics.providerTitle')" :description="t('whatsapp.analytics.providerDescription')">
        <dl class="grid grid-cols-2 gap-x-5 gap-y-4 text-sm lg:grid-cols-4">
            <div>
                <dt class="eyebrow">{{ t('whatsapp.analytics.billable') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(overview.providerReported.billable) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.analytics.notBillable') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(overview.providerReported.notBillable) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.analytics.notReported') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(overview.providerReported.notReported) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.analytics.cost') }}</dt>
                <dd class="mt-2 text-ink-muted" data-testid="cost-not-reported">{{ t('whatsapp.analytics.costNotReported') }}</dd>
            </div>
        </dl>
        <ul v-if="categories.length" class="mt-4 flex flex-wrap gap-2" :aria-label="t('whatsapp.analytics.byCategory')">
            <li v-for="[code, count] in categories" :key="code">
                <Badge tone="info">{{ categoryLabel(code) }}: {{ format.number(count) }}</Badge>
            </li>
        </ul>
    </Card>

    <Card flush class="mb-4" :title="t('whatsapp.analytics.campaigns')">
        <DataTable :columns="campaignColumns" :rows="overview.campaigns" row-key="id" :caption="t('whatsapp.analytics.campaigns')">
            <template #cell-name="{ row }">
                <Link :href="route('whatsapp_marketing.campaigns.show', { campaign: row.id })" class="font-medium text-ink wrap-anywhere hover:underline">{{ row.name }}</Link>
            </template>
            <template #cell-status="{ row }">
                <Badge :tone="campaignStatusTone(row.status)" dot>{{ t(`whatsapp.campaignStatus.${row.status}`) }}</Badge>
            </template>
            <template #cell-recipients="{ row }"><span class="tabular">{{ format.number(row.recipients) }}</span></template>
            <template #cell-sent="{ row }"><span class="tabular">{{ format.number(row.sent) }}</span></template>
            <template #cell-delivered="{ row }">
                <span class="tabular">{{ format.number(row.delivered) }}</span>
                <span class="tabular ml-1 text-xs text-ink-subtle">{{ percent(row.deliveredBps) }}</span>
            </template>
            <template #cell-read="{ row }">
                <span class="tabular">{{ format.number(row.read) }}</span>
                <span class="tabular ml-1 text-xs text-ink-subtle">{{ percent(row.readBps) }}</span>
            </template>
            <template #cell-failed="{ row }"><span class="tabular">{{ format.number(row.failed + row.blocked) }}</span></template>
            <template #empty>
                <EmptyState :title="t('whatsapp.analytics.noCampaigns')" :description="t('whatsapp.analytics.noCampaignsBody')" compact />
            </template>
        </DataTable>
    </Card>

    <Card :title="t('whatsapp.analytics.daily')" :description="t('whatsapp.analytics.dailyDescription')">
        <EmptyState v-if="!hasActivity" :title="t('whatsapp.analytics.noActivity')" compact />
        <template v-else>
            <ul class="mb-4 grid gap-1.5" :aria-label="t('whatsapp.analytics.daily')">
                <li v-for="row in daily" :key="row.date" class="grid grid-cols-[6rem_1fr_auto] items-center gap-3 text-sm">
                    <span class="tabular text-ink-muted">{{ format.date(row.date, 'short') }}</span>
                    <span class="h-2 overflow-hidden rounded-chip bg-hover" aria-hidden="true">
                        <span class="block h-full rounded-chip bg-brand" :style="{ width: `${Math.round(((row.inbound + row.outbound + row.campaign) / dailyMax) * 100)}%` }" />
                    </span>
                    <span class="tabular text-right font-medium text-ink">{{ format.number(row.inbound + row.outbound + row.campaign) }}</span>
                </li>
            </ul>
        </template>
    </Card>
</template>
