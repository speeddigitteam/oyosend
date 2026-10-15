<script setup lang="ts">
import { ChevronsUpDown } from 'lucide-vue-next';
import { computed, useId } from 'vue';
import { controlAttrs } from '@/Core/lib/fieldAttrs';
import Field from './Field.vue';

export interface SelectOption {
    value: string | number;
    label: string;
    disabled?: boolean;
}

defineOptions({ inheritAttrs: false });

/**
 * A native select with the glass control surface. Native on purpose: it is
 * fully accessible, works with every keyboard and screen reader, and uses
 * the platform's own picker on touch devices.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        options: SelectOption[];
        label?: string;
        hint?: string;
        error?: string;
        optional?: boolean;
        /** Shown as a disabled first option when nothing is selected. */
        placeholder?: string;
        /** Classes for the `<select>` itself; `class` places the whole field. */
        controlClass?: unknown;
    }>(),
    { id: undefined, label: undefined, hint: undefined, error: undefined, placeholder: undefined, controlClass: undefined },
);

const model = defineModel<string | number | null>();

const generatedId = useId();
const selectId = computed(() => props.id ?? generatedId);
</script>

<template>
    <Field :for="selectId" :label="label" :hint="hint" :error="error" :optional="optional" :class="$attrs.class" :style="$attrs.style">
        <div class="relative">
            <select
                :id="selectId"
                v-model="model"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error ? `${selectId}-error` : hint ? `${selectId}-hint` : undefined"
                :class="['control appearance-none pr-9', controlClass]"
                v-bind="controlAttrs($attrs)"
            >
                <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
                <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">
                    {{ option.label }}
                </option>
            </select>
            <ChevronsUpDown
                class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-subtle"
                aria-hidden="true"
            />
        </div>
    </Field>
</template>
