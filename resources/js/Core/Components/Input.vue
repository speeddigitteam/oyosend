<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        id?: string;
        type?: string;
        label?: string;
        hint?: string;
        error?: string;
        optional?: boolean;
        /** Monospace value: codes, tokens, identifiers. */
        mono?: boolean;
    }>(),
    { type: 'text', id: undefined, label: undefined, hint: undefined, error: undefined },
);

const model = defineModel<string | number | null>();

const generatedId = useId();
const inputId = computed(() => props.id ?? generatedId);
const element = ref<HTMLInputElement | null>(null);

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

        <div class="relative">
            <span
                v-if="$slots.leading"
                class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-subtle"
            >
                <slot name="leading" />
            </span>

            <input
                :id="inputId"
                ref="element"
                v-model="model"
                :type="type"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                :class="[
                    'control',
                    $slots.leading ? 'pl-10' : '',
                    $slots.trailing ? 'pr-10' : '',
                    mono ? 'font-mono tracking-wide' : '',
                ]"
                v-bind="$attrs"
            />

            <span v-if="$slots.trailing" class="absolute inset-y-0 right-0 flex items-center pr-1.5">
                <slot name="trailing" />
            </span>
        </div>
    </Field>
</template>
