<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });

/**
 * Several lines of text: notes, an address, a reason. Same label, hint and
 * error treatment as Input; other attributes fall through to the `<textarea>`.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        label?: string;
        hint?: string;
        error?: string;
        optional?: boolean;
        rows?: number;
    }>(),
    { id: undefined, label: undefined, hint: undefined, error: undefined, rows: 3 },
);

const model = defineModel<string | null>();

const generatedId = useId();
const inputId = computed(() => props.id ?? generatedId);
const element = ref<HTMLTextAreaElement | null>(null);

const describedBy = computed(() => {
    if (props.error) {
        return `${inputId.value}-error`;
    }

    return props.hint ? `${inputId.value}-hint` : undefined;
});

defineExpose({ focus: () => element.value?.focus() });
</script>

<template>
    <Field :for="inputId" :label="label" :hint="hint" :error="error" :optional="optional">
        <template v-if="$slots.aside" #aside><slot name="aside" /></template>

        <textarea
            :id="inputId"
            ref="element"
            v-model="model"
            :rows="rows"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="control min-h-20 resize-y"
            v-bind="$attrs"
        />
    </Field>
</template>
