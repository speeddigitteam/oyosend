<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * Label, hint and error around any form control. Input and Select use it
 * internally; use it directly to wrap a custom control.
 */
defineProps<{
    /** Id of the control this field labels. */
    for?: string;
    label?: string;
    hint?: string;
    error?: string;
    /** Marks the label as optional. */
    optional?: boolean;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div v-if="label || $slots.label" class="flex items-baseline justify-between gap-3">
            <label :for="$props.for" class="text-[0.8125rem] font-medium text-ink">
                <slot name="label">{{ label }}</slot>
                <span v-if="optional" class="ml-1 font-normal text-ink-subtle">({{ t('common.optional') }})</span>
            </label>
            <slot name="aside" />
        </div>

        <slot />

        <p v-if="error" :id="$props.for ? `${$props.for}-error` : undefined" class="text-[0.8125rem] text-danger" role="alert">
            {{ error }}
        </p>
        <p v-else-if="hint" :id="$props.for ? `${$props.for}-hint` : undefined" class="text-[0.8125rem] text-ink-subtle">
            {{ hint }}
        </p>
    </div>
</template>
