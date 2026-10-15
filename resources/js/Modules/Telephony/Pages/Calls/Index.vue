<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Phone, PhoneIncoming, PhoneMissed, PhoneOutgoing, Search } from 'lucide-vue-next';
import { computed } from 'vue';
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
import type { CursorPaginated } from '@/Core/types';
import TelephonyNav from '../../Components/TelephonyNav.vue';
import { statusTone, type CallDirection, type CallRow, type CallStatus } from '../../Components/types';
import { displayPhone, formatDuration } from '../../lib/phone';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    calls: CursorPaginated<CallRow>;
    filters: { search: string; direction: CallDirection | null; status: CallStatus | null };
    canCall: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({ search: props.filters.search, direction: props.filters.direction ?? '', status: props.filters.status ?? '' });

const directionOptions = computed(() => [
    { value: '', label: t('telephony.calls.any') },
    { value: 'outbound', label: t('telephony.direction.outbound') },
    { value: 'inbound', label: t('telephony.direction.inbound') },
]);

const STATUSES: CallStatus[] = ['completed', 'missed', 'no_answer', 'busy', 'failed', 'answered', 'ringing', 'dialing'];
const statusOptions = computed(() => [{ value: '', label: t('telephony.calls.any') }, ...STATUSES.map((value) => ({ value, label: t(`telephony.statuses.${value}`) }))]);

const filtered = computed(() => props.filters.search !== '' || props.filters.direction !== null || props.filters.status !== null);

const columns = computed<Column[]>(() => [
    { key: 'when', label: t('telephony.calls.columns.when') },
    { key: 'number', label: t('telephony.calls.columns.number') },
    { key: 'status', label: t('telephony.calls.columns.status') },
    { key: 'duration', label: t('telephony.calls.columns.duration'), align: 'right' },
    { key: 'member', label: t('telephony.calls.columns.member'), hideable: true },
    { key: 'actions', label: t('telephony.calls.call'), srOnly: true, align: 'right' },
]);

function apply(): void {
    form.transform((data) => Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '')));
    form.get(route('telephony.calls.index'), { preserveScroll: true, preserveState: true });
}

function clear(): void {
    form.search = '';
    form.direction = '';
    form.status = '';
    apply();
}

const icon = (row: CallRow) => (row.status === 'missed' ? PhoneMissed : row.direction === 'inbound' ? PhoneIncoming : PhoneOutgoing);
</script>

<template>
    <PageHeader :eyebrow="t('telephony.eyebrow')" :title="t('telephony.calls.title')" :description="t('telephony.calls.description')" />
    <TelephonyNav current="calls" />

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="apply">
            <Input v-model="form.search" :label="t('telephony.calls.search')" class="min-w-48 flex-1" type="search" maxlength="40" mono>
                <template #leading><Search class="size-4" aria-hidden="true" /></template>
            </Input>
            <Select v-model="form.direction" :options="directionOptions" :label="t('telephony.calls.columns.direction')" class="w-full sm:w-40" />
            <Select v-model="form.status" :options="statusOptions" :label="t('telephony.calls.columns.status')" class="w-full sm:w-40" />
            <Button type="submit" size="sm" :loading="form.processing">{{ t('telephony.calls.apply') }}</Button>
            <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('telephony.calls.clear') }}</Button>
        </form>
    </Card>

    <Card flush>
        <DataTable :columns="columns" :rows="calls.data" row-key="id" :caption="t('telephony.calls.title')">
            <template #cell-when="{ row }">
                <span class="flex items-center gap-2">
                    <component :is="icon(row)" :class="['size-4 shrink-0', row.status === 'missed' ? 'text-warn' : 'text-ink-subtle']" :aria-label="t(`telephony.direction.${row.direction}`)" />
                    <span class="tabular whitespace-nowrap">{{ format.date(row.startedAt, 'datetime') }}</span>
                </span>
            </template>
            <template #cell-number="{ row }">
                <span v-if="row.contact" class="block font-medium text-ink">{{ row.contact.name }}</span>
                <span :class="['tabular font-mono', row.contact ? 'text-xs text-ink-subtle' : 'text-ink']">{{ displayPhone(row.number) }}</span>
            </template>
            <template #cell-status="{ row }">
                <span class="flex flex-wrap items-center gap-1">
                    <Badge :tone="statusTone(row.status)">{{ t(`telephony.statuses.${row.status}`) }}</Badge>
                    <span v-if="!row.confirmed" class="text-xs text-ink-subtle" :title="t('telephony.calls.unconfirmed')">*</span>
                </span>
            </template>
            <template #cell-duration="{ row }">
                <span class="tabular">{{ row.durationSeconds ? formatDuration(row.durationSeconds) : t('telephony.calls.none') }}</span>
            </template>
            <template #cell-member="{ row }">{{ row.owner ?? t('telephony.calls.none') }}</template>
            <template #cell-actions="{ row }">
                <Button v-if="canCall" size="sm" variant="ghost" :href="route('telephony.dialer', { number: row.number })">
                    <template #icon><Phone class="size-4" aria-hidden="true" /></template>
                    {{ t('telephony.calls.call') }}
                </Button>
            </template>
            <template #empty>
                <EmptyState compact :title="t('telephony.calls.empty')" :description="t('telephony.calls.emptyBody')">
                    <template #icon><Phone /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>
    <Pagination :meta="calls" />
</template>
