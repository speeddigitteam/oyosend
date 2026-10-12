<script setup lang="ts">
import { AlertTriangle, CircleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { issueMessage } from './payrollIssues';
import type { RunIssue } from './types';

/**
 * A run's or payslip's errors or warnings, each rendered from its code through
 * the interface language. Errors block submission; warnings are for review.
 */
const props = defineProps<{
    kind: 'errors' | 'warnings';
    issues: RunIssue[];
    currency: string;
    title?: string;
}>();

const { t } = useI18n();
const format = useFormat();

const messages = computed(() =>
    props.issues.map((issue, index) => {
        const { key, params } = issueMessage(props.kind, issue, {
            number: (value) => format.number(value),
            money: (amount) => format.money(amount, props.currency),
        });

        return { id: `${index}-${issue.code}-${issue.employee_id ?? ''}`, text: t(key, params), code: issue.code };
    }),
);
</script>

<template>
    <section
        v-if="issues.length > 0"
        :class="['rounded-card border px-4 py-3', kind === 'errors' ? 'border-danger/40 bg-danger-soft' : 'border-warn/40 bg-warn-soft']"
        :aria-label="title ?? t(`payroll.codes.${kind}Title`)"
        :data-testid="`run-${kind}`"
    >
        <h3 :class="['flex items-center gap-2 text-sm font-semibold', kind === 'errors' ? 'text-danger' : 'text-warn']">
            <CircleAlert v-if="kind === 'errors'" class="size-4" aria-hidden="true" />
            <AlertTriangle v-else class="size-4" aria-hidden="true" />
            {{ title ?? t(`payroll.codes.${kind}Title`) }}
        </h3>
        <ul class="mt-2 grid gap-1 text-sm text-ink">
            <li v-for="message in messages" :key="message.id" :data-code="message.code">{{ message.text }}</li>
        </ul>
    </section>
</template>
