<script setup lang="ts">
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { SendableTemplate } from './types';
import { renderTemplateBody, variablePositions } from './whatsappFormat';

/**
 * Pick an approved template and fill its numbered variables. Used by the composer (window closed) and by
 * "Start conversation". Only approved templates are ever offered; the server checks again.
 */
const props = defineProps<{
    templates: SendableTemplate[];
    error?: string;
    variableError?: string;
}>();

const templateId = defineModel<string>('templateId', { default: '' });
const values = defineModel<string[]>('values', { default: () => [] });

const { t } = useI18n();
const format = useFormat();

const selected = computed(() => props.templates.find((template) => template.id === templateId.value) ?? null);
const options = computed(() => props.templates.map((template) => ({ value: template.id, label: `${template.name} (${template.language})` })));
const positions = computed(() => variablePositions(selected.value?.variableCount ?? 0));
const preview = computed(() => (selected.value === null ? '' : renderTemplateBody(selected.value.body, values.value)));

watch(templateId, () => {
    values.value = Array.from({ length: selected.value?.variableCount ?? 0 }, () => '');
});

function setValue(index: number, value: string | number | null | undefined): void {
    const next = [...values.value];
    next[index] = value === null || value === undefined ? '' : String(value);
    values.value = next;
}
</script>

<template>
    <div class="grid gap-3">
        <Select
            v-model="templateId"
            :options="options"
            :label="t('whatsapp.send.template')"
            :placeholder="t('whatsapp.send.chooseTemplate')"
            :error="error"
        />
        <p v-if="selected" class="glass-well whitespace-pre-wrap rounded-control px-3 py-2 text-sm text-ink wrap-anywhere" data-testid="template-preview">
            {{ preview }}
        </p>
        <template v-if="selected && positions.length > 0">
            <p class="text-xs text-ink-subtle">{{ t('whatsapp.send.variablesHint', { count: format.number(positions.length) }) }}</p>
            <Input
                v-for="position in positions"
                :key="position"
                :model-value="values[position - 1] ?? ''"
                :label="t('whatsapp.send.variable', { position: format.number(position) })"
                maxlength="1024"
                :error="position === 1 ? variableError : undefined"
                @update:model-value="setValue(position - 1, $event)"
            />
        </template>
    </div>
</template>
