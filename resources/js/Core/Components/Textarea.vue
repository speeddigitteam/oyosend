<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { controlAttrs } from '@/Core/lib/fieldAttrs';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });

/**
 * Several lines of text: notes, an address, a reason. Same label, hint and
 * error treatment as Input; `class` and `style` place the whole field and
 * other attributes fall through to the `<textarea>`.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        label?: string;
        hint?: string;
        error?: string;
        optional?: boolean;
        rows?: number;
        /** Classes for the `<textarea>` itself. */
        controlClass?: unknown;
    }>(),
    { id: undefined, label: undefined, hint: undefined, error: undefined, rows: 3, controlClass: undefined },
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
    <Field :for="inputId" :label="label" :hint="hint" :error="error" :optional="optional" :class="$attrs.class" :style="$attrs.style">
        <template v-if="$slots.aside" #aside><slot name="aside" /></template>

        <textarea
            :id="inputId"
            ref="element"
            v-model="model"
            :rows="rows"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            :class="['control min-h-20 resize-y', controlClass]"
            v-bind="controlAttrs($attrs)"
        />
    </Field>
</template>
