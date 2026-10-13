<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Ban, Download, Pencil, RotateCcw, Send, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
import ChannelNotice from '../../Components/ChannelNotice.vue';
import SegmentMeter from '../../Components/SegmentMeter.vue';
import SmsBadge from '../../Components/SmsBadge.vue';
import SmsFormDialog from '../../Components/SmsFormDialog.vue';
import { isLocalDateTime, localDateTime } from '../../Components/smsFormat';
import type { CampaignDetail, ChannelState, Paginated, RecipientRow, RecipientStatus, SegmentCount } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaign: CampaignDetail;
    recipients: Paginated<RecipientRow>;
    hiddenRecipients?: number;
    filters: { status: string | null };
    statuses: RecipientStatus[];
    sample: SegmentCount;
    channel: ChannelState;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status ?? 'all');
const statusOptions = computed(() => [
    { value: 'all', label: t('smsMarketing.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`smsMarketing.status.recipient.${value}`) })),
]);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('smsMarketing.fields.contact') },
    { key: 'phone', label: t('smsMarketing.fields.phone') },
    { key: 'status', label: t('smsMarketing.fields.status') },
    { key: 'segments', label: t('smsMarketing.fields.segments'), hideable: true },
    { key: 'attempts', label: t('smsMarketing.fields.attempts'), hideable: true, hiddenByDefault: true },
    { key: 'when', label: t('smsMarketing.fields.when'), hideable: true },
]);

const stats = computed(() => props.campaign.stats);
const tiles = computed(() => [
    { key: 'recipients', value: stats.value.recipients },
    { key: 'accepted', value: stats.value.accepted },
    { key: 'delivered', value: stats.value.delivered },
    { key: 'failed', value: stats.value.failed },
    { key: 'blocked', value: stats.value.blocked },
    { key: 'skipped', value: stats.value.skipped },
]);
const holdText = computed(() =>
    props.campaign.status === 'sending' && props.campaign.holdReason ? t(`smsMarketing.hold.${props.campaign.holdReason}`) : null,
);

// Launch
const launchOpen = ref(false);
const launch = useForm({ when: 'now', scheduled_for: '' });
const minLocal = computed(() => localDateTime(new Date(Date.now() + 2 * 60_000)));
const launchError = computed(() => {
    const errors = launch.errors as Record<string, string | undefined>;

    return errors.campaign ?? errors.groups ?? errors.limit;
});
const launchInvalid = computed(() => launch.when === 'later' && !isLocalDateTime(launch.scheduled_for));

function submitLaunch(): void {
    launch
        .transform((data) => ({ scheduled_for: data.when === 'later' ? data.scheduled_for : null }))
        .post(route('sms_marketing.campaigns.launch', { campaign: props.campaign.id }), {
            preserveScroll: true,
            onSuccess: () => {
                launchOpen.value = false;
            },
        });
}

// Cancel, delete
const cancelOpen = ref(false);
const deleteOpen = ref(false);
const busy = ref(false);

function cancel(): void {
    busy.value = true;
    router.post(route('sms_marketing.campaigns.cancel', { campaign: props.campaign.id }), {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            cancelOpen.value = false;
        },
    });
}

function remove(): void {
    busy.value = true;
    router.delete(route('sms_marketing.campaigns.destroy', { campaign: props.campaign.id }), {
        onFinish: () => {
            busy.value = false;
            deleteOpen.value = false;
        },
    });
}

function retry(): void {
    router.post(route('sms_marketing.campaigns.retry', { campaign: props.campaign.id }), {}, { preserveScroll: true });
}

function applyFilter(): void {
    router.get(
        route('sms_marketing.campaigns.show', { campaign: props.campaign.id }),
        { status: status.value === 'all' ? undefined : status.value },
        { preserveState: true, replace: true },
    );
}

function reasonText(row: RecipientRow): string {
    return row.reason ? t(`smsMarketing.reasons.${row.reason}`, row.reason) : '';
}
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="campaign.name" :description="t('smsMarketing.show.description')">
        <template #actions>
            <SmsBadge kind="campaign" :value="campaign.status" />
            <a
                :href="route('sms_marketing.campaigns.export', { campaign: campaign.id })"
                class="inline-flex h-[2.375rem] items-center justify-center gap-2 rounded-control border border-line-strong bg-control px-4 text-sm font-medium whitespace-nowrap text-ink hover:bg-control-hover"
            >
                <Download class="size-4" aria-hidden="true" />
                {{ t('smsMarketing.show.export') }}
            </a>
            <Button v-if="campaign.abilities.edit" :href="route('sms_marketing.campaigns.edit', { campaign: campaign.id })">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.common.edit') }}
            </Button>
            <Button v-if="campaign.abilities.launch" variant="primary" @click="launchOpen = true">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.show.launch') }}
            </Button>
            <Button v-if="campaign.abilities.retry" @click="retry">
                <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.show.retry', { count: format.number(campaign.retryable) }) }}
            </Button>
            <Button v-if="campaign.abilities.cancel" variant="danger" @click="cancelOpen = true">
                <template #icon><Ban class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.show.cancel') }}
            </Button>
            <Button v-if="campaign.abilities.delete" variant="ghost" @click="deleteOpen = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.common.delete') }}
            </Button>
        </template>
    </PageHeader>

    <ChannelNotice v-if="campaign.status === 'draft' || campaign.status === 'sending'" class="mb-3" :channel="channel" />

    <p v-if="holdText" class="mb-3 rounded-card border border-line bg-warn-soft px-4 py-3 text-[0.8125rem] text-ink" role="status" data-testid="hold">
        {{ holdText }}
    </p>

    <div class="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatTile v-for="tile in tiles" :key="tile.key" :label="t(`smsMarketing.show.stats.${tile.key}`)" :value="format.number(tile.value)" />
    </div>

    <div class="mb-3 grid gap-3 lg:grid-cols-3">
        <Card class="lg:col-span-2" :title="t('smsMarketing.show.message')">
            <p class="break-words whitespace-pre-wrap text-sm text-ink" data-testid="campaign-body">{{ campaign.body }}</p>
            <div class="mt-3"><SegmentMeter :count="sample" /></div>
        </Card>
        <Card :title="t('smsMarketing.show.details')">
            <dl class="grid gap-2 text-[0.8125rem]">
                <div>
                    <dt class="eyebrow">{{ t('smsMarketing.show.groups') }}</dt>
                    <dd class="mt-1 text-ink">{{ campaign.groups.map((group) => group.name).join(', ') || t('smsMarketing.common.dash') }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('smsMarketing.fields.sender') }}</dt>
                    <dd class="mt-1 text-ink">{{ campaign.sender ? `${campaign.sender.label} (${campaign.sender.value})` : t('smsMarketing.common.dash') }}</dd>
                </div>
                <div v-if="campaign.scheduledFor">
                    <dt class="eyebrow">{{ t('smsMarketing.show.scheduledFor') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ format.date(campaign.scheduledFor, 'datetime') }}</dd>
                </div>
                <div v-if="campaign.estimatedSegments > 0">
                    <dt class="eyebrow">{{ t('smsMarketing.show.estimatedSegments') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ format.number(campaign.estimatedSegments) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('smsMarketing.fields.owner') }}</dt>
                    <dd class="mt-1 text-ink">{{ campaign.owner?.name ?? t('smsMarketing.common.dash') }}</dd>
                </div>
            </dl>
        </Card>
    </div>

    <Card flush :title="t('smsMarketing.show.recipients')">
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="applyFilter">
            <Select v-model="status" :options="statusOptions" :label="t('smsMarketing.fields.status')" class="w-full sm:w-48" />
            <Button type="submit" size="sm">{{ t('smsMarketing.common.apply') }}</Button>
        </form>
        <p v-if="(hiddenRecipients ?? 0) > 0" class="border-b border-line px-5 py-3 text-[0.8125rem] text-ink-muted" data-testid="hidden-recipients">
            {{ t('smsMarketing.show.hiddenRecipients', { count: format.number(hiddenRecipients ?? 0) }) }}
        </p>
        <DataTable :columns="columns" :rows="recipients.data" row-key="id" :caption="t('smsMarketing.show.recipientsCaption')" storage-key="sms-marketing-recipients">
            <template #cell-name="{ row }"><span class="text-ink">{{ row.name || t('smsMarketing.common.dash') }}</span></template>
            <template #cell-phone="{ row }"><span class="tabular text-ink-muted">{{ row.phone ?? t('smsMarketing.common.dash') }}</span></template>
            <template #cell-status="{ row }">
                <SmsBadge kind="recipient" :value="row.status" />
                <span v-if="row.reason" class="mt-1 block text-xs text-ink-subtle">{{ reasonText(row) }}</span>
                <span v-if="row.status === 'pending' && row.nextAttemptAt" class="mt-1 block text-xs text-ink-subtle">
                    {{ t('smsMarketing.show.retryAt', { when: format.date(row.nextAttemptAt, 'datetime') }) }}
                </span>
            </template>
            <template #cell-segments="{ row }"><span class="tabular text-ink-muted">{{ row.segments > 0 ? format.number(row.segments) : t('smsMarketing.common.dash') }}</span></template>
            <template #cell-attempts="{ row }">
                <span class="tabular text-ink-muted">{{ format.number(row.attempts) }} / {{ format.number(campaign.maxAttempts) }}</span>
            </template>
            <template #cell-when="{ row }">
                <span class="tabular text-ink-muted">
                    {{ row.deliveredAt || row.sentAt ? format.date(row.deliveredAt ?? row.sentAt, 'datetime') : t('smsMarketing.common.dash') }}
                </span>
            </template>
            <template #empty>
                <EmptyState :title="t('smsMarketing.show.noRecipients')" :description="t('smsMarketing.show.noRecipientsBody')" compact />
            </template>
        </DataTable>
        <Pagination :meta="recipients" />
    </Card>

    <SmsFormDialog
        v-model:open="launchOpen"
        :title="t('smsMarketing.launch.title')"
        :description="t('smsMarketing.launch.description')"
        :submit-label="launch.when === 'now' ? t('smsMarketing.launch.sendNow') : t('smsMarketing.launch.schedule')"
        :processing="launch.processing"
        :disabled="launchInvalid"
        @submit="submitLaunch"
    >
        <Select
            v-model="launch.when"
            :label="t('smsMarketing.launch.when')"
            :options="[
                { value: 'now', label: t('smsMarketing.launch.now') },
                { value: 'later', label: t('smsMarketing.launch.later') },
            ]"
        />
        <Input
            v-if="launch.when === 'later'"
            v-model="launch.scheduled_for"
            type="datetime-local"
            :min="minLocal"
            :label="t('smsMarketing.launch.at')"
            :hint="t('smsMarketing.launch.atHint')"
            :error="launch.errors.scheduled_for"
        />
        <p v-if="launchError" class="text-[0.8125rem] text-danger" role="alert">{{ launchError }}</p>
    </SmsFormDialog>

    <ConfirmDialog
        v-model:open="cancelOpen"
        :title="t('smsMarketing.cancelDialog.title')"
        :description="t('smsMarketing.cancelDialog.body')"
        :confirm-label="t('smsMarketing.show.cancel')"
        :loading="busy"
        @confirm="cancel"
    />
    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('smsMarketing.deleteDialog.title')"
        :description="t('smsMarketing.deleteDialog.body', { name: campaign.name })"
        :confirm-label="t('smsMarketing.common.delete')"
        :loading="busy"
        @confirm="remove"
    />
</template>
