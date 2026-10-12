<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Clock, Plus, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import HrmSubNav from '../../Components/HrmSubNav.vue';
import ManualEntryDialog from '../../Components/ManualEntryDialog.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { AttendanceDay, NamedRef, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

interface Row {
    employee: { id: string; number: string; name: string; department: string | null };
    day: AttendanceDay | null;
    clockedIn: boolean;
}

const props = defineProps<{
    date: string;
    today: string;
    rows: Paginated<Row>;
    totals: Record<string, number>;
    statuses: string[];
    filters: { q: string; department_id: string; status: string };
    departments: NamedRef[];
    abilities: { manage: boolean; clock: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const date = ref(props.date);
const q = ref(props.filters.q);
const department = ref(props.filters.department_id);
const status = ref(props.filters.status);
const loading = ref(false);
const entryFor = ref<Row['employee'] | null>(null);
const entryOpen = ref(false);

const statusOptions = computed(() => [
    { value: '', label: t('hrm.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`hrm.status.day.${value}`) })),
]);
const departmentOptions = computed(() => [{ value: '', label: t('hrm.common.all') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);
const tableRows = computed(() => props.rows.data.map((row) => ({ ...row, id: row.employee.id })));
const filtered = computed(() => q.value !== '' || department.value !== '' || status.value !== '');

const headline = computed(() => (['present', 'half_day', 'absent', 'on_leave', 'incomplete'] as const).map((key) => ({
    key,
    label: t(`hrm.status.day.${key}`),
    value: format.number(props.totals[key] ?? 0),
    accent: key === 'absent' && (props.totals[key] ?? 0) > 0,
})));

const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('hrm.fields.employee') },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'in', label: t('hrm.fields.clockIn'), hideable: true },
    { key: 'out', label: t('hrm.fields.clockOut'), hideable: true },
    { key: 'worked', label: t('hrm.fields.worked'), align: 'right' },
    { key: 'late', label: t('hrm.fields.late'), align: 'right', hideable: true },
    { key: 'overtime', label: t('hrm.fields.overtime'), align: 'right', hideable: true, hiddenByDefault: true },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('hrm.attendance.index'), {
        date: date.value || undefined,
        q: q.value || undefined,
        department_id: department.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    q.value = '';
    department.value = '';
    status.value = '';
    apply();
}

function addEntry(row: Row): void {
    entryFor.value = row.employee;
    entryOpen.value = true;
}

function time(value: string | null | undefined): string {
    return value ? format.date(value, 'time') : t('hrm.common.dash');
}
</script>

<template>
    <PageHeader
        :eyebrow="t('hrm.eyebrow')"
        :title="t('hrm.attendance.title')"
        :description="t('hrm.attendance.description', { date: format.date(date) })"
    />
    <HrmSubNav area="attendance" current="day" />

    <div class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-testid="attendance-totals">
        <StatTile v-for="item in headline" :key="item.key" :label="item.label" :value="item.value" :accent="item.accent" />
    </div>

    <Card flush>
        <DataTable :columns="columns" :rows="tableRows" row-key="id" :caption="t('hrm.attendance.caption')" :loading="loading" storage-key="hrm-attendance">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="date" type="date" :label="t('hrm.fields.date')" :max="today" class="w-full sm:w-40" />
                    <Input v-model="q" type="search" :label="t('hrm.attendance.search')" class="min-w-40 flex-1">
                        <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    </Input>
                    <Select v-model="department" :options="departmentOptions" :label="t('hrm.fields.department')" class="w-full sm:w-44" />
                    <Select v-model="status" :options="statusOptions" :label="t('hrm.fields.status')" class="w-full sm:w-40" />
                    <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('hrm.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-employee="{ row }">
                <Link :href="route('hrm.attendance.employee', { employee: row.employee.id })" class="block text-ink hover:text-brand-text">{{ row.employee.name }}</Link>
                <span class="tabular block text-xs text-ink-subtle">{{ row.employee.number }}<template v-if="row.employee.department"> - {{ row.employee.department }}</template></span>
            </template>
            <template #cell-status="{ row }">
                <div class="flex flex-wrap items-center gap-1.5">
                    <HrmStatusBadge kind="day" :value="row.day?.status ?? 'not_recorded'" />
                    <Badge v-if="row.clockedIn" tone="ok">{{ t('hrm.attendance.clockedIn') }}</Badge>
                </div>
            </template>
            <template #cell-in="{ row }"><span class="tabular text-ink-muted">{{ time(row.day?.firstInAt) }}</span></template>
            <template #cell-out="{ row }"><span class="tabular text-ink-muted">{{ time(row.day?.lastOutAt) }}</span></template>
            <template #cell-worked="{ row }"><span class="tabular text-ink">{{ row.day ? hrm.duration(row.day.workedMinutes) : t('hrm.common.dash') }}</span></template>
            <template #cell-late="{ row }">
                <span :class="['tabular', row.day && row.day.lateMinutes > 0 ? 'text-warn' : 'text-ink-muted']">
                    {{ row.day && row.day.lateMinutes > 0 ? hrm.duration(row.day.lateMinutes) : t('hrm.common.dash') }}
                </span>
            </template>
            <template #cell-overtime="{ row }">
                <span class="tabular text-ink-muted">{{ row.day && row.day.overtimeMinutes > 0 ? hrm.duration(row.day.overtimeMinutes) : t('hrm.common.dash') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="abilities.manage" class="flex justify-end">
                    <Button size="sm" variant="ghost" @click="addEntry(row)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.entry.add') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('hrm.common.noMatches') : t('hrm.attendance.empty')"
                    :description="filtered ? t('hrm.common.noMatchesBody') : t('hrm.attendance.emptyBody')"
                >
                    <template #icon><Clock /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="rows" />
    </Card>

    <ManualEntryDialog v-if="abilities.manage" v-model:open="entryOpen" :employee="entryFor" :date="date" />
</template>
