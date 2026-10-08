<script setup lang="ts">
import { Check, Minus } from 'lucide-vue-next';
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        id?: string;
        label?: string;
        description?: string;
        disabled?: boolean;
        /** Shows a dash instead of a tick: some, but not all, of a group. */
        indeterminate?: boolean;
    }>(),
    { id: undefined, label: undefined, description: undefined },
);

const model = defineModel<boolean>({ default: false });

const generatedId = useId();
const inputId = computed(() => props.id ?? generatedId);
</script>

<template>
    <label
        :for="inputId"
        :class="['group flex items-start gap-2.5', disabled ? 'cursor-not-allowed opacity-55' : 'cursor-pointer']"
    >
        <span class="relative mt-px flex size-[1.125rem] shrink-0 items-center justify-center">
            <input
                :id="inputId"
                v-model="model"
                type="checkbox"
                :disabled="disabled"
                :aria-checked="indeterminate ? 'mixed' : undefined"
                :aria-describedby="description ? `${inputId}-description` : undefined"
                class="peer absolute inset-0 size-full cursor-[inherit] appearance-none rounded-[0.3125rem] border border-line-strong bg-control-sunken shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] transition-colors checked:border-brand checked:bg-brand focus-visible:outline-offset-2"
                :class="indeterminate ? 'border-brand bg-brand' : ''"
            />
            <Minus v-if="indeterminate" class="pointer-events-none relative size-3 text-brand-ink" :stroke-width="3.5" aria-hidden="true" />
            <Check
                v-else
                class="pointer-events-none relative size-3 text-brand-ink opacity-0 transition-opacity peer-checked:opacity-100"
                :stroke-width="3.5"
                aria-hidden="true"
            />
        </span>

        <span v-if="label || $slots.default" class="min-w-0 leading-snug">
            <span class="block text-sm text-ink"><slot>{{ label }}</slot></span>
            <span v-if="description" :id="`${inputId}-description`" class="mt-0.5 block text-[0.8125rem] text-ink-subtle">
                {{ description }}
            </span>
        </span>
    </label>
</template>
