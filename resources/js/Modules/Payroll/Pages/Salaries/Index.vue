<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Banknote, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import SalaryDialog from '../../Components/SalaryDialog.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import { todayIn } from '../../Components/payrollMoney';
import type { Assignment, EmployeeOption, EmployeeRef, NamedOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

interface ProfileRow {
    id: string;
    employee: EmployeeRef | null;
    current: Assignment | null;
    upcoming: Assignment | null;
    hasAccount: boolean;
}

const props = defineProps<{
    profiles: Paginated<ProfileRow>;
    filters: { department_id: string };
    departments: NamedOption[];
    unassigned: EmployeeOption[];
    structures: NamedOption[];
    currency: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();
const { can } = useAccess();
const page = usePage();

const department = ref(props.filters.department_id);
const loading = ref(false);
const assigning = ref(false);
const preselected = ref<string | undefined>(undefined);

const departmentOptions = computed(() => [
    { value: '', label: t('payroll.common.all') },
    ...props.departments.map((item) => ({ value: item.id, label: item.name })),
]);
const canAssign = computed(() => props.canManage && props.structures.length > 0 && props.unassigned.length > 0);
const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('payroll.fields.employee') },
    { key: 'department', label: t('payroll.fields.department'), hideable: true },
    { key: 'basic', label: t('payroll.fields.basic'), align: 'right' },
    { key: 'structure', label: t('payroll.fields.structure'), hideable: true },
    { key: 'effective', label: t('payroll.fields.effectiveFrom'), hideable: true },
    { key: 'account', label: t('payroll.salaries.account'), hideable: true },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('payroll.salaries.index'), { department_id: department.value || undefined }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function assign(employeeId?: string): void {
    preselected.value = employeeId;
    assigning.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.salaries.title')" :description="t('payroll.salaries.description')">
        <template v-if="canAssign" #actions>
            <Button variant="primary" @click="assign()">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.salaries.assign') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3">
        <Card v-if="canManage && structures.length === 0" :title="t('payroll.salaries.needStructure')">
            <p class="text-sm text-ink-muted">{{ t('payroll.salaries.needStructureBody') }}</p>
            <div v-if="can('payroll.structures.view')" class="mt-3">
                <Button size="sm" :href="route('payroll.structures.index')">{{ t('payroll.salaries.openStructures') }}</Button>
            </div>
        </Card>

        <Card flush>
            <DataTable :columns="columns" :rows="profiles.data" row-key="id" :caption="t('payroll.salaries.caption')" :loading="loading" storage-key="payroll-salaries">
                <template #toolbar>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                        <Select v-model="department" :options="departmentOptions" :label="t('payroll.fields.department')" class="w-full sm:w-56" />
                        <Button type="submit" size="sm">{{ t('payroll.common.apply') }}</Button>
                    </form>
                </template>
                <template #cell-employee="{ row }">
                    <Button variant="ghost" size="sm" :href="route('payroll.salaries.show', { profile: row.id })">
                        {{ row.employee?.name ?? t('payroll.common.unknownEmployee') }}
                    </Button>
                    <span v-if="row.employee" class="ml-1 text-xs text-ink-subtle">{{ row.employee.number }}</span>
                </template>
                <template #cell-department="{ row }"><span class="text-ink-muted">{{ row.employee?.department ?? t('payroll.common.dash') }}</span></template>
                <template #cell-basic="{ row }">
                    <span v-if="row.current" class="tabular font-mono">{{ pay.money(row.current.basicAmount, row.current.currency) }}</span>
                    <span v-else class="text-ink-subtle">{{ t('payroll.salaries.notEffective') }}</span>
                    <span v-if="row.upcoming" class="block text-xs text-ink-subtle">
                        {{ t('payroll.salaries.upcoming', { amount: pay.money(row.upcoming.basicAmount, row.upcoming.currency), date: format.date(row.upcoming.effectiveFrom) }) }}
                    </span>
                </template>
                <template #cell-structure="{ row }"><span class="text-ink-muted">{{ row.current?.structureName ?? t('payroll.common.dash') }}</span></template>
                <template #cell-effective="{ row }">
                    <span class="tabular text-ink-muted">{{ row.current ? format.date(row.current.effectiveFrom) : t('payroll.common.dash') }}</span>
                </template>
                <template #cell-account="{ row }">
                    <Badge :tone="row.hasAccount ? 'ok' : 'neutral'" dot>{{ row.hasAccount ? t('payroll.salaries.accountSet') : t('payroll.salaries.accountMissing') }}</Badge>
                </template>
                <template #cell-actions="{ row }">
                    <Button size="sm" variant="ghost" :href="route('payroll.salaries.show', { profile: row.id })">{{ t('payroll.common.view') }}</Button>
                </template>
                <template #empty>
                    <EmptyState :title="t('payroll.salaries.empty')" :description="t(canManage ? 'payroll.salaries.emptyBody' : 'payroll.salaries.emptyViewOnly')">
                        <template #icon><Banknote /></template>
                    </EmptyState>
                </template>
            </DataTable>
            <Pagination :meta="profiles" />
        </Card>

        <Card v-if="canManage && unassigned.length > 0" :title="t('payroll.salaries.unassigned')" :description="t('payroll.salaries.unassignedBody')">
            <ul class="grid gap-1" data-testid="unassigned-list">
                <li v-for="employee in unassigned" :key="employee.id" class="flex flex-wrap items-center justify-between gap-2 border-b border-line py-2 last:border-b-0">
                    <span class="text-sm text-ink">
                        {{ employee.name }}
                        <span class="text-xs text-ink-subtle">{{ employee.number }}<template v-if="employee.department"> - {{ employee.department }}</template></span>
                    </span>
                    <Button v-if="structures.length > 0" size="sm" variant="ghost" @click="assign(employee.id)">{{ t('payroll.salaries.setSalary') }}</Button>
                </li>
            </ul>
        </Card>
    </div>

    <SalaryDialog
        v-if="canAssign"
        v-model:open="assigning"
        :url="route('payroll.salaries.store')"
        :structures="structures"
        :currency="currency"
        :today="todayIn(page.props.app.timezone)"
        :employees="unassigned"
        :employee-id="preselected"
    />
</template>
