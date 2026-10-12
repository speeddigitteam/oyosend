<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarDays } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AdjustBalanceDialog from '../../Components/AdjustBalanceDialog.vue';
import HrmSubNav from '../../Components/HrmSubNav.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { LeaveType, Paginated, PersonRef } from '../../Components/types';

defineOptions({ layout: TenantLayout });

interface Row {
    employee: PersonRef;
    balances: Record<string, number>;
}

const props = defineProps<{
    year: number;
    rows: Paginated<Row>;
    types: LeaveType[];
    canAdjust: boolean;
}>();

const { t } = useI18n();
const hrm = useHrmFormat();

const yearInput = ref(String(props.year));
const adjusting = ref<PersonRef | null>(null);
const adjustOpen = ref(false);

const tableRows = computed(() => props.rows.data.map((row) => ({ ...row, id: row.employee.id })));

const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('hrm.fields.employee') },
    ...props.types.map((type) => ({ key: `type_${type.id}`, label: type.name, align: 'right' as const })),
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' as const },
]);

function apply(): void {
    router.get(route('hrm.leave.balances'), { year: yearInput.value || undefined }, { preserveState: true, replace: true });
}

function adjust(person: PersonRef): void {
    adjusting.value = person;
    adjustOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.balances.title')" :description="t('hrm.balances.description')" />
    <HrmSubNav area="leave" current="balances" />

    <Card flush>
        <DataTable :columns="columns" :rows="tableRows" row-key="id" :caption="t('hrm.balances.caption')">
            <template #toolbar>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="yearInput" type="number" min="2000" max="2100" :label="t('hrm.fields.leaveYear')" class="w-full sm:w-32" />
                    <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
                </form>
            </template>
            <template #cell-employee="{ row }">
                <span class="block text-ink">{{ row.employee.name }}</span>
                <span class="tabular block text-xs text-ink-subtle">{{ row.employee.number }}</span>
            </template>
            <template v-for="type in types" :key="type.id" #[`cell-type_${type.id}`]="{ row }">
                <span class="tabular text-ink" :data-testid="`balance-${type.id}`">{{ hrm.days(row.balances[type.id] ?? 0) }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canAdjust" class="flex justify-end">
                    <Button size="sm" variant="ghost" @click="adjust(row.employee)">{{ t('hrm.balances.adjust.action') }}</Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('hrm.balances.empty')" :description="t('hrm.balances.emptyBody')">
                    <template #icon><CalendarDays /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="rows" />
    </Card>

    <AdjustBalanceDialog v-if="canAdjust" v-model:open="adjustOpen" :employee="adjusting" :types="types" :year="year" />
</template>
