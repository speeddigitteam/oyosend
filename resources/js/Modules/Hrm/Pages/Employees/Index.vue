<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, Search, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
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
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import type { EmployeeRow, EmployeeStatus, EmploymentType, NamedRef, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    employees: Paginated<EmployeeRow>;
    filters: { q: string; status: string; department_id: string; branch_id: string; manager_id: string; employment_type: string };
    statuses: EmployeeStatus[];
    employmentTypes: EmploymentType[];
    departments: NamedRef[];
    branches: NamedRef[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const q = ref(props.filters.q);
const status = ref(props.filters.status);
const department = ref(props.filters.department_id);
const branch = ref(props.filters.branch_id);
const type = ref(props.filters.employment_type);
const loading = ref(false);

const statusOptions = computed(() => [
    { value: '', label: t('hrm.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`hrm.status.employee.${value}`) })),
]);
const typeOptions = computed(() => [
    { value: '', label: t('hrm.common.all') },
    ...props.employmentTypes.map((value) => ({ value, label: t(`hrm.employmentType.${value}`) })),
]);
const departmentOptions = computed(() => [{ value: '', label: t('hrm.common.all') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);
const branchOptions = computed(() => [{ value: '', label: t('hrm.common.all') }, ...props.branches.map((item) => ({ value: item.id, label: item.name }))]);

const filtered = computed(() => [q, status, department, branch, type].some((item) => item.value !== ''));

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.employee') },
    { key: 'number', label: t('hrm.fields.employeeNumber'), hideable: true },
    { key: 'designation', label: t('hrm.fields.designation'), hideable: true },
    { key: 'department', label: t('hrm.fields.department'), hideable: true },
    { key: 'manager', label: t('hrm.fields.manager'), hideable: true, hiddenByDefault: true },
    { key: 'joinedOn', label: t('hrm.fields.joinedOn'), hideable: true, hiddenByDefault: true },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);

function initials(row: EmployeeRow): string {
    return `${row.firstName.charAt(0)}${row.lastName?.charAt(0) ?? ''}`.toUpperCase();
}

function apply(): void {
    router.get(route('hrm.employees.index'), {
        q: q.value || undefined,
        status: status.value || undefined,
        department_id: department.value || undefined,
        branch_id: branch.value || undefined,
        employment_type: type.value || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    q.value = '';
    status.value = '';
    department.value = '';
    branch.value = '';
    type.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.employees.title')" :description="t('hrm.employees.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" :href="route('hrm.employees.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.employees.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="employees.data" row-key="id" :caption="t('hrm.employees.caption')" :loading="loading" storage-key="hrm-employees">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="q" type="search" :label="t('hrm.employees.search')" class="min-w-48 flex-1">
                        <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    </Input>
                    <Select v-model="status" :options="statusOptions" :label="t('hrm.fields.status')" class="w-full sm:w-40" />
                    <Select v-model="department" :options="departmentOptions" :label="t('hrm.fields.department')" class="w-full sm:w-44" />
                    <Select v-model="branch" :options="branchOptions" :label="t('hrm.fields.branch')" class="w-full sm:w-40" />
                    <Select v-model="type" :options="typeOptions" :label="t('hrm.fields.employmentType')" class="w-full sm:w-40" />
                    <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('hrm.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-name="{ row }">
                <div class="flex min-w-0 items-center gap-3">
                    <Avatar :initials="initials(row)" :name="row.name" size="sm" />
                    <div class="min-w-0">
                        <Button variant="ghost" size="sm" :href="route('hrm.employees.show', { employee: row.id })" class="-ml-3 max-w-64 truncate">{{ row.name }}</Button>
                        <span v-if="row.workEmail" class="-mt-1 block max-w-64 truncate text-xs text-ink-subtle">{{ row.workEmail }}</span>
                    </div>
                </div>
            </template>
            <template #cell-number="{ row }"><span class="tabular font-mono text-ink-muted">{{ row.number }}</span></template>
            <template #cell-designation="{ row }"><span class="text-ink-muted">{{ row.designation?.name ?? t('hrm.common.dash') }}</span></template>
            <template #cell-department="{ row }"><span class="text-ink-muted">{{ row.department?.name ?? t('hrm.common.dash') }}</span></template>
            <template #cell-manager="{ row }"><span class="text-ink-muted">{{ row.managerName ?? t('hrm.common.dash') }}</span></template>
            <template #cell-joinedOn="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.joinedOn) }}</span></template>
            <template #cell-status="{ row }"><HrmStatusBadge kind="employee" :value="row.status" /></template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('hrm.employees.show', { employee: row.id })">{{ t('hrm.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('hrm.common.noMatches') : t('hrm.employees.empty')"
                    :description="filtered ? t('hrm.common.noMatchesBody') : t(canCreate ? 'hrm.employees.emptyBody' : 'hrm.employees.emptyViewOnly')"
                >
                    <template #icon><UsersRound /></template>
                    <Button v-if="canCreate && !filtered" variant="primary" :href="route('hrm.employees.create')">{{ t('hrm.employees.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="employees" />
    </Card>
</template>
