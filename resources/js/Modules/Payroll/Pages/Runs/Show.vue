<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Banknote, Check, Download, RefreshCw, Send, Undo2, Undo, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import IssueList from '../../Components/IssueList.vue';
import NoteDialog from '../../Components/NoteDialog.vue';
import PaymentDialog from '../../Components/PaymentDialog.vue';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import RunStepper from '../../Components/RunStepper.vue';
import type { Period, PayslipRow, RunDetail } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    run: RunDetail;
    period: Period;
    payslips: Paginated<PayslipRow>;
    filters: { q: string; payment_status: string };
    paymentStatuses: string[];
    approval: { requiresSeparateApprover: boolean; youPreparedOrSubmitted: boolean };
    abilities: {
        recalculate: boolean;
        submit: boolean;
        sendBack: boolean;
        approve: boolean;
        discard: boolean;
        reverse: boolean;
        recordPayments: boolean;
        exportPaymentSheet: boolean;
    };
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const busy = ref<string | null>(null);
const sendingBack = ref(false);
const reversing = ref(false);
const approving = ref(false);
const discarding = ref(false);
const paying = ref(false);
const search = ref(props.filters.q);
const status = ref(props.filters.payment_status);

const reversal = computed(() => props.run.kind === 'reversal');
const hasErrors = computed(() => props.run.errors.length > 0);
/** The submitted run is waiting, and the viewer can approve but is blocked by the four-eyes rule. */
const blockedByFourEyes = computed(() =>
    props.run.status === 'submitted' && props.approval.requiresSeparateApprover && props.approval.youPreparedOrSubmitted);
const canApproveNow = computed(() => props.run.status === 'submitted' && props.abilities.approve);
const hasActions = computed(() => Object.values(props.abilities).some(Boolean));

const totals = computed(() => [
    { key: 'gross', label: t('payroll.fields.gross'), amount: props.run.grossAmount },
    { key: 'deductions', label: t('payroll.fields.deductions'), amount: props.run.deductionAmount },
    { key: 'reimbursements', label: t('payroll.fields.reimbursements'), amount: props.run.reimbursementAmount },
    { key: 'employer', label: t('payroll.fields.employer'), amount: props.run.employerAmount },
]);
const statusOptions = computed(() => [
    { value: '', label: t('payroll.common.all') },
    ...props.paymentStatuses.map((value) => ({ value, label: t(`payroll.status.payment.${value}`) })),
]);
const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('payroll.fields.employee') },
    { key: 'department', label: t('payroll.fields.department'), hideable: true },
    { key: 'gross', label: t('payroll.fields.gross'), align: 'right', hideable: true },
    { key: 'deductions', label: t('payroll.fields.deductions'), align: 'right', hideable: true },
    { key: 'net', label: t('payroll.fields.net'), align: 'right' },
    { key: 'status', label: t('payroll.fields.paymentStatus') },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);
const paymentMethods = ['bank', 'cash', 'mobile_wallet', 'cheque', 'other'] as const;

function post(name: string, label: string, after?: () => void): void {
    busy.value = label;
    router.post(route(name, { run: props.run.id }), {}, {
        preserveScroll: true,
        onSuccess: after,
        onFinish: () => { busy.value = null; },
    });
}

function discard(): void {
    busy.value = 'discard';
    router.delete(route('payroll.runs.destroy', { run: props.run.id }), {
        onFinish: () => { busy.value = null; discarding.value = false; },
    });
}

function approve(): void {
    post('payroll.runs.approve', 'approve', () => { approving.value = false; });
}

function applyFilters(): void {
    router.get(route('payroll.runs.show', { run: props.run.id }), {
        q: search.value || undefined,
        payment_status: status.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('payroll.runs.eyebrow', { period: period.name })"
        :title="run.number ?? t('payroll.runs.draftNumber')"
        :description="t('payroll.runs.showDescription', { start: format.date(period.startsOn), end: format.date(period.endsOn) })"
    >
        <template v-if="hasActions" #actions>
            <Button v-if="abilities.recalculate" :loading="busy === 'recalculate'" @click="post('payroll.runs.recalculate', 'recalculate')">
                <template #icon><RefreshCw class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.recalculate') }}
            </Button>
            <Button v-if="abilities.submit" variant="primary" :loading="busy === 'submit'" @click="post('payroll.runs.submit', 'submit')">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.submit') }}
            </Button>
            <Button v-if="abilities.sendBack" @click="sendingBack = true">
                <template #icon><Undo2 class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.sendBack') }}
            </Button>
            <Button v-if="abilities.approve" variant="primary" @click="approving = true">
                <template #icon><Check class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.approve') }}
            </Button>
            <Button v-if="abilities.recordPayments" @click="paying = true">
                <template #icon><Banknote class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.payments.bulkTitle') }}
            </Button>
            <Button v-if="abilities.reverse" variant="danger" @click="reversing = true">
                <template #icon><Undo class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.reverse') }}
            </Button>
            <Button v-if="abilities.discard" variant="ghost" @click="discarding = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.discard') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3">
        <Card>
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <RunStepper :status="run.status" />
                <div class="flex flex-wrap items-center gap-2">
                    <PayrollBadge kind="runKind" :value="run.kind" />
                    <PayrollBadge kind="run" :value="run.status" />
                    <Badge v-if="run.isLocked" tone="neutral">{{ t('payroll.runs.locked') }}</Badge>
                    <Badge v-if="run.isReversed" tone="warn" data-testid="run-reversed">{{ t('payroll.runs.reversed') }}</Badge>
                </div>
            </div>
            <p v-if="reversal" class="mt-3 text-sm text-ink-muted" data-testid="reversal-note">
                {{ t('payroll.runs.reversalOf', { run: run.reversesRunNumber ?? t('payroll.common.dash') }) }}
            </p>
        </Card>

        <IssueList kind="errors" :issues="run.errors" :currency="run.currency" />
        <IssueList kind="warnings" :issues="run.warnings" :currency="run.currency" />

        <section
            v-if="run.status === 'submitted'"
            class="glass rounded-card px-5 py-3 text-sm"
            aria-live="polite"
            data-testid="approval-note"
        >
            <p v-if="blockedByFourEyes" class="text-ink">{{ t('payroll.runs.fourEyesBlocked') }}</p>
            <p v-else-if="canApproveNow && approval.requiresSeparateApprover" class="text-ink">{{ t('payroll.runs.fourEyesOk') }}</p>
            <p v-else-if="canApproveNow" class="text-ink">{{ t('payroll.runs.approveOpen') }}</p>
            <p v-else class="text-ink-muted">{{ t('payroll.runs.awaitingApproval') }}</p>
        </section>
        <p v-else-if="run.status === 'draft' && hasErrors" class="glass rounded-card px-5 py-3 text-sm text-ink" data-testid="submit-blocked">
            {{ t('payroll.runs.submitBlocked') }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="run-totals">
            <div class="glass rounded-card px-5 py-4 lg:col-span-1">
                <p class="eyebrow">{{ t('payroll.fields.net') }}</p>
                <p class="figure mt-2 text-2xl text-ink" data-testid="total-net">{{ format.money(reversal ? -run.netAmount : run.netAmount, run.currency) }}</p>
                <p class="mt-1 text-xs text-ink-subtle">{{ t('payroll.runs.employees', { count: format.number(run.employeeCount) }) }}</p>
            </div>
            <div v-for="item in totals" :key="item.key" class="glass rounded-card px-5 py-4">
                <p class="eyebrow">{{ item.label }}</p>
                <p class="tabular mt-2 font-mono text-ink" :data-testid="`total-${item.key}`">{{ format.money(reversal ? -item.amount : item.amount, run.currency) }}</p>
            </div>
        </div>

        <Card :title="t('payroll.runs.history')">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="eyebrow">{{ t('payroll.runs.preparedBy') }}</dt><dd class="mt-0.5 text-ink">{{ run.preparedByName ?? t('payroll.runs.automation') }}</dd></div>
                <div v-if="run.submittedAt"><dt class="eyebrow">{{ t('payroll.runs.submittedAt') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.date(run.submittedAt, 'datetime') }}</dd></div>
                <div v-if="run.approvedAt"><dt class="eyebrow">{{ t('payroll.runs.approvedBy') }}</dt><dd class="mt-0.5 text-ink">{{ run.approvedByName ?? t('payroll.common.dash') }}</dd></div>
                <div v-if="run.approvedAt"><dt class="eyebrow">{{ t('payroll.runs.approvedAt') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.date(run.approvedAt, 'datetime') }}</dd></div>
                <div v-if="run.note" class="sm:col-span-2 lg:col-span-4"><dt class="eyebrow">{{ t('payroll.runs.note') }}</dt><dd class="mt-0.5 text-ink">{{ run.note }}</dd></div>
            </dl>
            <div v-if="abilities.exportPaymentSheet" class="mt-4 border-t border-line pt-3">
                <a
                    :href="route('payroll.runs.payment-sheet', { run: run.id })"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-text underline underline-offset-2"
                    data-testid="payment-sheet-link"
                >
                    <Download class="size-4" aria-hidden="true" />
                    {{ t('payroll.runs.paymentSheet') }}
                </a>
                <p class="mt-1 text-[0.8125rem] text-ink-subtle">{{ t('payroll.runs.paymentSheetHint') }}</p>
            </div>
        </Card>

        <Card flush :title="t('payroll.runs.payslips')">
            <DataTable :columns="columns" :rows="payslips.data" row-key="id" :caption="t('payroll.runs.payslipsCaption')" storage-key="payroll-run-payslips">
                <template #toolbar>
                    <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="applyFilters">
                        <Input v-model="search" type="search" :label="t('payroll.payslips.search')" class="min-w-48 flex-1" />
                        <Select v-model="status" :options="statusOptions" :label="t('payroll.fields.paymentStatus')" class="w-full sm:w-44" />
                        <Button type="submit" size="sm">{{ t('payroll.common.apply') }}</Button>
                    </form>
                </template>
                <template #cell-employee="{ row }">
                    <Button variant="ghost" size="sm" :href="route('payroll.payslips.show', { payslip: row.id })">{{ row.employeeName }}</Button>
                    <span class="ml-1 text-xs text-ink-subtle">{{ row.employeeNumber }}</span>
                    <Badge v-if="row.isReversal" tone="warn" class="ml-1">{{ t('payroll.payslips.reversal') }}</Badge>
                    <Badge v-if="row.warnings.length > 0" tone="warn" class="ml-1">{{ t('payroll.runs.hasWarnings') }}</Badge>
                </template>
                <template #cell-department="{ row }"><span class="text-ink-muted">{{ row.departmentName ?? t('payroll.common.dash') }}</span></template>
                <template #cell-gross="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.grossAmount, row.currency, row.isReversal) }}</span></template>
                <template #cell-deductions="{ row }"><span class="tabular font-mono text-ink-muted">{{ pay.money(row.deductionAmount, row.currency, row.isReversal) }}</span></template>
                <template #cell-net="{ row }"><span class="tabular font-mono">{{ pay.money(row.netAmount, row.currency, row.isReversal) }}</span></template>
                <template #cell-status="{ row }"><PayrollBadge kind="payment" :value="row.paymentStatus" /></template>
                <template #cell-actions="{ row }">
                    <Button size="sm" variant="ghost" :href="route('payroll.payslips.show', { payslip: row.id })">{{ t('payroll.common.view') }}</Button>
                </template>
                <template #empty>
                    <EmptyState :title="t('payroll.runs.noPayslips')" :description="t('payroll.runs.noPayslipsBody')" />
                </template>
            </DataTable>
            <Pagination :meta="payslips" />
        </Card>
    </div>

    <NoteDialog
        v-if="abilities.sendBack"
        v-model:open="sendingBack"
        :url="route('payroll.runs.return', { run: run.id })"
        :title="t('payroll.runs.sendBackTitle')"
        :description="t('payroll.runs.sendBackBody')"
        :submit-label="t('payroll.runs.sendBack')"
        :label="t('payroll.runs.sendBackNote')"
    />
    <NoteDialog
        v-if="abilities.reverse"
        v-model:open="reversing"
        :url="route('payroll.runs.reverse', { run: run.id })"
        :title="t('payroll.runs.reverseTitle')"
        :description="t('payroll.runs.reverseBody')"
        :submit-label="t('payroll.runs.reverse')"
        :label="t('payroll.runs.reverseReason')"
        :hint="t('payroll.runs.reverseHint')"
        danger
    />
    <ConfirmDialog
        v-if="abilities.approve"
        v-model:open="approving"
        tone="default"
        :title="t('payroll.runs.approveTitle')"
        :description="t(approval.requiresSeparateApprover ? 'payroll.runs.approveBodyFourEyes' : 'payroll.runs.approveBody')"
        :confirm-label="t('payroll.runs.approve')"
        :loading="busy === 'approve'"
        @confirm="approve"
    />
    <ConfirmDialog
        v-if="abilities.discard"
        v-model:open="discarding"
        :title="t('payroll.runs.discardTitle')"
        :description="t('payroll.runs.discardBody')"
        :confirm-label="t('payroll.runs.discard')"
        :loading="busy === 'discard'"
        @confirm="discard"
    />
    <PaymentDialog
        v-if="abilities.recordPayments"
        v-model:open="paying"
        bulk
        :url="route('payroll.runs.payments.store', { run: run.id })"
        :currency="run.currency"
        :methods="[...paymentMethods]"
    />
</template>
