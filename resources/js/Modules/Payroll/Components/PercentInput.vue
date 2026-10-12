<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import { bpsText, parseBps } from './payrollMoney';

/**
 * A rate field. The person types a percentage with up to two decimals ("7.5");
 * the model is the exact basis-point integer (750), or null while the text is
 * empty or invalid. `max` is in basis points.
 */
const props = withDefaults(
    defineProps<{
        modelValue: number | null;
        label?: string;
        hint?: string;
        error?: string;
        optional?: boolean;
        max?: number;
    }>(),
    { label: undefined, hint: undefined, error: undefined, max: 10_000 },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const { t } = useI18n();
const text = ref(bpsText(props.modelValue));

watch(() => props.modelValue, (value) => {
    if (parseBps(text.value) !== value) {
        text.value = bpsText(value);
    }
});

const parsed = computed(() => parseBps(text.value));
const invalid = computed(() => text.value.trim() !== '' && (parsed.value === null || parsed.value > props.max));
const message = computed(() => props.error ?? (invalid.value ? t('payroll.money.invalidPercent', { max: bpsText(props.max) }) : undefined));

function update(value: string | number | null | undefined): void {
    text.value = String(value ?? '');
    const next = parseBps(text.value);
    emit('update:modelValue', next !== null && next <= props.max ? next : null);
}
</script>

<template>
    <Input
        :model-value="text"
        inputmode="decimal"
        autocomplete="off"
        mono
        :label="label"
        :hint="hint"
        :error="message"
        :optional="optional"
        @update:model-value="update"
    >
        <template #trailing><span class="text-ink-subtle" aria-hidden="true">%</span></template>
    </Input>
</template>
