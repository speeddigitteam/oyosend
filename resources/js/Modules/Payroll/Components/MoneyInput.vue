<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { currencyDigits, moneyText, parseMoney } from './payrollMoney';

/**
 * An amount field. The person types a decimal ("1250.50"); the model is the
 * exact integer in minor units (125050), or null while the text is empty or
 * not an exact amount. Nothing is rounded: a third decimal on a two-digit
 * currency is an error, not a silent cut.
 */
const props = withDefaults(
    defineProps<{
        modelValue: number | null;
        currency: string;
        label?: string;
        hint?: string;
        error?: string;
        optional?: boolean;
        /** Show the formatted amount under the field while the text is valid. */
        preview?: boolean;
    }>(),
    { label: undefined, hint: undefined, error: undefined, preview: true },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const { t } = useI18n();
const format = useFormat();

const text = ref(moneyText(props.modelValue, props.currency));

// Another part of the form changed the value (a reset, a prefill): follow it, but never rewrite what is being typed.
watch(() => props.modelValue, (value) => {
    if (parseMoney(text.value, props.currency) !== value) {
        text.value = moneyText(value, props.currency);
    }
});

const parsed = computed(() => parseMoney(text.value, props.currency));
const invalid = computed(() => text.value.trim() !== '' && parsed.value === null);
const message = computed(() => props.error ?? (invalid.value ? t('payroll.money.invalid', { digits: currencyDigits(props.currency) }) : undefined));
const shownHint = computed(() => {
    if (props.preview && parsed.value !== null && !message.value) {
        return format.money(parsed.value, props.currency);
    }

    return props.hint;
});

function update(value: string | number | null | undefined): void {
    text.value = String(value ?? '');
    emit('update:modelValue', parseMoney(text.value, props.currency));
}
</script>

<template>
    <Input
        :model-value="text"
        inputmode="decimal"
        autocomplete="off"
        mono
        :label="label"
        :hint="shownHint"
        :error="message"
        :optional="optional"
        @update:model-value="update"
    />
</template>
