<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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
import { useHrmFormat } from '../../Components/hrmFormat';
import type { AttendanceSummaryRow, GroupCount, Headcount, LeaveReport, NamedRef, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    from: string;
    to: string;
    filters: { department_id: string; year: number };
    headcount: Headcount;
    attendance: Paginated<AttendanceSummaryRow>;
    leave: LeaveReport;
    departments: NamedRef[];
    maxDays: number;
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const from = ref(props.from);
const to = ref(props.to);
const department = ref(props.filters.department_id);
const year = ref(String(props.filters.year));

const departmentOptions = computed(() => [{ value: '', label: t('hrm.common.all') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);

const tiles = computed(() => [
    { key: 'total', label: t('hrm.reports.headcount'), value: format.number(props.headcount.total) },
    { key: 'joined', label: t('hrm.reports.joined'), value: format.number(props.headcount.joined) },
    { key: 'left', label: t('hrm.reports.left'), value: format.number(props.headcount.left) },
]);

const groups = computed<{ key: string; title: string; items: { label: string; count: number }[] }[]>(() => [
    {
        key: 'status',
        title: t('hrm.reports.byStatus'),
        items: Object.entries(props.headcount.by_status).map(([value, count]) => ({ label: t(`hrm.status.employee.${value}`), count })),
    },
    {
        key: 'type',
        title: t('hrm.reports.byType'),
        items: Object.entries(props.headcount.by_employment_type).map(([value, count]) => ({ label: t(`hrm.employmentType.${value}`), count })),
    },
    { key: 'department', title: t('hrm.reports.byDepartment'), items: named(props.headcount.by_department) },
    { key: 'branch', title: t('hrm.reports.byBranch'), items: named(props.headcount.by_branch) },
    { key: 'designation', title: t('hrm.reports.byDesignation'), items: named(props.headcount.by_designation) },
].filter((group) => group.items.length > 0));

function named(items: GroupCount[]): { label: string; count: number }[] {
    return items.map((item) => ({ label: item.name ?? t('hrm.reports.unassigned'), count: item.count }));
}

const attendanceColumns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.employee') },
    { key: 'present', label: t('hrm.status.day.present'), align: 'right' },
    { key: 'absent', label: t('hrm.status.day.absent'), align: 'right' },
    { key: 'onLeave', label: t('hrm.status.day.on_leave'), align: 'right', hideable: true },
    { key: 'lateDays', label: t('hrm.fields.lateDays'), align: 'right', hideable: true },
    { key: 'worked', label: t('hrm.fields.worked'), align: 'right' },
    { key: 'overtime', label: t('hrm.fields.overtime'), align: 'right', hideable: true, hiddenByDefault: true },
]);

const leaveColumns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.leaveType') },
    { key: 'granted', label: t('hrm.reports.granted'), align: 'right' },
    { key: 'used', label: t('hrm.reports.used'), align: 'right' },
    { key: 'pending', label: t('hrm.reports.pending'), align: 'right' },
    { key: 'balance', label: t('hrm.reports.balance'), align: 'right' },
]);

function apply(): void {
    router.get(route('hrm.reports.index'), {
        from: from.value || undefined,
        to: to.value || undefined,
        department_id: department.value || undefined,
        year: year.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.reports.title')" :description="t('hrm.reports.description')" />

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <Input v-model="from" type="date" :label="t('hrm.fields.from')" class="w-full sm:w-40" />
            <Input v-model="to" type="date" :label="t('hrm.fields.to')" class="w-full sm:w-40" />
            <Select v-model="department" :options="departmentOptions" :label="t('hrm.fields.department')" class="w-full sm:w-48" />
            <Input v-model="year" type="number" min="2000" max="2100" :label="t('hrm.fields.leaveYear')" class="w-full sm:w-32" />
            <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
        </form>
        <p class="mt-2 text-xs text-ink-subtle">{{ t('hrm.reports.periodHint', { count: format.number(maxDays) }) }}</p>
    </Card>

    <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatTile v-for="tile in tiles" :key="tile.key" :label="tile.label" :value="tile.value" />
    </div>

    <div class="mb-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <Card v-for="group in groups" :key="group.key" :title="group.title">
            <ul class="divide-y divide-line">
                <li v-for="item in group.items" :key="item.label" class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0 text-sm">
                    <span class="min-w-0 truncate text-ink">{{ item.label }}</span>
                    <span class="tabular text-ink-muted">{{ format.number(item.count) }}</span>
                </li>
            </ul>
        </Card>
    </div>

    <Card class="mb-3" flush :title="t('hrm.reports.attendanceTitle')" :description="t('hrm.reports.attendanceHint', { from: format.date(from), to: format.date(to) })">
        <DataTable :columns="attendanceColumns" :rows="attendance.data" row-key="employeeId" :caption="t('hrm.reports.attendanceTitle')" storage-key="hrm-report-attendance">
            <template #cell-name="{ row }">
                <span class="block text-ink">{{ row.name }}</span>
                <span class="tabular block text-xs text-ink-subtle">{{ row.employeeNumber }}</span>
            </template>
            <template #cell-present="{ row }"><span class="tabular">{{ format.number(row.present) }}</span></template>
            <template #cell-absent="{ row }"><span :class="['tabular', row.absent > 0 ? 'text-danger' : 'text-ink-muted']">{{ format.number(row.absent) }}</span></template>
            <template #cell-onLeave="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.onLeave) }}</span></template>
            <template #cell-lateDays="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.lateDays) }}</span></template>
            <template #cell-worked="{ row }"><span class="tabular">{{ hrm.duration(row.workedMinutes) }}</span></template>
            <template #cell-overtime="{ row }"><span class="tabular text-ink-muted">{{ hrm.duration(row.overtimeMinutes) }}</span></template>
            <template #empty><EmptyState :title="t('hrm.reports.attendanceEmpty')" compact /></template>
        </DataTable>
        <Pagination :meta="attendance" />
    </Card>

    <div class="grid gap-3 lg:grid-cols-2">
        <Card flush :title="t('hrm.reports.leaveTitle', { year: format.number(leave.year, { useGrouping: false }) })">
            <DataTable :columns="leaveColumns" :rows="leave.by_type" row-key="leave_type_id" :caption="t('hrm.reports.leaveTitle', { year: format.number(leave.year, { useGrouping: false }) })">
                <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                <template #cell-granted="{ row }"><span class="tabular">{{ hrm.days(row.granted) }}</span></template>
                <template #cell-used="{ row }"><span class="tabular">{{ hrm.days(row.used) }}</span></template>
                <template #cell-pending="{ row }"><span class="tabular text-ink-muted">{{ hrm.days(row.pending) }}</span></template>
                <template #cell-balance="{ row }"><span class="tabular text-ink">{{ hrm.days(row.balance) }}</span></template>
                <template #empty><EmptyState :title="t('hrm.reports.leaveEmpty')" compact /></template>
            </DataTable>
        </Card>

        <Card :title="t('hrm.reports.leaveByDepartment')" :description="t('hrm.reports.leaveByDepartmentHint')">
            <p v-if="leave.by_department.length === 0" class="text-sm text-ink-muted">{{ t('hrm.reports.leaveEmpty') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="item in leave.by_department" :key="item.department_id ?? 'none'" class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0 text-sm">
                    <span class="text-ink">{{ item.name ?? t('hrm.reports.unassigned') }}</span>
                    <span class="tabular text-ink-muted">{{ hrm.days(item.units) }}</span>
                </li>
            </ul>
        </Card>
    </div>
</template>
