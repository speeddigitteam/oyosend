<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { route } from '@/Core/lib/route';
import HrmStatusBadge from './HrmStatusBadge.vue';
import LeavePeriod from './LeavePeriod.vue';
import { useHrmFormat } from './hrmFormat';
import type { LeaveRequest, Paginated } from './types';

/**
 * Leave requests as a table. The row buttons only appear where the server said
 * the person may act (`abilities`); the page owns what the buttons open.
 */
defineProps<{
    requests: Paginated<LeaveRequest>;
    caption: string;
    emptyTitle: string;
    emptyBody?: string;
    storageKey?: string;
}>();

const emit = defineEmits<{
    approve: [request: LeaveRequest];
    reject: [request: LeaveRequest];
    cancel: [request: LeaveRequest];
}>();

const { t } = useI18n();
const hrm = useHrmFormat();

const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('hrm.fields.employee') },
    { key: 'leaveType', label: t('hrm.fields.leaveType'), hideable: true },
    { key: 'period', label: t('hrm.fields.period') },
    { key: 'units', label: t('hrm.fields.days'), align: 'right' },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);
</script>

<template>
    <DataTable :columns="columns" :rows="requests.data" row-key="id" :caption="caption" :storage-key="storageKey">
        <template #cell-employee="{ row }">
            <Link :href="route('hrm.leave.requests.show', { leaveRequest: row.id })" class="block text-ink hover:text-brand-text">{{ row.employee.name }}</Link>
            <span class="tabular block text-xs text-ink-subtle">{{ row.employee.number }}</span>
        </template>
        <template #cell-leaveType="{ row }">
            <span class="text-ink-muted">{{ row.leaveType.name }}</span>
            <span v-if="!row.leaveType.isPaid" class="block text-xs text-ink-subtle">{{ t('hrm.leave.unpaid') }}</span>
        </template>
        <template #cell-period="{ row }">
            <LeavePeriod :starts-on="row.startsOn" :ends-on="row.endsOn" :start-session="row.startSession" :end-session="row.endSession" class="text-ink-muted" />
        </template>
        <template #cell-units="{ row }"><span class="tabular text-ink" data-testid="request-days">{{ hrm.days(row.units) }}</span></template>
        <template #cell-status="{ row }"><HrmStatusBadge kind="leave" :value="row.status" /></template>
        <template #cell-actions="{ row }">
            <div class="flex justify-end gap-1">
                <template v-if="row.abilities?.decide">
                    <Button size="sm" variant="ghost" @click="emit('approve', row)">{{ t('hrm.decision.approve') }}</Button>
                    <Button size="sm" variant="ghost" @click="emit('reject', row)">{{ t('hrm.decision.reject') }}</Button>
                </template>
                <Button v-if="row.abilities?.cancel" size="sm" variant="ghost" @click="emit('cancel', row)">{{ t('hrm.leave.cancel.action') }}</Button>
            </div>
        </template>
        <template #empty>
            <EmptyState :title="emptyTitle" :description="emptyBody">
                <template #icon><CalendarDays /></template>
            </EmptyState>
        </template>
    </DataTable>
    <Pagination :meta="requests" />
</template>
