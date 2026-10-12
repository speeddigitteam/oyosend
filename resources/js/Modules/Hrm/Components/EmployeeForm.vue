<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import { fromMinorUnits, toMinorUnits } from './hrmFormat';
import type { EmployeeDetail, EmployeeFormOptions } from './types';

/**
 * One form for adding and for editing an employee. Fields the person may not
 * change are not shown, and a block they cannot see is never sent back, so
 * saving cannot blank what was hidden from them.
 */
const props = defineProps<{
    options: EmployeeFormOptions;
    /** Present when editing. */
    employee?: EmployeeDetail | null;
    defaults?: { joinedOn: string; status: string; employmentType: string };
    /** Department, branch, designation, manager, type, probation, cost and account link. */
    canEditEmployment: boolean;
}>();

const emit = defineEmits<{ cancel: []; saved: [] }>();

const { t } = useI18n();
const page = usePage();

const editing = computed(() => props.employee != null);
const personal = computed(() => !editing.value || props.employee?.personal != null);
const currency = computed(() => props.employee?.costCurrency ?? page.props.app.currency);
const sensitiveRate = computed(() => !editing.value || props.employee?.costRateAmount != null || props.employee?.personal != null);

/** Server errors for fields the form sends under another name. */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const form = useForm({
    first_name: props.employee?.firstName ?? '',
    last_name: props.employee?.lastName ?? '',
    work_email: props.employee?.workEmail ?? '',
    phone: props.employee?.phone ?? '',
    personal_email: props.employee?.personal?.personalEmail ?? '',
    date_of_birth: props.employee?.personal?.dateOfBirth ?? '',
    gender: props.employee?.personal?.gender ?? '',
    national_id: props.employee?.personal?.nationalId ?? '',
    address: props.employee?.personal?.address ?? '',
    city: props.employee?.personal?.city ?? '',
    country: props.employee?.personal?.country ?? '',
    employment_type: props.employee?.employmentType ?? props.defaults?.employmentType ?? 'full_time',
    joined_on: props.defaults?.joinedOn ?? '',
    status: props.defaults?.status ?? 'probation',
    designation_id: props.employee?.designation?.id ?? '',
    department_id: props.employee?.department?.id ?? '',
    branch_id: props.employee?.branch?.id ?? '',
    manager_id: props.employee?.managerId ?? '',
    membership_id: props.employee?.membership?.id ?? '',
    probation_ends_on: props.employee?.probationEndsOn ?? '',
    cost_rate: fromMinorUnits(props.employee?.costRateAmount ?? null, currency.value),
    notes: props.employee?.notes ?? '',
});

const typeOptions = computed(() => props.options.employmentTypes.map((value) => ({ value, label: t(`hrm.employmentType.${value}`) })));
const genderOptions = computed(() => props.options.genders.map((value) => ({ value, label: t(`hrm.gender.${value}`) })));
const startOptions = computed(() => (['probation', 'active'] as const).map((value) => ({ value, label: t(`hrm.status.employee.${value}`) })));
const designations = computed(() => props.options.designations.map((item) => ({ value: item.id, label: item.name })));
const departments = computed(() => props.options.departments.map((item) => ({ value: item.id, label: item.name })));
const branches = computed(() => props.options.branches.map((item) => ({ value: item.id, label: item.name })));
const managers = computed(() => props.options.managers.map((item) => ({ value: item.id, label: `${item.name} (${item.number})` })));
const members = computed(() => props.options.members.map((item) => ({ value: item.id, label: item.email === '' ? item.name : `${item.name} (${item.email})` })));

const rateInvalid = computed(() => form.cost_rate.trim() !== '' && toMinorUnits(form.cost_rate, currency.value) === null);
const unusable = computed(() => form.first_name.trim() === '' || (!editing.value && form.joined_on === '') || rateInvalid.value);

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    const keepRate = props.canEditEmployment && sensitiveRate.value;

    form.transform((data) => {
        const payload: Record<string, unknown> = {
            first_name: data.first_name.trim(),
            last_name: blank(data.last_name),
            work_email: blank(data.work_email),
            phone: blank(data.phone),
            employment_type: data.employment_type,
            notes: blank(data.notes),
        };

        if (personal.value) {
            Object.assign(payload, {
                personal_email: blank(data.personal_email),
                date_of_birth: blank(data.date_of_birth),
                gender: blank(data.gender),
                national_id: blank(data.national_id),
                address: blank(data.address),
                city: blank(data.city),
                country: blank(data.country)?.toUpperCase() ?? null,
            });
        }

        if (props.canEditEmployment) {
            Object.assign(payload, {
                designation_id: blank(data.designation_id),
                department_id: blank(data.department_id),
                branch_id: blank(data.branch_id),
                manager_id: blank(data.manager_id),
                membership_id: blank(data.membership_id),
                probation_ends_on: blank(data.probation_ends_on),
            });
        }

        if (keepRate && (props.employee?.costRateAmount != null || data.cost_rate.trim() !== '')) {
            Object.assign(payload, {
                cost_rate_amount: data.cost_rate.trim() === '' ? null : toMinorUnits(data.cost_rate, currency.value),
                cost_currency: currency.value,
            });
        }

        if (!editing.value) {
            Object.assign(payload, { joined_on: data.joined_on, status: data.status });
        }

        return payload as typeof data;
    });

    const options = { preserveScroll: true, onSuccess: () => emit('saved') };

    if (props.employee) {
        form.put(route('hrm.employees.update', { employee: props.employee.id }), options);
    } else {
        form.post(route('hrm.employees.store'), options);
    }
}
</script>

<template>
    <form class="grid gap-6" novalidate @submit.prevent="submit">
        <fieldset class="grid gap-4">
            <legend class="eyebrow mb-3">{{ t('hrm.form.identity') }}</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.first_name" :label="t('hrm.fields.firstName')" maxlength="100" :error="form.errors.first_name" required autocomplete="off" />
                <Input v-model="form.last_name" :label="t('hrm.fields.lastName')" optional maxlength="100" :error="form.errors.last_name" autocomplete="off" />
                <Input v-model="form.work_email" type="email" :label="t('hrm.fields.workEmail')" optional maxlength="190" :error="form.errors.work_email" />
                <Input v-model="form.phone" type="tel" :label="t('hrm.fields.phone')" optional maxlength="40" :error="form.errors.phone" />
            </div>
        </fieldset>

        <fieldset class="grid gap-4">
            <legend class="eyebrow mb-3">{{ t('hrm.form.employment') }}</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-if="canEditEmployment" v-model="form.employment_type" :label="t('hrm.fields.employmentType')" :options="typeOptions" :error="form.errors.employment_type" />
                <Input v-if="!editing" v-model="form.joined_on" type="date" :label="t('hrm.fields.joinedOn')" :error="form.errors.joined_on" required />
                <Select v-if="!editing" v-model="form.status" :label="t('hrm.fields.startsAs')" :options="startOptions" :error="form.errors.status" />
                <template v-if="canEditEmployment">
                    <Select v-model="form.designation_id" :label="t('hrm.fields.designation')" :options="designations" :placeholder="t('hrm.common.none')" optional :error="form.errors.designation_id" />
                    <Select v-model="form.department_id" :label="t('hrm.fields.department')" :options="departments" :placeholder="t('hrm.common.none')" optional :error="form.errors.department_id" />
                    <Select v-model="form.branch_id" :label="t('hrm.fields.branch')" :options="branches" :placeholder="t('hrm.common.none')" optional :error="form.errors.branch_id" />
                    <Select v-model="form.manager_id" :label="t('hrm.fields.manager')" :options="managers" :placeholder="t('hrm.common.none')" optional :error="form.errors.manager_id" />
                    <Input v-model="form.probation_ends_on" type="date" :label="t('hrm.fields.probationEndsOn')" optional :error="form.errors.probation_ends_on" />
                    <Select
                        v-model="form.membership_id"
                        :label="t('hrm.fields.account')"
                        :options="members"
                        :placeholder="t('hrm.common.none')"
                        :hint="t('hrm.form.accountHint')"
                        optional
                        :error="form.errors.membership_id"
                    />
                    <Input
                        v-if="sensitiveRate"
                        v-model="form.cost_rate"
                        inputmode="decimal"
                        :label="t('hrm.fields.costRate', { currency })"
                        :hint="t('hrm.form.costRateHint')"
                        optional
                        :error="serverErrors.cost_rate_amount ?? (rateInvalid ? t('hrm.form.amountInvalid') : undefined)"
                    />
                </template>
            </div>
        </fieldset>

        <fieldset v-if="personal" class="grid gap-4">
            <legend class="eyebrow mb-3">{{ t('hrm.form.personal') }}</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.personal_email" type="email" :label="t('hrm.fields.personalEmail')" optional maxlength="190" :error="form.errors.personal_email" />
                <Input v-model="form.date_of_birth" type="date" :label="t('hrm.fields.dateOfBirth')" optional :error="form.errors.date_of_birth" />
                <Select v-model="form.gender" :label="t('hrm.fields.gender')" :options="genderOptions" :placeholder="t('hrm.common.notSpecified')" optional :error="form.errors.gender" />
                <Input v-model="form.national_id" :label="t('hrm.fields.nationalId')" optional maxlength="64" mono autocomplete="off" :error="form.errors.national_id" />
                <Input v-model="form.city" :label="t('hrm.fields.city')" optional maxlength="100" :error="form.errors.city" />
                <Input v-model="form.country" :label="t('hrm.fields.country')" :hint="t('hrm.form.countryHint')" optional maxlength="2" :error="form.errors.country" />
            </div>
            <Textarea v-model="form.address" :label="t('hrm.fields.address')" optional maxlength="500" :error="form.errors.address" />
        </fieldset>

        <Textarea v-model="form.notes" :label="t('hrm.fields.notes')" optional maxlength="5000" :error="form.errors.notes" />

        <div class="flex flex-wrap justify-end gap-2">
            <Button variant="ghost" :disabled="form.processing" @click="emit('cancel')">{{ t('hrm.common.cancel') }}</Button>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="unusable">
                {{ editing ? t('hrm.common.saveChanges') : t('hrm.employees.createSubmit') }}
            </Button>
        </div>
    </form>
</template>
