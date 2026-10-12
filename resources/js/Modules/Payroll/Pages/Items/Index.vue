<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Gift, Pencil, Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import ItemDialog from '../../Components/ItemDialog.vue';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { EmployeeOption, Item, Period } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    items: Paginated<Item>;
    filters: { period_id: string; employee_id: string; status: string; type: string };
    periods: Period[];
    employees: EmployeeOption[];
    types: string[];
    statuses: string[];
    currency: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const pay = usePayrollFormat();

const period = ref(props.filters.period_id);
const status = ref(props.filters.status);
const type = ref(props.filters.type);
const loading = ref(false);
const formOpen = ref(false);
const editing = ref<Item | null>(null);
const cancelling = ref<Item | null>(null);
const busy = ref(false);

const periodOptions = computed(() => [{ value: '', label: t('payroll.common.all') }, ...props.periods.map((item) => ({ value: item.id, label: item.name }))]);
const statusOptions = computed(() => [{ value: '', label: t('payroll.common.all') }, ...props.statuses.map((value) => ({ value, label: t(`payroll.status.item.${value}`) }))]);
const typeOptions = computed(() => [{ value: '', label: t('payroll.common.all') }, ...props.types.map((value) => ({ value, label: t(`payroll.itemTypes.${value}`) }))]);
const filtered = computed(() => period.value !== '' || status.value !== '' || type.value !== '' || props.filters.employee_id !== '');
const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('payroll.fields.employee') },
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'type', label: t('payroll.fields.type'), hideable: true },
    { key: 'amount', label: t('payroll.fields.amount'), align: 'right' },
    { key: 'period', label: t('payroll.fields.period'), hideable: true },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('payroll.items.index'), {
        period_id: period.value || undefined,
        status: status.value || undefined,
        type: type.value || undefined,
        employee_id: props.filters.employee_id || undefined,
    }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    period.value = '';
    status.value = '';
    type.value = '';
    router.get(route('payroll.items.index'), {}, { preserveState: true, replace: true });
}

function add(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(item: Item): void {
    editing.value = item;
    formOpen.value = true;
}

function cancel(): void {
    if (cancelling.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('payroll.items.destroy', { item: cancelling.value.id }), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; cancelling.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.items.title')" :description="t('payroll.items.description')">
        <template v-if="canManage && periods.length > 0 && employees.length > 0" #actions>
            <Button variant="primary" @click="add">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.items.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="items.data" row-key="id" :caption="t('payroll.items.caption')" :loading="loading" storage-key="payroll-items">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Select v-model="period" :options="periodOptions" :label="t('payroll.fields.period')" class="w-full sm:w-48" />
                    <Select v-model="type" :options="typeOptions" :label="t('payroll.fields.type')" class="w-full sm:w-44" />
                    <Select v-model="status" :options="statusOptions" :label="t('payroll.fields.status')" class="w-full sm:w-40" />
                    <Button type="submit" size="sm">{{ t('payroll.common.apply') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('payroll.common.clearFilters') }}</Button>
                </form>
            </template>
            <template #cell-employee="{ row }">
                <span class="block max-w-56 truncate text-ink">{{ row.employee?.name ?? t('payroll.common.unknownEmployee') }}</span>
                <span v-if="row.employee" class="block text-xs text-ink-subtle">{{ row.employee.number }}</span>
            </template>
            <template #cell-name="{ row }">
                <span class="text-ink">{{ row.name }}</span>
                <Badge v-if="row.isTaxable" class="ml-1">{{ t('payroll.fields.taxable') }}</Badge>
            </template>
            <template #cell-type="{ row }"><span class="text-ink-muted">{{ t(`payroll.itemTypes.${row.type}`) }}</span></template>
            <template #cell-amount="{ row }">
                <span v-if="row.amount !== null" class="tabular font-mono">{{ pay.money(row.amount, currency) }}</span>
                <span v-else class="tabular font-mono">{{ t('payroll.structures.percentOf', { rate: pay.percent(row.basicBps ?? 0) }) }}</span>
            </template>
            <template #cell-period="{ row }"><span class="text-ink-muted">{{ row.periodName ?? t('payroll.common.dash') }}</span></template>
            <template #cell-status="{ row }"><PayrollBadge kind="item" :value="row.status" /></template>
            <template #cell-actions="{ row }">
                <div v-if="canManage && row.status === 'pending'" class="flex justify-end gap-1">
                    <Button size="sm" variant="ghost" @click="edit(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('payroll.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="cancelling = row">
                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                        {{ t('payroll.items.cancel') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="filtered ? t('payroll.common.noMatches') : t('payroll.items.empty')" :description="filtered ? t('payroll.common.noMatchesBody') : t(canManage ? 'payroll.items.emptyBody' : 'payroll.items.emptyViewOnly')">
                    <template #icon><Gift /></template>
                    <Button v-if="canManage && !filtered && periods.length > 0 && employees.length > 0" variant="primary" @click="add">{{ t('payroll.items.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="items" />
    </Card>

    <template v-if="canManage">
        <ItemDialog v-model:open="formOpen" :item="editing" :employees="employees" :periods="periods" :types="types" :currency="currency" />
        <ConfirmDialog
            :open="cancelling !== null"
            :title="t('payroll.items.cancelTitle')"
            :description="t('payroll.items.cancelBody', { name: cancelling?.name ?? '' })"
            :confirm-label="t('payroll.items.cancel')"
            :loading="busy"
            @update:open="!$event && (cancelling = null)"
            @confirm="cancel"
        />
    </template>
</template>
