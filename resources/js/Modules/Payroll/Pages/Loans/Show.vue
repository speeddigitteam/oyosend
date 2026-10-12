<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { EmployeeRef, Installment, Loan } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    loan: Loan;
    employee: EmployeeRef | null;
    installments: Installment[];
    outstandingAmount: number;
    abilities: { approve: boolean; cancel: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const deciding = ref<'approve' | 'reject' | null>(null);
const cancelling = ref(false);
const busy = ref(false);

const decisionOpen = computed({
    get: () => deciding.value !== null,
    set: (value: boolean) => { if (!value) deciding.value = null; },
});
const columns = computed<Column[]>(() => [
    { key: 'sequence', label: t('payroll.loans.sequence') },
    { key: 'dueOn', label: t('payroll.fields.dueOn') },
    { key: 'amount', label: t('payroll.fields.amount'), align: 'right' },
    { key: 'status', label: t('payroll.fields.status') },
]);

function cancel(): void {
    busy.value = true;
    router.post(route('payroll.loans.cancel', { loan: props.loan.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; cancelling.value = false; },
    });
}
</script>

<template>
    <PageHeader
        :eyebrow="t(`payroll.loanKinds.${loan.kind}`)"
        :title="loan.title"
        :description="employee ? `${employee.name} (${employee.number})` : undefined"
    >
        <template v-if="abilities.approve || abilities.cancel" #actions>
            <Button v-if="abilities.approve" variant="primary" @click="deciding = 'approve'">
                <template #icon><Check class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.loans.approve') }}
            </Button>
            <Button v-if="abilities.approve" @click="deciding = 'reject'">
                <template #icon><X class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.loans.reject') }}
            </Button>
            <Button v-if="abilities.cancel" variant="ghost" @click="cancelling = true">{{ t('payroll.loans.cancel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-3">
        <Card :title="t('payroll.loans.summary')">
            <dl class="grid gap-3 text-sm">
                <div><dt class="eyebrow">{{ t('payroll.fields.status') }}</dt><dd class="mt-1"><PayrollBadge kind="loan" :value="loan.status" /></dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.principal') }}</dt><dd class="tabular mt-0.5 font-mono text-ink">{{ pay.money(loan.principalAmount, loan.currency) }}</dd></div>
                <div>
                    <dt class="eyebrow">{{ t('payroll.loans.outstanding') }}</dt>
                    <dd class="figure mt-1 text-2xl text-ink" data-testid="outstanding">{{ pay.money(outstandingAmount, loan.currency) }}</dd>
                </div>
                <div><dt class="eyebrow">{{ t('payroll.fields.installments') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.number(loan.installmentCount) }}</dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.firstDue') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.date(loan.firstDueOn) }}</dd></div>
                <div v-if="loan.reason"><dt class="eyebrow">{{ t('payroll.fields.reason') }}</dt><dd class="mt-0.5 text-ink">{{ loan.reason }}</dd></div>
                <div v-if="loan.decisionNote"><dt class="eyebrow">{{ t('payroll.loans.decisionNote') }}</dt><dd class="mt-0.5 text-ink">{{ loan.decisionNote }}</dd></div>
                <div v-if="loan.decidedAt"><dt class="eyebrow">{{ t('payroll.loans.decidedAt') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.date(loan.decidedAt, 'datetime') }}</dd></div>
            </dl>
        </Card>

        <Card flush :title="t('payroll.loans.schedule')" :description="t('payroll.loans.scheduleBody')" class="lg:col-span-2">
            <DataTable :columns="columns" :rows="installments" row-key="id" :caption="t('payroll.loans.scheduleCaption')">
                <template #cell-sequence="{ row }"><span class="tabular font-mono text-ink">{{ format.number(row.sequence) }}</span></template>
                <template #cell-dueOn="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.dueOn) }}</span></template>
                <template #cell-amount="{ row }"><span class="tabular font-mono">{{ pay.money(row.amount, loan.currency) }}</span></template>
                <template #cell-status="{ row }"><PayrollBadge kind="installment" :value="row.status" /></template>
                <template #empty>
                    <p class="px-5 py-6 text-sm text-ink-subtle">{{ t('payroll.loans.noSchedule') }}</p>
                </template>
            </DataTable>
        </Card>
    </div>

    <DecisionDialog
        v-if="abilities.approve"
        v-model:open="decisionOpen"
        :url="route('payroll.loans.decision', { loan: loan.id })"
        :decision="deciding ?? 'approve'"
        :title="deciding === 'reject' ? t('payroll.loans.rejectTitle') : t('payroll.loans.approveTitle')"
        :description="deciding === 'reject' ? t('payroll.loans.rejectBody') : t('payroll.loans.approveBody')"
    />
    <ConfirmDialog
        v-if="abilities.cancel"
        v-model:open="cancelling"
        :title="t('payroll.loans.cancelTitle')"
        :description="t('payroll.loans.cancelBody')"
        :confirm-label="t('payroll.loans.cancel')"
        :loading="busy"
        @confirm="cancel"
    />
</template>
