<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import { route } from '@/Core/lib/route';
import PayrollFormDialog from './PayrollFormDialog.vue';
import type { Period, PeriodKind } from './types';

/** Create a pay period, or edit one that is still open and has no run. */
const props = defineProps<{
    open: boolean;
    period: Period | null;
    /** `Y-m-d` today in the workspace zone; the default month. */
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({
    kind: 'monthly' as PeriodKind,
    name: '',
    month: '',
    starts_on: '',
    ends_on: '',
    pay_date: '',
});

const kindOptions = computed(() => [
    { value: 'monthly' as const, label: t('payroll.periodKinds.monthly') },
    { value: 'custom' as const, label: t('payroll.periodKinds.custom') },
]);
const unusable = computed(() => (form.kind === 'monthly' ? form.month === '' : form.starts_on === '' || form.ends_on === ''));

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const period = props.period;

    form.defaults({
        kind: period?.kind ?? 'monthly',
        name: period?.name ?? '',
        month: period?.kind === 'custom' ? '' : (period?.startsOn ?? props.today).slice(0, 7),
        starts_on: period?.startsOn ?? '',
        ends_on: period?.endsOn ?? '',
        pay_date: period?.payDate ?? '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    const monthly = form.kind === 'monthly';

    form.transform((data) => ({
        kind: data.kind,
        name: data.name.trim() === '' ? null : data.name.trim(),
        month: monthly ? data.month : null,
        starts_on: monthly ? null : data.starts_on,
        ends_on: monthly ? null : data.ends_on,
        pay_date: data.pay_date === '' ? null : data.pay_date,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.period === null) {
        form.post(route('payroll.periods.store'), options);
    } else {
        form.put(route('payroll.periods.update', { period: props.period.id }), options);
    }
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="period === null ? t('payroll.periods.create') : t('payroll.periods.edit')"
        :description="t('payroll.periods.formBody')"
        :submit-label="period === null ? t('payroll.periods.create') : t('payroll.common.saveChanges')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <SegmentedControl v-model="form.kind" :options="kindOptions" :label="t('payroll.fields.kind')" />
        <Input v-if="form.kind === 'monthly'" v-model="form.month" type="month" :label="t('payroll.fields.month')" :error="form.errors.month" required />
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.starts_on" type="date" :label="t('payroll.fields.startsOn')" :error="form.errors.starts_on" required />
            <Input v-model="form.ends_on" type="date" :label="t('payroll.fields.endsOn')" :error="form.errors.ends_on" required />
        </div>
        <Input v-model="form.name" :label="t('payroll.fields.name')" :hint="t('payroll.periods.nameHint')" :error="form.errors.name" maxlength="120" optional />
        <Input v-model="form.pay_date" type="date" :label="t('payroll.fields.payDate')" :error="form.errors.pay_date" optional />
    </PayrollFormDialog>
</template>
