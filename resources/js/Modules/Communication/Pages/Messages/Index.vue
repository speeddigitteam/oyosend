<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Mail, Plus, Search } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
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
import type { Paginated } from '@/Core/types';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import { stateTone } from '../../Components/communicationFormat';
import MessageStatusBadge from '../../Components/MessageStatusBadge.vue';
import type { ChannelStatus, MessageRow } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    messages: Paginated<MessageRow>;
    filters: { search: string; status: string | null; channel: string | null; source: string | null; contact: string | null };
    statuses: string[];
    channels: ChannelStatus[];
    canSend: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const { label, failure } = useCommunicationLabels();

const draft = reactive({ search: props.filters.search, status: props.filters.status ?? '', channel: props.filters.channel ?? '' });

const statusOptions = computed(() => [
    { value: '', label: t('communication.messages.anyStatus') },
    ...props.statuses.map((value) => ({ value, label: label('status', value) })),
]);
const channelOptions = computed(() => [
    { value: '', label: t('communication.messages.anyChannel') },
    ...props.channels.map(({ channel }) => ({ value: channel, label: label('channel', channel) })),
]);
const hasFilters = computed(() => props.filters.search !== '' || props.filters.status !== null || props.filters.channel !== null || props.filters.contact !== null);
const anyUnavailable = computed(() => props.channels.some((channel) => channel.state === 'not_configured'));

const columns = computed<Column[]>(() => [
    { key: 'recipient', label: t('communication.common.recipient') },
    { key: 'channel', label: t('communication.common.channel') },
    { key: 'purpose', label: t('communication.common.purpose'), hideable: true },
    { key: 'status', label: t('communication.common.status') },
    { key: 'queuedAt', label: t('communication.messages.queuedAt'), hideable: true },
    { key: 'actions', label: t('communication.common.actions'), srOnly: true, align: 'right' },
]);

function visit(): void {
    const query: Record<string, string> = {};
    const values: Record<string, string | null> = {
        search: draft.search.trim(),
        status: draft.status,
        channel: draft.channel,
        source: props.filters.source,
        contact: props.filters.contact,
    };

    for (const [key, value] of Object.entries(values)) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    router.get(route('communication.messages.index'), query, { preserveState: true });
}

function clear(): void {
    draft.search = '';
    draft.status = '';
    draft.channel = '';
    router.get(route('communication.messages.index'), {}, { preserveState: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.messages.title')" :description="t('communication.messages.description')">
        <template v-if="canSend" #actions>
            <Button variant="primary" :href="route('communication.messages.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('communication.messages.compose') }}
            </Button>
        </template>
    </PageHeader>
    <CommunicationNav current="messages" />

    <div class="grid gap-3">
        <Card :title="t('communication.messages.channels')" :description="t('communication.messages.channelsHint')">
            <ul class="flex flex-wrap gap-x-6 gap-y-2">
                <li v-for="channel in channels" :key="channel.channel" class="flex items-center gap-2 text-sm text-ink">
                    {{ label('channel', channel.channel) }}
                    <Badge :tone="stateTone(channel.state)" dot>{{ label('channelState', channel.state) }}</Badge>
                </li>
            </ul>
            <p v-if="anyUnavailable" class="mt-3 text-sm text-ink-subtle">{{ t('communication.messages.channelsMissing') }}</p>
        </Card>

        <Card>
            <form role="search" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end" @submit.prevent="visit">
                <Input v-model="draft.search" :label="t('communication.messages.search')" maxlength="100">
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                </Input>
                <Select v-model="draft.status" :options="statusOptions" :label="t('communication.common.status')" />
                <Select v-model="draft.channel" :options="channelOptions" :label="t('communication.common.channel')" />
                <div class="flex gap-2">
                    <Button type="submit" size="md">{{ t('communication.common.apply') }}</Button>
                    <Button v-if="hasFilters" type="button" variant="ghost" @click="clear">{{ t('communication.messages.clear') }}</Button>
                </div>
            </form>
        </Card>

        <Card flush>
            <DataTable :columns="columns" :rows="messages.data" row-key="id" :caption="t('communication.messages.caption')">
                <template #cell-recipient="{ row }">
                    <Link :href="route('communication.messages.show', { message: row.id })" class="font-medium text-ink wrap-anywhere hover:text-brand-text">
                        {{ row.recipient }}
                    </Link>
                    <span class="block max-w-md truncate text-xs text-ink-subtle">
                        {{ row.contact?.name ?? row.company?.name ?? '' }}
                        <template v-if="row.contact || row.company"> - </template>
                        {{ row.subject ?? t('communication.messages.noSubject') }}
                    </span>
                </template>
                <template #cell-channel="{ row }">{{ label('channel', row.channel) }}</template>
                <template #cell-purpose="{ row }"><Badge tone="neutral">{{ label('purpose', row.purpose) }}</Badge></template>
                <template #cell-status="{ row }">
                    <MessageStatusBadge :status="row.status" />
                    <span v-if="row.failureCode" class="mt-1 block max-w-xs text-xs text-ink-subtle">{{ failure(row.failureCode) }}</span>
                </template>
                <template #cell-queuedAt="{ row }">
                    <span class="tabular text-ink-muted">{{ format.date(row.queuedAt ?? row.createdAt, 'datetime') }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-end">
                        <Button size="sm" variant="ghost" :href="route('communication.messages.show', { message: row.id })">{{ t('communication.messages.view') }}</Button>
                    </div>
                </template>
                <template #empty>
                    <EmptyState
                        :title="t(hasFilters ? 'communication.messages.emptyFiltered' : 'communication.messages.empty')"
                        :description="t(hasFilters ? 'communication.messages.emptyFilteredBody' : 'communication.messages.emptyBody')"
                    >
                        <template #icon><Mail /></template>
                        <Button v-if="!hasFilters && canSend" variant="primary" :href="route('communication.messages.create')">{{ t('communication.messages.compose') }}</Button>
                        <Button v-if="hasFilters" variant="secondary" @click="clear">{{ t('communication.messages.clear') }}</Button>
                    </EmptyState>
                </template>
            </DataTable>
        </Card>
        <Pagination :meta="messages" />
    </div>
</template>
