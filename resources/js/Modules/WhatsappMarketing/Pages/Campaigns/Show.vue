<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock, Pencil, Send, Trash2, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
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
import ChannelBanner from '../../Components/ChannelBanner.vue';
import FormDialog from '../../Components/FormDialog.vue';
import type { CampaignRow, ChannelInfo, Paginated } from '../../Components/types';
import { useWhatsappFormat } from '../../Components/useWhatsappFormat';
import { campaignStatusTone, recipientStateTone, zonedToIso } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

interface Recipient {
    id: string;
    contactId: string;
    name: string | null;
    phone: string | null;
    state: string;
    reason: string | null;
    sentAt: string | null;
    deliveredAt: string | null;
    readAt: string | null;
}

const props = defineProps<{
    campaign: CampaignRow;
    progress: {
        pending: number;
        queued: number;
        sent: number;
        delivered: number;
        read: number;
        failed: number;
        blocked: number;
        skipped: number;
        deliveredBps: number | null;
        readBps: number | null;
    };
    reasons: Record<string, number>;
    recipients: Paginated<Recipient>;
    stateFilter: string | null;
    channel: ChannelInfo;
    can: { manage: boolean; send: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const { percent, reason } = useWhatsappFormat();

const connected = computed(() => props.channel.state === 'available');
const isDraft = computed(() => props.campaign.status === 'draft');
const canStartSending = computed(() => props.can.send && (props.campaign.status === 'draft' || props.campaign.status === 'scheduled'));
const canCancel = computed(() => props.can.send && (props.campaign.status === 'scheduled' || props.campaign.status === 'sending'));
const canEdit = computed(() => props.can.manage && isDraft.value);

const sendConfirm = ref(false);
const cancelConfirm = ref(false);
const deleteConfirm = ref(false);
const scheduleOpen = ref(false);
const busy = ref(false);
const schedule = useForm({ when: '' });
const scheduleError = ref('');

function post(name: string, data: Record<string, string> = {}, done?: () => void): void {
    busy.value = true;
    router.post(route(name, { campaign: props.campaign.id }), data, {
        preserveScroll: true,
        onSuccess: () => done?.(),
        onFinish: () => (busy.value = false),
    });
}

function sendNow(): void {
    post('whatsapp_marketing.campaigns.send', {}, () => (sendConfirm.value = false));
}

function submitSchedule(): void {
    const iso = zonedToIso(schedule.when, page.props.app.timezone);

    if (iso === null) {
        scheduleError.value = t('whatsapp.campaign.scheduleInvalid');

        return;
    }

    if (Date.parse(iso) <= Date.now()) {
        scheduleError.value = t('whatsapp.campaign.schedulePast');

        return;
    }

    scheduleError.value = '';
    post('whatsapp_marketing.campaigns.send', { scheduled_at: iso }, () => (scheduleOpen.value = false));
}

function cancel(): void {
    post('whatsapp_marketing.campaigns.cancel', {}, () => (cancelConfirm.value = false));
}

function destroy(): void {
    busy.value = true;
    router.delete(route('whatsapp_marketing.campaigns.destroy', { campaign: props.campaign.id }), { onFinish: () => (busy.value = false) });
}

const stateOptions = computed(() => [
    { value: '', label: t('whatsapp.campaign.anyState') },
    ...(['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'blocked', 'skipped'] as const).map((value) => ({ value, label: t(`whatsapp.recipientState.${value}`) })),
]);

function filterState(value: string | number | null | undefined): void {
    router.get(route('whatsapp_marketing.campaigns.show', { campaign: props.campaign.id }), value ? { state: String(value) } : {}, {
        preserveState: true,
        preserveScroll: true,
        only: ['recipients', 'stateFilter'],
    });
}

const tiles = computed(() =>
    (['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'blocked', 'skipped'] as const).map((key) => ({ key, value: props.progress[key] })),
);
const reasonRows = computed(() => Object.entries(props.reasons).map(([code, count]) => ({ code, count })).sort((a, b) => b.count - a.count));

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.campaign.columns.contact') },
    { key: 'state', label: t('whatsapp.campaign.columns.state') },
    { key: 'reason', label: t('whatsapp.campaign.columns.reason'), hideable: true },
    { key: 'sentAt', label: t('whatsapp.campaign.columns.sent'), hideable: true },
]);
const reasonColumns = computed<Column[]>(() => [
    { key: 'reason', label: t('whatsapp.campaign.columns.reason') },
    { key: 'count', label: t('whatsapp.campaign.columns.count') },
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="campaign.name">
        <template #actions>
            <Button variant="ghost" :href="route('whatsapp_marketing.campaigns.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.common.back') }}
            </Button>
            <Button v-if="canEdit" variant="secondary" :href="route('whatsapp_marketing.campaigns.edit', { campaign: campaign.id })">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.common.edit') }}
            </Button>
            <Button v-if="canEdit" variant="ghost" @click="deleteConfirm = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.common.delete') }}
            </Button>
            <Button v-if="canCancel" variant="secondary" @click="cancelConfirm = true">
                <template #icon><XCircle class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.campaign.cancel') }}
            </Button>
            <Button v-if="canStartSending" variant="secondary" :disabled="!connected" @click="scheduleOpen = true">
                <template #icon><CalendarClock class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.campaign.schedule') }}
            </Button>
            <Button v-if="canStartSending" variant="primary" :disabled="!connected" @click="sendConfirm = true">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.campaign.sendNow') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="campaigns" />
    <ChannelBanner :channel="channel" />
    <p v-if="canStartSending && !connected" class="mb-3 text-sm text-ink-muted" data-testid="send-disabled">{{ t('whatsapp.campaign.sendDisabled') }}</p>

    <Card class="mb-3" :title="t('whatsapp.campaign.details')">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="eyebrow">{{ t('whatsapp.campaign.status') }}</dt>
                <dd class="mt-1"><Badge :tone="campaignStatusTone(campaign.status)" dot>{{ t(`whatsapp.campaignStatus.${campaign.status}`) }}</Badge></dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.campaign.template') }}</dt>
                <dd class="mt-1 text-ink wrap-anywhere">{{ campaign.templateName }} ({{ campaign.templateLanguage }})</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.campaign.list') }}</dt>
                <dd class="mt-1 text-ink wrap-anywhere">{{ campaign.listName }}</dd>
            </div>
            <div v-if="campaign.scheduledAt">
                <dt class="eyebrow">{{ t('whatsapp.campaign.scheduledAt') }}</dt>
                <dd class="tabular mt-1 text-ink">{{ format.date(campaign.scheduledAt, 'datetime') }}</dd>
            </div>
            <div v-if="campaign.startedAt">
                <dt class="eyebrow">{{ t('whatsapp.campaign.startedAt') }}</dt>
                <dd class="tabular mt-1 text-ink">{{ format.date(campaign.startedAt, 'datetime') }}</dd>
            </div>
            <div v-if="campaign.completedAt">
                <dt class="eyebrow">{{ t('whatsapp.campaign.completedAt') }}</dt>
                <dd class="tabular mt-1 text-ink">{{ format.date(campaign.completedAt, 'datetime') }}</dd>
            </div>
            <div v-if="campaign.owner">
                <dt class="eyebrow">{{ t('whatsapp.campaign.owner') }}</dt>
                <dd class="mt-1 text-ink">{{ campaign.owner.name }}</dd>
            </div>
            <div v-if="campaign.stopReason" class="sm:col-span-2 lg:col-span-3">
                <dt class="eyebrow">{{ t('whatsapp.campaign.stopReason') }}</dt>
                <dd class="mt-1 text-warn" data-testid="stop-reason">{{ reason(campaign.stopReason) }}</dd>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="eyebrow">{{ t('whatsapp.campaign.message') }}</dt>
                <dd class="glass-well mt-1 whitespace-pre-wrap rounded-control px-3 py-2 text-ink wrap-anywhere">{{ campaign.templateBody }}</dd>
            </div>
        </dl>
    </Card>

    <ul class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-4" :aria-label="t('whatsapp.campaign.progress')">
        <li v-for="tile in tiles" :key="tile.key">
            <StatTile :label="t(`whatsapp.recipientState.${tile.key}`)" :value="format.number(tile.value)" />
        </li>
        <li>
            <StatTile :label="t('whatsapp.campaign.deliveredRate')" :value="percent(progress.deliveredBps)" :hint="t('whatsapp.campaign.deliveredRateHint')" />
        </li>
        <li>
            <StatTile :label="t('whatsapp.campaign.readRate')" :value="percent(progress.readBps)" :hint="t('whatsapp.campaign.readRateHint')" />
        </li>
    </ul>

    <Card v-if="reasonRows.length > 0" flush class="mb-3" :title="t('whatsapp.campaign.reasonsTitle')">
        <DataTable :columns="reasonColumns" :rows="reasonRows" row-key="code" :caption="t('whatsapp.campaign.reasonsTitle')">
            <template #cell-reason="{ row }"><span class="text-ink">{{ reason(row.code) }}</span></template>
            <template #cell-count="{ row }"><span class="tabular text-ink">{{ format.number(row.count) }}</span></template>
        </DataTable>
    </Card>

    <Card flush :title="t('whatsapp.campaign.recipients')">
        <template #actions>
            <Select :model-value="stateFilter ?? ''" :options="stateOptions" :label="t('whatsapp.campaign.state')" @update:model-value="filterState" />
        </template>
        <DataTable :columns="columns" :rows="recipients.data" row-key="id" :caption="t('whatsapp.campaign.recipients')">
            <template #cell-name="{ row }">
                <p class="text-ink wrap-anywhere">{{ row.name ?? t('whatsapp.common.none') }}</p>
                <p class="tabular text-xs text-ink-subtle">{{ row.phone ?? t('whatsapp.common.none') }}</p>
            </template>
            <template #cell-state="{ row }">
                <Badge :tone="recipientStateTone(row.state)" dot>{{ t(`whatsapp.recipientState.${row.state}`) }}</Badge>
            </template>
            <template #cell-reason="{ row }"><span class="text-ink-muted">{{ row.reason ? reason(row.reason) : t('whatsapp.common.none') }}</span></template>
            <template #cell-sentAt="{ row }">
                <span class="tabular text-ink-muted">{{ row.sentAt ? format.date(row.sentAt, 'datetime') : t('whatsapp.common.none') }}</span>
            </template>
            <template #empty>
                <EmptyState :title="t(stateFilter ? 'whatsapp.campaign.noRecipientsFiltered' : 'whatsapp.campaign.noRecipients')" :description="stateFilter ? undefined : t('whatsapp.campaign.noRecipientsBody')" />
            </template>
        </DataTable>
        <Pagination :meta="recipients" />
    </Card>

    <ConfirmDialog
        v-model:open="sendConfirm"
        tone="default"
        :title="t('whatsapp.campaign.sendTitle', { name: campaign.name })"
        :description="t('whatsapp.campaign.sendBody')"
        :confirm-label="t('whatsapp.campaign.sendNow')"
        :loading="busy"
        @confirm="sendNow"
    />
    <ConfirmDialog
        v-model:open="cancelConfirm"
        :title="t('whatsapp.campaign.cancelTitle', { name: campaign.name })"
        :description="t('whatsapp.campaign.cancelBody')"
        :confirm-label="t('whatsapp.campaign.cancel')"
        :loading="busy"
        @confirm="cancel"
    />
    <ConfirmDialog
        v-model:open="deleteConfirm"
        :title="t('whatsapp.campaign.deleteTitle', { name: campaign.name })"
        :description="t('whatsapp.campaign.deleteBody')"
        :confirm-label="t('whatsapp.common.delete')"
        :loading="busy"
        @confirm="destroy"
    />
    <FormDialog v-model:open="scheduleOpen" size="sm" :title="t('whatsapp.campaign.scheduleTitle')" :description="t('whatsapp.campaign.scheduleBody')" :submit-label="t('whatsapp.campaign.schedule')" :processing="busy" :disabled="schedule.when === ''" @submit="submitSchedule">
        <Input v-model="schedule.when" type="datetime-local" :label="t('whatsapp.campaign.sendAt')" :hint="t('whatsapp.campaign.sendAtHint', { zone: page.props.app.timezone })" :error="scheduleError" />
    </FormDialog>
</template>
