<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { asTemplate } from '../lib/schema';

/**
 * A text field that may hold a value from the run. `path` mode is for fields
 * that take a bare path such as trigger.amount (suggestions in a native
 * datalist); `template` mode is for free text where a value can be inserted as
 * a template. Both only ever store text: nothing is evaluated here.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        mode?: 'path' | 'template';
        suggestions?: string[];
        hint?: string;
        error?: string;
        disabled?: boolean;
        mono?: boolean;
        maxlength?: number;
    }>(),
    { mode: 'path', suggestions: () => [], hint: undefined, error: undefined, maxlength: 500 },
);

const model = defineModel<string>({ default: '' });
const { t } = useI18n();
const listId = useId();

const options = computed(() => [
    { value: '', label: t('automation.editor.variable.insert') },
    ...props.suggestions.map((path) => ({ value: path, label: path })),
]);

function insert(path: string | number | null | undefined): void {
    if (typeof path !== 'string' || path === '') {
        return;
    }

    const separator = model.value === '' || model.value.endsWith(' ') ? '' : ' ';
    model.value = `${model.value}${separator}${asTemplate(path)}`;
}
</script>

<template>
    <div class="space-y-1.5">
        <Input
            v-model="model"
            :label="label"
            :hint="hint"
            :error="error"
            :disabled="disabled"
            :mono="mono || mode === 'path'"
            :maxlength="maxlength"
            :list="mode === 'path' && suggestions.length > 0 ? listId : undefined"
            autocomplete="off"
        />
        <datalist v-if="mode === 'path'" :id="listId">
            <option v-for="path in suggestions" :key="path" :value="path" />
        </datalist>
        <Select
            v-if="mode === 'template' && suggestions.length > 0 && !disabled"
            :model-value="''"
            :options="options"
            :aria-label="t('automation.editor.variable.insert')"
            @update:model-value="insert"
        />
    </div>
</template>
