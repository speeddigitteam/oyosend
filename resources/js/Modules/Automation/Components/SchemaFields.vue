<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import {
    displayFieldValue,
    isTemplate,
    objectFromRows,
    parseFieldValue,
    rowsFromObject,
    schemaFields,
    type SchemaField,
} from '../lib/schema';
import KeyValueEditor from './KeyValueEditor.vue';
import VariableInput from './VariableInput.vue';

/**
 * Form fields generated from a trigger's or action's JSON Schema. Each field
 * is one of: a choice, a yes/no, a number, a list, a set of name/value rows or
 * text. Where `templates` is on, a value from the run can be inserted. The
 * server validates the result against the same schema.
 */
const props = defineProps<{
    schema: unknown;
    modelValue: Record<string, unknown>;
    suggestions?: string[];
    templates?: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: Record<string, unknown>] }>();
const { t } = useI18n();

const fields = computed(() => schemaFields(props.schema));

function label(field: SchemaField): string {
    return field.required ? field.key : `${field.key} (${t('automation.common.optional')})`;
}

function set(key: string, value: unknown): void {
    const next = { ...props.modelValue };

    if (value === undefined) {
        delete next[key];
    } else {
        next[key] = value;
    }

    emit('update:modelValue', next);
}

function text(key: string): string {
    return displayFieldValue(props.modelValue[key]);
}

function setText(field: SchemaField, raw: string | number | null | undefined): void {
    set(field.key, parseFieldValue(field.kind, String(raw ?? '')));
}

function booleanValue(key: string): string {
    const value = props.modelValue[key];

    return value === true ? 'true' : value === false ? 'false' : '';
}

const booleanOptions = computed(() => [
    { value: '', label: t('automation.editor.unset') },
    { value: 'true', label: t('automation.editor.yes') },
    { value: 'false', label: t('automation.editor.no') },
]);

function enumOptions(field: SchemaField): { value: string | number; label: string }[] {
    return [
        { value: '', label: t('automation.editor.unset') },
        ...field.options.map((option) => ({ value: option, label: String(option) })),
    ];
}

function setEnum(field: SchemaField, raw: string | number | null | undefined): void {
    if (raw === '' || raw === null) {
        set(field.key, undefined);

        return;
    }

    const match = field.options.find((option) => String(option) === String(raw));
    set(field.key, match ?? raw);
}

function isDynamic(key: string): boolean {
    return isTemplate(props.modelValue[key]);
}

function objectRows(key: string): Record<string, unknown> {
    return Object.fromEntries(rowsFromObject(props.modelValue[key]).map((row) => [row.key, row.value]));
}

function setObject(key: string, value: Record<string, unknown>): void {
    const normalized = objectFromRows(Object.entries(value).map(([name, item]) => ({ key: name, value: String(item ?? '') })));
    set(key, Object.keys(normalized).length === 0 ? undefined : normalized);
}
</script>

<template>
    <div class="space-y-3">
        <div v-for="field in fields" :key="field.key">
            <Select
                v-if="field.kind === 'enum'"
                :model-value="text(field.key)"
                :options="enumOptions(field)"
                :label="label(field)"
                :disabled="disabled"
                @update:model-value="setEnum(field, $event)"
            />

            <template v-else-if="field.kind === 'boolean' && !isDynamic(field.key)">
                <Select
                    :model-value="booleanValue(field.key)"
                    :options="booleanOptions"
                    :label="label(field)"
                    :disabled="disabled"
                    @update:model-value="set(field.key, $event === '' ? undefined : $event === 'true')"
                />
            </template>

            <template v-else-if="field.kind === 'object' && !isDynamic(field.key)">
                <p class="mb-1.5 text-[0.8125rem] font-medium text-ink">{{ label(field) }}</p>
                <KeyValueEditor
                    :model-value="objectRows(field.key)"
                    :disabled="disabled"
                    @update:model-value="setObject(field.key, $event)"
                />
            </template>

            <VariableInput
                v-else-if="templates"
                :model-value="text(field.key)"
                :label="label(field)"
                mode="template"
                :suggestions="suggestions ?? []"
                :disabled="disabled"
                :hint="field.kind === 'array' ? t('automation.editor.condition.valueList') : undefined"
                :maxlength="field.maxLength ?? 500"
                @update:model-value="setText(field, $event)"
            />

            <Input
                v-else
                :model-value="text(field.key)"
                :label="label(field)"
                :disabled="disabled"
                :maxlength="field.maxLength ?? 500"
                :hint="field.kind === 'array' ? t('automation.editor.condition.valueList') : undefined"
                @update:model-value="setText(field, $event)"
            />
        </div>
    </div>
</template>
