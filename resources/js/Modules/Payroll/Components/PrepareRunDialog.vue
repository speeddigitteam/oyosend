<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import PayrollFormDialog from './PayrollFormDialog.vue';
import type { Period } from './types';

/**
 * Calculates a run for a pay period. A regular run is one per period; an
 * adjustment run pays only items still pending for an approved period.
 * Nothing is paid or locked: the result is a draft to review.
 */
const props = defineProps<{
    open: boolean;
    periods: Period[];
    adjustment?: boolean;
    /** Skip the picker when the period is already known. */
    periodId?: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({});
const chosen = ref('');

const options = computed(() => props.periods.map((period) => ({
    value: period.id,
    label: `${period.name} (${format.date(period.startsOn)} - ${format.date(period.endsOn)})`,
})));
const target = computed(() => props.periodId ?? chosen.value);

watch(() => props.open, (open) => {
    if (open) {
        chosen.value = props.periods[0]?.id ?? '';
    }
}, { immediate: true });

function submit(): void {
    const name = props.adjustment ? 'payroll.runs.adjustments.store' : 'payroll.runs.store';

    form.post(route(name, { period: target.value }), { onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        size="sm"
        :title="adjustment ? t('payroll.runs.prepareAdjustment') : t('payroll.runs.prepare')"
        :description="adjustment ? t('payroll.runs.adjustmentBody') : t('payroll.runs.prepareBody')"
        :submit-label="t('payroll.runs.calculate')"
        :processing="form.processing"
        :disabled="target === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select
            v-if="periodId === undefined"
            v-model="chosen"
            :options="options"
            :placeholder="t('payroll.common.choose')"
            :label="t('payroll.fields.period')"
            required
        />
    </PayrollFormDialog>
</template>
