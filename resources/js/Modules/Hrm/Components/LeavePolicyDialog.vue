<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import { daysToUnits, unitsToDays } from './hrmFormat';
import type { Accrual, EmploymentType, LeavePolicy, LeaveType } from './types';

/**
 * A policy says how much of one leave type people accrue. Amounts are typed in
 * days (half days allowed) and stored as units.
 */
const props = defineProps<{
    open: boolean;
    policy: LeavePolicy | null;
    types: LeaveType[];
    accruals: Accrual[];
    employmentTypes: EmploymentType[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

/** Server errors for fields the form sends under another name. */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const form = useForm({
    leave_type_id: '',
    name: '',
    employment_types: [] as EmploymentType[],
    accrual: 'annual' as Accrual,
    annual_days: '0',
    carry_days: '0',
    max_balance_days: '',
    max_request_days: '',
    sandwich_rule: false,
    min_service_days: '0',
    is_active: true,
});

const typeOptions = computed(() => props.types.map((type) => ({ value: type.id, label: type.name })));
const accrualOptions = computed(() => props.accruals.map((value) => ({ value, label: t(`hrm.accrual.${value}`) })));

function days(units: number | null): string {
    return units === null ? '' : String(unitsToDays(units));
}

watch(() => props.open, (open) => {
    if (open) {
        const policy = props.policy;
        form.clearErrors();
        form.leave_type_id = policy?.leaveTypeId ?? props.types[0]?.id ?? '';
        form.name = policy?.name ?? '';
        form.employment_types = [...(policy?.employmentTypes ?? [])];
        form.accrual = policy?.accrual ?? 'annual';
        form.annual_days = days(policy?.annualUnits ?? 0);
        form.carry_days = days(policy?.carryForwardMaxUnits ?? 0);
        form.max_balance_days = days(policy?.maxBalanceUnits ?? null);
        form.max_request_days = days(policy?.maxUnitsPerRequest ?? null);
        form.sandwich_rule = policy?.sandwichRule ?? false;
        form.min_service_days = String(policy?.minServiceDays ?? 0);
        form.is_active = policy?.isActive ?? true;
    }
}, { immediate: true });

function toggleType(value: EmploymentType, on: boolean): void {
    form.employment_types = on ? [...form.employment_types, value] : form.employment_types.filter((item) => item !== value);
}

const annual = computed(() => daysToUnits(form.annual_days));
const carry = computed(() => daysToUnits(form.carry_days));
const maxBalance = computed(() => (form.max_balance_days.trim() === '' ? null : daysToUnits(form.max_balance_days)));
const maxRequest = computed(() => (form.max_request_days.trim() === '' ? null : daysToUnits(form.max_request_days)));
const amountsValid = computed(() => annual.value !== null && carry.value !== null
    && (form.max_balance_days.trim() === '' || maxBalance.value !== null)
    && (form.max_request_days.trim() === '' || (maxRequest.value !== null && maxRequest.value >= 1)));

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const transformed = form.transform((data) => ({
        leave_type_id: data.leave_type_id,
        name: data.name.trim(),
        employment_types: data.employment_types,
        accrual: data.accrual,
        annual_units: annual.value,
        carry_forward_max_units: carry.value,
        max_balance_units: maxBalance.value,
        max_units_per_request: maxRequest.value,
        sandwich_rule: data.sandwich_rule,
        min_service_days: Number(data.min_service_days),
        is_active: data.is_active,
    }) as never);

    if (props.policy) {
        transformed.put(route('hrm.settings.leave-policies.update', { policy: props.policy.id }), options);
    } else {
        transformed.post(route('hrm.settings.leave-policies.store'), options);
    }
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="lg"
        :title="policy ? t('hrm.settings.policies.edit') : t('hrm.settings.policies.add')"
        :submit-label="t('hrm.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || form.leave_type_id === '' || !amountsValid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <Select v-model="form.leave_type_id" :label="t('hrm.fields.leaveType')" :options="typeOptions" :error="form.errors.leave_type_id" />
            <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="100" :error="form.errors.name" required />
            <Select v-model="form.accrual" :label="t('hrm.fields.accrual')" :options="accrualOptions" :error="form.errors.accrual" />
            <Input v-model="form.annual_days" inputmode="decimal" :label="t('hrm.fields.annualDays')" :hint="t('hrm.settings.policies.halfHint')" :error="serverErrors.annual_units" required />
            <Input v-model="form.carry_days" inputmode="decimal" :label="t('hrm.fields.carryDays')" :error="serverErrors.carry_forward_max_units" required />
            <Input v-model="form.max_balance_days" inputmode="decimal" :label="t('hrm.fields.maxBalanceDays')" optional :error="serverErrors.max_balance_units" />
            <Input v-model="form.max_request_days" inputmode="decimal" :label="t('hrm.fields.maxRequestDays')" optional :error="serverErrors.max_units_per_request" />
            <Input v-model="form.min_service_days" type="number" min="0" max="3650" :label="t('hrm.fields.minService')" :error="form.errors.min_service_days" />
        </div>
        <fieldset class="grid gap-2">
            <legend class="mb-1 text-sm font-medium text-ink">{{ t('hrm.fields.appliesTo') }}</legend>
            <p class="text-xs text-ink-subtle">{{ t('hrm.settings.policies.appliesHint') }}</p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <Checkbox
                    v-for="value in employmentTypes"
                    :key="value"
                    :model-value="form.employment_types.includes(value)"
                    :label="t(`hrm.employmentType.${value}`)"
                    @update:model-value="toggleType(value, $event)"
                />
            </div>
        </fieldset>
        <div class="flex flex-wrap gap-x-6 gap-y-2">
            <Checkbox v-model="form.sandwich_rule" :label="t('hrm.settings.policies.sandwich')" :description="t('hrm.settings.policies.sandwichHint')" />
            <Checkbox v-model="form.is_active" :label="t('hrm.fields.active')" />
        </div>
    </HrmFormDialog>
</template>
