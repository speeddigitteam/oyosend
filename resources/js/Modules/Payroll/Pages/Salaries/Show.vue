<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AccountForm from '../../Components/AccountForm.vue';
import SalaryDialog from '../../Components/SalaryDialog.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { Account, Assignment, EmployeeRef, NamedOption, PaymentMethod } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    profile: { id: string; employeeId: string };
    employee: EmployeeRef | null;
    assignments: Assignment[];
    account: Account;
    structures: NamedOption[];
    paymentMethods: PaymentMethod[];
    currency: string;
    today: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const revising = ref(false);

// Newest revision first; the one in force today is the latest that has begun.
const rows = computed(() => [...props.assignments].sort((a, b) => b.revision - a.revision));
const currentId = computed(() => props.assignments
    .filter((row) => row.effectiveFrom <= props.today)
    .sort((a, b) => b.effectiveFrom.localeCompare(a.effectiveFrom))[0]?.id ?? null);
const latest = computed(() => rows.value[0] ?? null);
const columns = computed<Column[]>(() => [
    { key: 'revision', label: t('payroll.salaries.revision') },
    { key: 'effectiveFrom', label: t('payroll.fields.effectiveFrom') },
    { key: 'basic', label: t('payroll.fields.basic'), align: 'right' },
    { key: 'structure', label: t('payroll.fields.structure'), hideable: true },
    { key: 'reason', label: t('payroll.fields.reason'), hideable: true },
]);
</script>

<template>
    <PageHeader
        :eyebrow="t('payroll.salaries.title')"
        :title="employee?.name ?? t('payroll.common.unknownEmployee')"
        :description="employee ? [employee.number, employee.designation, employee.department].filter(Boolean).join(' - ') : undefined"
    >
        <template v-if="canManage && structures.length > 0" #actions>
            <Button variant="primary" @click="revising = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.salaries.newRevision') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3">
        <Card flush :title="t('payroll.salaries.history')" :description="t('payroll.salaries.historyBody')">
            <DataTable :columns="columns" :rows="rows" row-key="id" :caption="t('payroll.salaries.historyCaption')">
                <template #cell-revision="{ row }">
                    <span class="tabular font-mono text-ink">{{ format.number(row.revision) }}</span>
                    <Badge v-if="row.id === currentId" tone="ok" class="ml-2">{{ t('payroll.salaries.current') }}</Badge>
                    <Badge v-else-if="row.effectiveFrom > today" tone="info" class="ml-2">{{ t('payroll.salaries.scheduled') }}</Badge>
                </template>
                <template #cell-effectiveFrom="{ row }"><span class="tabular text-ink">{{ format.date(row.effectiveFrom) }}</span></template>
                <template #cell-basic="{ row }"><span class="tabular font-mono">{{ pay.money(row.basicAmount, row.currency) }}</span></template>
                <template #cell-structure="{ row }"><span class="text-ink-muted">{{ row.structureName ?? t('payroll.common.dash') }}</span></template>
                <template #cell-reason="{ row }"><span class="text-ink-muted">{{ row.reason ?? t('payroll.common.dash') }}</span></template>
                <template #empty>
                    <EmptyState :title="t('payroll.salaries.noHistory')" :description="t('payroll.salaries.noHistoryBody')" />
                </template>
            </DataTable>
        </Card>

        <AccountForm :profile-id="profile.id" :account="account" :methods="paymentMethods" :can-manage="canManage" />
    </div>

    <SalaryDialog
        v-if="canManage && structures.length > 0"
        v-model:open="revising"
        :url="route('payroll.salaries.revisions.store', { profile: profile.id })"
        :structures="structures"
        :currency="currency"
        :today="today"
        :structure-id="latest?.structureId ?? null"
        :basic-amount="latest?.basicAmount ?? null"
    />
</template>
