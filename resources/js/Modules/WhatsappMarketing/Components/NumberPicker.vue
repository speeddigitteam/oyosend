<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import type { NumberPicker } from './types';

/**
 * Picks the WhatsApp business number a message goes out from (ADR 0022). It only appears when the workspace has
 * more than one number; with a single number the default is used and no control is shown. The value is the
 * number's platform id, sent to the server as `sender_number_id`.
 */
const props = defineProps<{ picker: NumberPicker; label?: string }>();

const model = defineModel<string>({ default: '' });

const { t } = useI18n();

const show = computed(() => props.picker.options.length > 1);

const options = computed(() =>
    props.picker.options.map((number) => ({
        value: number.id,
        label: number.isDefault ? t('whatsapp.numbers.pickerDefault', { label: number.label }) : number.label,
    })),
);
</script>

<template>
    <Select
        v-if="show"
        v-model="model"
        :options="options"
        :label="label ?? t('whatsapp.numbers.pickerLabel')"
        data-testid="number-picker"
    />
</template>
