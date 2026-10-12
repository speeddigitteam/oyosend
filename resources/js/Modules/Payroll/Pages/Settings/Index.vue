<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import PercentInput from '../../Components/PercentInput.vue';
import type { PayrollSettings } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: PayrollSettings;
    currency: string;
    options: { daysBasis: string[]; rateBases: string[]; engines: string[] };
}>();

const { t } = useI18n();

const form = useForm({
    days_basis: props.settings.daysBasis as string,
    fixed_days: String(props.settings.fixedDays),
    daily_rate_base: props.settings.dailyRateBase as string,
    deduct_unpaid_leave: props.settings.deductUnpaidLeave,
    deduct_absence: props.settings.deductAbsence,
    work_day_minutes: String(props.settings.workDayMinutes),
    overtime_multiplier_bps: props.settings.overtimeMultiplierBps as number | null,
    overtime_taxable: props.settings.overtimeTaxable,
    max_overtime_minutes: props.settings.maxOvertimeMinutes === null ? '' : String(props.settings.maxOvertimeMinutes),
    require_separate_approver: props.settings.requireSeparateApprover,
    payslip_prefix: props.settings.payslipPrefix,
    run_prefix: props.settings.runPrefix,
    number_padding: String(props.settings.numberPadding),
    payslip_note: props.settings.payslipNote ?? '',
});

const daysBasisOptions = computed(() => props.options.daysBasis.map((value) => ({ value, label: t(`payroll.settings.daysBasis.${value}`) })));
const rateBaseOptions = computed(() => props.options.rateBases.map((value) => ({ value, label: t(`payroll.settings.rateBase.${value}`) })));
const unusable = computed(() => form.overtime_multiplier_bps === null);

function save(): void {
    form.transform((data) => ({
        days_basis: data.days_basis,
        fixed_days: Number(data.fixed_days),
        daily_rate_base: data.daily_rate_base,
        deduct_unpaid_leave: data.deduct_unpaid_leave,
        deduct_absence: data.deduct_absence,
        work_day_minutes: Number(data.work_day_minutes),
        overtime_multiplier_bps: data.overtime_multiplier_bps,
        overtime_taxable: data.overtime_taxable,
        max_overtime_minutes: data.max_overtime_minutes.trim() === '' ? null : Number(data.max_overtime_minutes),
        require_separate_approver: data.require_separate_approver,
        payslip_prefix: data.payslip_prefix.trim(),
        run_prefix: data.run_prefix.trim(),
        number_padding: Number(data.number_padding),
        payslip_note: data.payslip_note.trim() === '' ? null : data.payslip_note.trim(),
    })).put(route('payroll.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.settings.title')" :description="t('payroll.settings.description')" />

    <form class="grid gap-3" novalidate @submit.prevent="save">
        <Card :title="t('payroll.settings.dayValuation')" :description="t('payroll.settings.dayValuationBody')">
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.days_basis" :options="daysBasisOptions" :label="t('payroll.settings.daysBasisLabel')" :error="form.errors.days_basis" />
                <Input
                    v-if="form.days_basis === 'fixed_days'"
                    v-model="form.fixed_days"
                    type="number"
                    min="1"
                    max="31"
                    inputmode="numeric"
                    :label="t('payroll.settings.fixedDays')"
                    :error="form.errors.fixed_days"
                />
                <Select v-model="form.daily_rate_base" :options="rateBaseOptions" :label="t('payroll.settings.rateBaseLabel')" :hint="t('payroll.settings.rateBaseHint')" :error="form.errors.daily_rate_base" />
                <Checkbox v-model="form.deduct_unpaid_leave" :label="t('payroll.settings.deductUnpaidLeave')" :description="t('payroll.settings.deductUnpaidLeaveHint')" />
                <Checkbox v-model="form.deduct_absence" :label="t('payroll.settings.deductAbsence')" :description="t('payroll.settings.deductAbsenceHint')" />
            </div>
        </Card>

        <Card :title="t('payroll.settings.overtime')" :description="t('payroll.settings.overtimeBody')">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.work_day_minutes" type="number" min="60" max="1440" inputmode="numeric" :label="t('payroll.settings.workDayMinutes')" :error="form.errors.work_day_minutes" />
                <PercentInput v-model="form.overtime_multiplier_bps" :max="100000" :label="t('payroll.settings.overtimeRate')" :hint="t('payroll.settings.overtimeRateHint')" :error="form.errors.overtime_multiplier_bps" />
                <Input v-model="form.max_overtime_minutes" type="number" min="0" max="100000" inputmode="numeric" :label="t('payroll.settings.maxOvertime')" :hint="t('payroll.settings.maxOvertimeHint')" :error="form.errors.max_overtime_minutes" optional />
                <Checkbox v-model="form.overtime_taxable" :label="t('payroll.settings.overtimeTaxable')" />
            </div>
        </Card>

        <Card :title="t('payroll.settings.approval')" :description="t('payroll.settings.approvalBody')">
            <Checkbox
                v-model="form.require_separate_approver"
                :label="t('payroll.settings.separateApprover')"
                :description="t('payroll.settings.separateApproverHint')"
            />
            <p v-if="!form.require_separate_approver" class="mt-3 text-sm text-warn" role="status" data-testid="four-eyes-off">{{ t('payroll.settings.separateApproverOff') }}</p>
        </Card>

        <Card :title="t('payroll.settings.numbering')" :description="t('payroll.settings.numberingBody')">
            <div class="grid gap-4 sm:grid-cols-3">
                <Input v-model="form.payslip_prefix" :label="t('payroll.settings.payslipPrefix')" :error="form.errors.payslip_prefix" maxlength="20" mono />
                <Input v-model="form.run_prefix" :label="t('payroll.settings.runPrefix')" :error="form.errors.run_prefix" maxlength="20" mono />
                <Input v-model="form.number_padding" type="number" min="1" max="10" inputmode="numeric" :label="t('payroll.settings.padding')" :error="form.errors.number_padding" />
            </div>
        </Card>

        <Card :title="t('payroll.settings.payslipNote')" :description="t('payroll.settings.payslipNoteBody')">
            <Textarea v-model="form.payslip_note" :label="t('payroll.settings.payslipNote')" :error="form.errors.payslip_note" maxlength="2000" optional />
        </Card>

        <div>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="unusable">{{ t('payroll.common.saveChanges') }}</Button>
        </div>
    </form>
</template>
