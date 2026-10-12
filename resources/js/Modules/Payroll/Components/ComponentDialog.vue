<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import MoneyInput from './MoneyInput.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';
import PercentInput from './PercentInput.vue';
import type { Component } from './types';

/**
 * A recurring salary component: an earning or a deduction, a fixed amount or a
 * share of the basic salary, with its own taxable flag and validity dates.
 */
const props = defineProps<{
    open: boolean;
    structureId: string;
    component: Component | null;
    currency: string;
    kinds: string[];
    calcs: string[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({
    name: '',
    code: '',
    kind: 'earning' as string,
    calc: 'fixed' as string,
    amount: null as number | null,
    rate_bps: null as number | null,
    is_taxable: true,
    sort_order: '0',
    effective_from: '',
    effective_to: '',
    is_active: true,
});

const kindOptions = computed(() => props.kinds.map((value) => ({ value, label: t(`payroll.componentKinds.${value}`) })));
const calcOptions = computed(() => props.calcs.map((value) => ({ value, label: t(`payroll.componentCalcs.${value}`) })));
const unusable = computed(() =>
    form.name.trim() === '' || form.code.trim() === '' || (form.calc === 'fixed' ? form.amount === null : form.rate_bps === null));

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const component = props.component;

    form.defaults({
        name: component?.name ?? '',
        code: component?.code ?? '',
        kind: component?.kind ?? props.kinds[0] ?? 'earning',
        calc: component?.calc ?? props.calcs[0] ?? 'fixed',
        amount: component?.amount ?? null,
        rate_bps: component?.rateBps ?? null,
        is_taxable: component?.isTaxable ?? true,
        sort_order: String(component?.sortOrder ?? 0),
        effective_from: component?.effectiveFrom ?? '',
        effective_to: component?.effectiveTo ?? '',
        is_active: component?.isActive ?? true,
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    const fixed = form.calc === 'fixed';

    form.transform((data) => ({
        name: data.name.trim(),
        code: data.code.trim(),
        kind: data.kind,
        calc: data.calc,
        amount: fixed ? data.amount : null,
        rate_bps: fixed ? null : data.rate_bps,
        is_taxable: data.is_taxable,
        sort_order: data.sort_order === '' ? 0 : Number(data.sort_order),
        effective_from: data.effective_from === '' ? null : data.effective_from,
        effective_to: data.effective_to === '' ? null : data.effective_to,
        is_active: data.is_active,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.component === null) {
        form.post(route('payroll.structures.components.store', { structure: props.structureId }), options);
    } else {
        form.put(route('payroll.structures.components.update', { structure: props.structureId, component: props.component.id }), options);
    }
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="component === null ? t('payroll.structures.addComponent') : t('payroll.structures.editComponent')"
        :submit-label="component === null ? t('payroll.structures.addComponent') : t('payroll.common.saveChanges')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.name" :label="t('payroll.fields.name')" :error="form.errors.name" maxlength="120" required autofocus />
            <Input v-model="form.code" :label="t('payroll.fields.code')" :error="form.errors.code" maxlength="30" mono required />
            <Select v-model="form.kind" :options="kindOptions" :label="t('payroll.fields.kind')" :error="form.errors.kind" required />
            <Select v-model="form.calc" :options="calcOptions" :label="t('payroll.fields.calc')" :error="form.errors.calc" required />
        </div>
        <MoneyInput v-if="form.calc === 'fixed'" v-model="form.amount" :currency="currency" :label="t('payroll.fields.amountWithCurrency', { currency })" :error="form.errors.amount" />
        <PercentInput v-else v-model="form.rate_bps" :max="100000" :label="t('payroll.fields.percentOfBasic')" :hint="t('payroll.structures.percentHint')" :error="form.errors.rate_bps" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.effective_from" type="date" :label="t('payroll.fields.effectiveFrom')" :error="form.errors.effective_from" optional />
            <Input v-model="form.effective_to" type="date" :label="t('payroll.fields.effectiveTo')" :error="form.errors.effective_to" optional />
            <Input v-model="form.sort_order" type="number" min="0" max="65535" :label="t('payroll.fields.sortOrder')" :error="form.errors.sort_order" />
        </div>
        <Checkbox v-model="form.is_taxable" :label="t('payroll.fields.taxable')" :description="t('payroll.structures.taxableHint')" />
        <Checkbox v-model="form.is_active" :label="t('payroll.fields.active')" />
    </PayrollFormDialog>
</template>
