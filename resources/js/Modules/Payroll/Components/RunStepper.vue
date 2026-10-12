<script setup lang="ts">
import { Check } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { RunStatus } from './types';

/** The run's path: draft, submitted for approval, approved and locked. */
const props = defineProps<{ status: RunStatus }>();

const { t } = useI18n();

const STEPS: RunStatus[] = ['draft', 'submitted', 'approved'];
const current = computed(() => STEPS.indexOf(props.status));
</script>

<template>
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-2" :aria-label="t('payroll.runs.stepsLabel')" data-testid="run-stepper">
        <li
            v-for="(step, index) in STEPS"
            :key="step"
            class="flex items-center gap-2"
            :aria-current="index === current ? 'step' : undefined"
            :data-state="index < current ? 'done' : index === current ? 'current' : 'todo'"
        >
            <span
                :class="[
                    'flex size-6 items-center justify-center rounded-full text-xs font-medium',
                    index < current ? 'bg-ok-soft text-ok' : index === current ? 'bg-brand-soft text-brand-text' : 'bg-hover text-ink-subtle',
                ]"
                aria-hidden="true"
            >
                <Check v-if="index < current || (index === current && step === 'approved')" class="size-3.5" />
                <template v-else>{{ index + 1 }}</template>
            </span>
            <span :class="['text-sm', index === current ? 'font-medium text-ink' : 'text-ink-muted']">
                {{ t(`payroll.status.run.${step}`) }}
            </span>
            <span v-if="index < STEPS.length - 1" class="mx-1 h-px w-6 bg-line-strong" aria-hidden="true" />
        </li>
    </ol>
</template>
