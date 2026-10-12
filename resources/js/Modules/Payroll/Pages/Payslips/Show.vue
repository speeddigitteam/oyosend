<script setup lang="ts">
import { Banknote, ExternalLink } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import IssueList from '../../Components/IssueList.vue';
import PaymentDialog from '../../Components/PaymentDialog.vue';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { LineKind, PaymentMethod, PayslipDetail, PayslipLine, Payment, Period } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    payslip: PayslipDetail;
    period: Period;
    lines: PayslipLine[];
    payments: Payment[];
    paymentMethods: PaymentMethod[];
    abilities: { recordPayment: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const paying = ref(false);

const GROUPS: LineKind[] = ['earning', 'deduction', 'reimbursement', 'employer'];
const groups = computed(() => GROUPS
    .map((kind) => ({ kind, lines: props.lines.filter((line) => line.kind === kind) }))
    .filter((group) => group.lines.length > 0));
const columns = computed<Column[]>(() => [
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'code', label: t('payroll.fields.code'), hideable: true },
    { key: 'amount', label: t('payroll.fields.amount'), align: 'right' },
]);
const documentUrl = computed(() => route('payroll.payslips.document', { payslip: props.payslip.id }));
const downloadUrl = computed(() => route('payroll.payslips.document', { payslip: props.payslip.id, download: '1' }));
const paymentUrl = computed(() => route('payroll.payments.store', { payslip: props.payslip.id }));
const summary = computed(() => [
    { key: 'basic', label: t('payroll.fields.basic'), amount: props.payslip.basicAmount },
    { key: 'gross', label: t('payroll.fields.gross'), amount: props.payslip.grossAmount },
    { key: 'deductions', label: t('payroll.fields.deductions'), amount: props.payslip.deductionAmount },
    { key: 'reimbursements', label: t('payroll.fields.reimbursements'), amount: props.payslip.reimbursementAmount },
    { key: 'employer', label: t('payroll.fields.employer'), amount: props.payslip.employerAmount },
]);
const attendance = computed(() => {
    const summaryData = (props.payslip.attendance as { summary?: Record<string, unknown> } | null)?.summary ?? null;

    if (summaryData === null) {
        return [];
    }

    const count = (key: string): number | null => (typeof summaryData[key] === 'number' ? (summaryData[key] as number) : null);
    const rows: Array<{ key: string; label: string; value: string }> = [];

    for (const key of ['days_present', 'days_half', 'days_absent', 'days_on_leave'] as const) {
        const value = count(key);

        if (value !== null) {
            rows.push({ key, label: t(`payroll.attendance.${key}`), value: format.number(value) });
        }
    }

    const overtime = count('overtime_minutes');

    if (overtime !== null && overtime > 0) {
        rows.push({ key: 'overtime', label: t('payroll.attendance.overtime'), value: pay.duration(overtime) });
    }

    return rows;
});

function openDocument(): void {
    window.open(documentUrl.value, '_blank', 'noopener');
}

/** The rule set a computed line came from, when the line records it. */
function ruleNote(line: PayslipLine): string | null {
    const version = line.detail?.version;
    const base = line.detail?.base;

    return typeof version === 'number' && typeof base === 'number'
        ? t('payroll.payslips.ruleNote', { version: format.number(version), base: pay.money(base, props.payslip.currency, props.payslip.isReversal) })
        : null;
}
</script>

<template>
    <PageHeader
        :eyebrow="payslip.employeeName"
        :title="payslip.number ?? t('payroll.payslips.draftNumber')"
        :description="t('payroll.payslips.showDescription', { period: period.name })"
    >
        <template #actions>
            <Button v-if="abilities.recordPayment" variant="primary" @click="paying = true">
                <template #icon><Banknote class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.payments.title') }}
            </Button>
        </template>
    </PageHeader>

    <div v-if="payslip.isReversal" class="glass mb-4 flex flex-wrap items-center gap-3 rounded-card px-5 py-3" data-testid="reversal-banner">
        <Badge tone="warn">{{ t('payroll.payslips.reversal') }}</Badge>
        <p class="text-sm text-ink">{{ t('payroll.payslips.reversalBody', { run: payslip.runNumber ?? t('payroll.common.dash') }) }}</p>
    </div>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid gap-3 lg:col-span-2">
            <Card :title="t('payroll.payslips.summary')">
                <dl class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3">
                    <div v-for="item in summary" :key="item.key">
                        <dt class="eyebrow">{{ item.label }}</dt>
                        <dd class="tabular mt-1 font-mono text-ink" :data-testid="`summary-${item.key}`">{{ pay.money(item.amount, payslip.currency, payslip.isReversal) }}</dd>
                    </div>
                    <div class="col-span-2 sm:col-span-3">
                        <dt class="eyebrow">{{ t('payroll.fields.net') }}</dt>
                        <dd class="figure mt-2 text-3xl text-ink" data-testid="summary-net">{{ pay.money(payslip.netAmount, payslip.currency, payslip.isReversal) }}</dd>
                    </div>
                </dl>
            </Card>

            <IssueList kind="warnings" :issues="payslip.warnings" :currency="payslip.currency" />

            <Card v-for="group in groups" :key="group.kind" flush :title="t(`payroll.lineKinds.${group.kind}`)" :data-testid="`lines-${group.kind}`">
                <DataTable :columns="columns" :rows="group.lines" row-key="id" :caption="t(`payroll.lineKinds.${group.kind}`)">
                    <template #cell-name="{ row }">
                        <span class="text-ink">{{ row.name }}</span>
                        <span v-if="ruleNote(row)" class="block text-xs text-ink-subtle">{{ ruleNote(row) }}</span>
                        <Badge v-if="row.isTaxable && group.kind === 'earning'" class="ml-1">{{ t('payroll.fields.taxable') }}</Badge>
                    </template>
                    <template #cell-code="{ row }"><span class="font-mono text-ink-muted">{{ row.code }}</span></template>
                    <template #cell-amount="{ row }">
                        <span class="tabular font-mono">{{ pay.money(row.amount, payslip.currency, payslip.isReversal) }}</span>
                    </template>
                </DataTable>
            </Card>
        </div>

        <div class="grid content-start gap-3">
            <Card :title="t('payroll.payslips.payment')">
                <div class="grid gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <PayrollBadge kind="payment" :value="payslip.paymentStatus" />
                        <span v-if="payslip.paidOn" class="tabular text-sm text-ink-muted">{{ format.date(payslip.paidOn) }}</span>
                    </div>
                    <p v-if="payslip.paymentMethod" class="text-sm text-ink-muted">
                        {{ t(`payroll.methods.${payslip.paymentMethod}`) }}
                        <span v-if="payslip.paymentReference" class="font-mono text-ink">{{ payslip.paymentReference }}</span>
                    </p>
                    <p v-if="payments.length === 0" class="text-sm text-ink-subtle">{{ t('payroll.payslips.noPayments') }}</p>
                    <ol v-else class="grid gap-3 border-t border-line pt-3" data-testid="payment-timeline">
                        <li v-for="payment in payments" :key="payment.id" class="grid gap-0.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <PayrollBadge kind="payment" :value="payment.status" />
                                <span class="tabular font-mono text-sm text-ink">{{ pay.money(payment.amount, payment.currency, payslip.isReversal) }}</span>
                            </div>
                            <p class="text-[0.8125rem] text-ink-muted">
                                <template v-if="payment.method">{{ t(`payroll.methods.${payment.method}`) }}</template>
                                <template v-if="payment.reference"> - <span class="font-mono">{{ payment.reference }}</span></template>
                            </p>
                            <p v-if="payment.note" class="text-[0.8125rem] text-ink-muted">{{ payment.note }}</p>
                            <p class="tabular text-xs text-ink-subtle">
                                {{ payment.paidOn ? format.date(payment.paidOn) : '' }}
                                {{ payment.createdAt ? t('payroll.payslips.recordedAt', { when: format.date(payment.createdAt, 'datetime') }) : '' }}
                            </p>
                        </li>
                    </ol>
                </div>
            </Card>

            <Card :title="t('payroll.payslips.document')" :description="t('payroll.payslips.documentBody')">
                <div class="flex flex-wrap items-center gap-3">
                    <Button size="sm" @click="openDocument">
                        <template #icon><ExternalLink class="size-4" aria-hidden="true" /></template>
                        {{ t('payroll.payslips.openDocument') }}
                    </Button>
                    <a :href="downloadUrl" class="text-sm font-medium text-brand-text underline underline-offset-2" data-testid="download-link">
                        {{ t('payroll.payslips.download') }}
                    </a>
                </div>
            </Card>

            <Card :title="t('payroll.payslips.details')">
                <dl class="grid gap-3 text-sm">
                    <div><dt class="eyebrow">{{ t('payroll.fields.employee') }}</dt><dd class="mt-0.5 text-ink">{{ payslip.employeeName }} <span class="font-mono text-ink-subtle">{{ payslip.employeeNumber }}</span></dd></div>
                    <div v-if="payslip.departmentName"><dt class="eyebrow">{{ t('payroll.fields.department') }}</dt><dd class="mt-0.5 text-ink">{{ payslip.departmentName }}</dd></div>
                    <div v-if="payslip.designationName"><dt class="eyebrow">{{ t('payroll.fields.designation') }}</dt><dd class="mt-0.5 text-ink">{{ payslip.designationName }}</dd></div>
                    <div><dt class="eyebrow">{{ t('payroll.fields.period') }}</dt><dd class="tabular mt-0.5 text-ink">{{ period.name }}, {{ format.date(period.startsOn) }} - {{ format.date(period.endsOn) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('payroll.fields.run') }}</dt><dd class="mt-0.5 font-mono text-ink">{{ payslip.runNumber ?? t('payroll.common.dash') }}</dd></div>
                    <div v-if="payslip.structureName"><dt class="eyebrow">{{ t('payroll.fields.structure') }}</dt><dd class="mt-0.5 text-ink">{{ payslip.structureName }}</dd></div>
                    <div><dt class="eyebrow">{{ t('payroll.payslips.payableDays') }}</dt><dd class="tabular mt-0.5 text-ink">{{ t('payroll.payslips.payableOf', { payable: format.number(payslip.payableDays), total: format.number(payslip.periodDays) }) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('payroll.payslips.taxable') }}</dt><dd class="tabular mt-0.5 font-mono text-ink">{{ pay.money(payslip.taxableAmount, payslip.currency, payslip.isReversal) }}</dd></div>
                    <div v-for="row in attendance" :key="row.key"><dt class="eyebrow">{{ row.label }}</dt><dd class="tabular mt-0.5 text-ink">{{ row.value }}</dd></div>
                </dl>
            </Card>
        </div>
    </div>

    <PaymentDialog
        v-if="abilities.recordPayment"
        v-model:open="paying"
        :url="paymentUrl"
        :currency="payslip.currency"
        :methods="paymentMethods"
        :amount="payslip.netAmount"
    />
</template>
