<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import type { AppSetting } from './types';

const props = defineProps<{ setting: AppSetting; modelValue: string | number | boolean | null; error?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string | number | boolean | null] }>();

const { t } = useI18n();

const scalar = computed<string | number | null>({
    get: () => (typeof props.modelValue === 'boolean' ? null : props.modelValue),
    set: (value) => emit('update:modelValue', value),
});
const checked = computed<boolean>({
    get: () => props.modelValue === true,
    set: (value) => emit('update:modelValue', value),
});
const options = computed<SelectOption[]>(() => [
    ...(!props.setting.required ? [{ value: '', label: t('appStore.settings.notConfigured') }] : []),
    ...Object.entries(props.setting.options).map(([value, label]) => ({ value, label })),
]);
const hint = computed(() => {
    const parts = [props.setting.description];

    if (props.setting.type === 'secret' && props.setting.hasValue) {
        parts.push(t('appStore.settings.secretRetained'));
    }

    return parts.filter(Boolean).join(' ');
});
</script>

<template>
    <Field v-if="setting.type === 'boolean'" :error="error">
        <Checkbox v-model="checked" :label="setting.label" :description="setting.description ?? undefined" />
    </Field>

    <Select
        v-else-if="setting.type === 'select'"
        v-model="scalar"
        :name="`settings[${setting.key}]`"
        :label="setting.label"
        :hint="setting.description ?? undefined"
        :error="error"
        :optional="!setting.required"
        :placeholder="setting.required ? t('appStore.settings.choose') : undefined"
        :options="options"
    />

    <Input
        v-else
        v-model="scalar"
        :name="`settings[${setting.key}]`"
        :type="setting.type === 'secret' ? 'password' : setting.type === 'integer' ? 'number' : 'text'"
        :label="setting.label"
        :hint="hint || undefined"
        :error="error"
        :optional="!setting.required"
        :placeholder="setting.type === 'secret' && setting.hasValue ? t('appStore.settings.secretPlaceholder') : undefined"
        :min="setting.type === 'integer' ? (setting.min ?? undefined) : undefined"
        :max="setting.type === 'integer' ? (setting.max ?? undefined) : undefined"
        :maxlength="setting.type !== 'integer' ? setting.max_length : undefined"
        :step="setting.type === 'integer' ? 1 : undefined"
        :autocomplete="setting.type === 'secret' ? 'off' : undefined"
    />
</template>
