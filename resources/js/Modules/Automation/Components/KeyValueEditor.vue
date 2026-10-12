<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import { objectFromRows, rowsFromObject, type KeyValueRow } from '../lib/schema';

/**
 * Edits a flat object as rows of name and value. Whole numbers and true / false
 * keep their type; anything else, including a template such as a trigger value
 * in double braces, stays text. This is declarative data: nothing is evaluated.
 */
const props = defineProps<{
    modelValue: Record<string, unknown>;
    disabled?: boolean;
    keyLabel?: string;
    valueLabel?: string;
    addLabel?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: Record<string, unknown>] }>();
const { t } = useI18n();

const rows = ref<KeyValueRow[]>(rowsFromObject(props.modelValue));

function sync(): void {
    emit('update:modelValue', objectFromRows(rows.value));
}

watch(
    () => props.modelValue,
    (value) => {
        if (JSON.stringify(value) !== JSON.stringify(objectFromRows(rows.value))) {
            rows.value = rowsFromObject(value);
        }
    },
    { deep: true },
);

function add(): void {
    rows.value = [...rows.value, { key: '', value: '' }];
}

function remove(index: number): void {
    rows.value = rows.value.filter((_, position) => position !== index);
    sync();
}

function edit(index: number, field: 'key' | 'value', value: string | number | null | undefined): void {
    rows.value = rows.value.map((row, position) => (position === index ? { ...row, [field]: String(value ?? '') } : row));
    sync();
}
</script>

<template>
    <div class="space-y-2">
        <p v-if="rows.length === 0" class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.keyValue.empty') }}</p>

        <div v-for="(row, index) in rows" :key="index" class="flex flex-wrap items-end gap-2">
            <div class="min-w-0 flex-1 basis-32">
                <Input
                    :label="keyLabel ?? t('automation.editor.keyValue.key')"
                    :model-value="row.key"
                    :disabled="disabled"
                    maxlength="64"
                    mono
                    @update:model-value="edit(index, 'key', $event)"
                />
            </div>
            <div class="min-w-0 flex-1 basis-40">
                <Input
                    :label="valueLabel ?? t('automation.editor.keyValue.value')"
                    :model-value="row.value"
                    :disabled="disabled"
                    maxlength="500"
                    @update:model-value="edit(index, 'value', $event)"
                />
            </div>
            <Button v-if="!disabled" icon variant="ghost" size="sm" type="button" @click="remove(index)">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.keyValue.remove') }}
            </Button>
        </div>

        <Button v-if="!disabled" size="sm" variant="secondary" type="button" @click="add">
            <template #icon><Plus class="size-4" aria-hidden="true" /></template>
            {{ addLabel ?? t('automation.editor.keyValue.add') }}
        </Button>
    </div>
</template>
