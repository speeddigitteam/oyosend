<script setup lang="ts">
import { ref } from 'vue';
import Input from '@/Core/Components/Input.vue';

defineOptions({ inheritAttrs: false });

/**
 * Field for the 6-digit code of an authenticator app: numeric keypad on
 * touch devices, one-time-code autofill, and spaced mono digits that are
 * easy to check against the app. Other attributes fall through to the
 * `<input>`.
 *
 * The value is cleaned as it is typed, the same way the workspace sign-in
 * does it, so "123 456" pasted from a message arrives as "123456".
 */
defineProps<{
    label: string;
    hint?: string;
    error?: string;
}>();

const model = defineModel<string>({ default: '' });

const input = ref<InstanceType<typeof Input> | null>(null);

const BENGALI_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Six Latin digits at most. A Bengali keyboard types ০-৯, which the server would not read as a code. */
function toCode(value: string): string {
    return value
        .replace(/[০-৯]/g, (digit) => String(BENGALI_DIGITS.indexOf(digit)))
        .replace(/\D/g, '')
        .slice(0, 6);
}

/*
 * The element is written to directly as well as the model: when cleaning
 * gives back the value the model already had (a letter typed after "123"),
 * nothing changes for Vue and the stray character would stay on screen.
 */
function clean(event: Event): void {
    // Leave an input method alone until it has finished composing.
    if (event instanceof InputEvent && event.isComposing) {
        return;
    }

    const element = event.target as HTMLInputElement;
    const code = toCode(element.value);

    if (element.value !== code) {
        element.value = code;
    }

    model.value = code;
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <Input
        ref="input"
        :model-value="model"
        :label="label"
        :hint="hint"
        :error="error"
        name="code"
        inputmode="numeric"
        autocomplete="one-time-code"
        autocapitalize="off"
        spellcheck="false"
        control-class="tabular h-12 font-mono text-lg tracking-[0.3em]"
        v-bind="$attrs"
        @update:model-value="model = toCode(String($event ?? ''))"
        @input="clean"
        @change="clean"
    />
</template>
