<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import { route } from '@/Core/lib/route';
import { parseDecimal, toDecimal } from './invoiceMath';
import type { TaxRate } from './types';

const props = withDefaults(defineProps<{ open: boolean; rate?: TaxRate | null }>(), { rate: null });
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({ name: '', percent: '', is_default: false, is_active: true });
const percentError = ref('');

watch(() => props.open, (open) => {
    if (!open) return;

    form.defaults({
        name: props.rate?.name ?? '',
        percent: props.rate ? toDecimal(props.rate.rateBps, 2, true) : '',
        is_default: props.rate?.isDefault ?? false,
        is_active: props.rate?.isActive ?? true,
    });
    form.reset();
    form.clearErrors();
    percentError.value = '';
}, { immediate: true });

function submit(): void {
    const bps = parseDecimal(form.percent, 2);

    if (bps === null || bps > 10_000) {
        percentError.value = t('invoicing.settings.taxRatePercentInvalid');
        return;
    }

    percentError.value = '';
    form.transform((data) => ({ name: data.name.trim(), rate_bps: bps, is_default: data.is_default, is_active: data.is_active }));

    const settings = { preserveScroll: true, onSuccess: () => { model.value = false; } };

    if (props.rate) form.put(route('invoicing.settings.tax-rates.update', { taxRate: props.rate.id }), settings);
    else form.post(route('invoicing.settings.tax-rates.store'), settings);
}
</script>

<template>
    <Modal v-model:open="model" size="sm" :title="t(rate ? 'invoicing.settings.editTaxRate' : 'invoicing.settings.addTaxRate')" :description="t('invoicing.settings.taxRateHint')" :persistent="form.processing">
        <form id="tax-rate-form" class="grid gap-4" @submit.prevent="submit">
            <Input v-model="form.name" :label="t('invoicing.fields.name')" :error="form.errors.name" maxlength="80" required autofocus />
            <Input v-model="form.percent" inputmode="decimal" :label="t('invoicing.settings.taxRatePercent')" :error="percentError || (form.errors as Record<string, string>).rate_bps" mono required />
            <Checkbox v-model="form.is_default" :label="t('invoicing.settings.taxRateDefault')" :description="t('invoicing.settings.taxRateDefaultHint')" />
            <Checkbox v-model="form.is_active" :label="t('invoicing.settings.taxRateActive')" :description="t('invoicing.settings.taxRateActiveHint')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="tax-rate-form" variant="primary" :loading="form.processing">{{ t('invoicing.common.save') }}</Button>
        </template>
    </Modal>
</template>
