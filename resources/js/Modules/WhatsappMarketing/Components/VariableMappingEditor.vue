<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { VariableRow } from './whatsappFormat';

/**
 * One row per numbered template variable: it is either one of the allow-listed contact/workspace fields or a
 * fixed text. Nothing here is an expression; the server only accepts these two shapes.
 */
const props = defineProps<{
    rows: VariableRow[];
    fields: string[];
    errors?: Record<string, string | undefined>;
}>();

const emit = defineEmits<{ 'update:rows': [rows: VariableRow[]] }>();

const { t } = useI18n();
const format = useFormat();

function patch(index: number, change: Partial<VariableRow>): void {
    emit(
        'update:rows',
        props.rows.map((row, position) => (position === index ? { ...row, ...change } : row)),
    );
}

function fieldOptions() {
    return [
        { value: '', label: t('whatsapp.campaigns.chooseField') },
        ...props.fields.map((field) => ({ value: field, label: t(`whatsapp.fields.${field}`) })),
    ];
}

function kindOptions() {
    return [
        { value: 'field', label: t('whatsapp.campaigns.fromField') },
        { value: 'text', label: t('whatsapp.campaigns.fixedText') },
    ];
}

function rowError(row: VariableRow): string | undefined {
    const errors = props.errors ?? {};

    return errors[`variables.${row.position}`] ?? errors[`variables.${row.position}.type`] ?? errors[`variables.${row.position}.field`] ?? errors[`variables.${row.position}.value`];
}
</script>

<template>
    <div class="grid gap-3">
        <div v-for="(row, index) in rows" :key="row.position" class="grid gap-2 rounded-control border border-line p-3" data-testid="variable-row">
            <p class="eyebrow">{{ t('whatsapp.campaigns.variable', { position: format.number(row.position) }) }}</p>
            <SegmentedControl
                :model-value="row.kind"
                :options="kindOptions()"
                :label="t('whatsapp.campaigns.variableSource', { position: format.number(row.position) })"
                @update:model-value="patch(index, { kind: $event as 'field' | 'text' })"
            />
            <Select
                v-if="row.kind === 'field'"
                :model-value="row.field"
                :options="fieldOptions()"
                :label="t('whatsapp.campaigns.field')"
                :error="rowError(row)"
                @update:model-value="patch(index, { field: String($event ?? '') })"
            />
            <Input
                v-else
                :model-value="row.value"
                :label="t('whatsapp.campaigns.text')"
                :error="rowError(row)"
                maxlength="200"
                @update:model-value="patch(index, { value: String($event ?? '') })"
            />
        </div>
    </div>
</template>
