<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Clock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
import HrmSubNav from '../../Components/HrmSubNav.vue';
import MonthSwitcher from '../../Components/MonthSwitcher.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { AttendanceSummaryRow, NamedRef, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    month: string;
    from: string;
    to: string;
    rows: Paginated<AttendanceSummaryRow>;
    filters: { department_id: string };
    departments: NamedRef[];
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const department = ref(props.filters.department_id);
const departmentOptions = computed(() => [{ value: '', label: t('hrm.common.all') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.employee') },
    { key: 'present', label: t('hrm.status.day.present'), align: 'right' },
    { key: 'halfDay', label: t('hrm.status.day.half_day'), align: 'right', hideable: true },
    { key: 'absent', label: t('hrm.status.day.absent'), align: 'right' },
    { key: 'onLeave', label: t('hrm.status.day.on_leave'), align: 'right', hideable: true },
    { key: 'lateDays', label: t('hrm.fields.lateDays'), align: 'right', hideable: true },
    { key: 'worked', label: t('hrm.fields.worked'), align: 'right' },
    { key: 'overtime', label: t('hrm.fields.overtime'), align: 'right', hideable: true, hiddenByDefault: true },
]);

const query = computed<Record<string, string>>(() => (department.value === '' ? ({} as Record<string, string>) : { department_id: department.value }));

function apply(): void {
    router.get(route('hrm.attendance.summary'), { month: props.month, ...query.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.summary.title')" :description="t('hrm.summary.description')">
        <template #actions>
            <MonthSwitcher :month="month" name="hrm.attendance.summary" :query="query" />
        </template>
    </PageHeader>
    <HrmSubNav area="attendance" current="summary" />

    <Card flush>
        <DataTable :columns="columns" :rows="rows.data" row-key="employeeId" :caption="t('hrm.summary.caption')" storage-key="hrm-attendance-summary">
            <template #toolbar>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Select v-model="department" :options="departmentOptions" :label="t('hrm.fields.department')" class="w-full sm:w-52" />
                    <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
                </form>
                <p class="ml-auto text-xs text-ink-subtle">{{ t('hrm.summary.closedOnly', { from: format.date(from), to: format.date(to) }) }}</p>
            </template>
            <template #cell-name="{ row }">
                <Link :href="route('hrm.attendance.employee', { employee: row.employeeId, month })" class="block text-ink hover:text-brand-text">{{ row.name }}</Link>
                <span class="tabular block text-xs text-ink-subtle">{{ row.employeeNumber }}</span>
            </template>
            <template #cell-present="{ row }"><span class="tabular">{{ format.number(row.present) }}</span></template>
            <template #cell-halfDay="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.halfDay) }}</span></template>
            <template #cell-absent="{ row }"><span :class="['tabular', row.absent > 0 ? 'text-danger' : 'text-ink-muted']">{{ format.number(row.absent) }}</span></template>
            <template #cell-onLeave="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.onLeave) }}</span></template>
            <template #cell-lateDays="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.lateDays) }}</span></template>
            <template #cell-worked="{ row }"><span class="tabular">{{ hrm.duration(row.workedMinutes) }}</span></template>
            <template #cell-overtime="{ row }"><span class="tabular text-ink-muted">{{ hrm.duration(row.overtimeMinutes) }}</span></template>
            <template #empty>
                <EmptyState :title="t('hrm.summary.empty')" :description="t('hrm.summary.emptyBody')">
                    <template #icon><Clock /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="rows" />
    </Card>
</template>
