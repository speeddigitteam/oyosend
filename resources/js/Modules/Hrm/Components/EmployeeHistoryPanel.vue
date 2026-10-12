<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { EmployeeFormOptions, HistoryEntry } from './types';

/**
 * The employment ledger as a timeline. It is append-only on the server, so this
 * only reads. Ids are shown as names where the person's form options know them.
 */
const props = defineProps<{
    history: HistoryEntry[];
    options: EmployeeFormOptions | null;
    /** Whether cost-rate changes may be shown. */
    showCost: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const DATES = ['probation_ends_on', 'confirmed_on', 'terminated_on', 'last_working_on'];
const ENUMS: Record<string, string> = { status: 'hrm.status.employee', employment_type: 'hrm.employmentType', termination_type: 'hrm.termination' };

const lookup = computed(() => {
    const source = props.options;

    return {
        department_id: new Map((source?.departments ?? []).map((item) => [item.id, item.name])),
        branch_id: new Map((source?.branches ?? []).map((item) => [item.id, item.name])),
        designation_id: new Map((source?.designations ?? []).map((item) => [item.id, item.name])),
        manager_id: new Map((source?.managers ?? []).map((item) => [item.id, item.name])),
    } as Record<string, Map<string, string>>;
});

function show(field: string, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return t('hrm.common.none');
    }

    const text = String(value);

    if (ENUMS[field]) {
        return t(`${ENUMS[field]}.${text}`);
    }

    if (DATES.includes(field)) {
        return format.date(text);
    }

    if (lookup.value[field]) {
        return lookup.value[field].get(text) ?? t('hrm.history.someone');
    }

    if (field === 'owner_id') {
        return t('hrm.history.account');
    }

    return text;
}

interface Row { key: string; field: string; from: string; to: string }

function rows(entry: HistoryEntry): Row[] {
    return Object.entries(entry.changes ?? {})
        .filter(([field]) => props.showCost || !field.startsWith('cost_'))
        .map(([field, change]) => {
            const pair = change as { from?: unknown; to?: unknown };

            return { key: field, field, from: show(field, pair.from), to: show(field, pair.to) };
        });
}
</script>

<template>
    <EmptyState v-if="history.length === 0" :title="t('hrm.history.empty')" compact />
    <ol v-else class="relative grid gap-5 border-l border-line pl-5" data-testid="employee-history">
        <li v-for="entry in history" :key="entry.id" class="relative">
            <span class="absolute top-1.5 -left-[1.6875rem] size-2.5 rounded-full bg-brand" aria-hidden="true" />
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-medium text-ink">{{ t(`hrm.history.events.${entry.event}`) }}</p>
                <p class="tabular text-xs text-ink-subtle">{{ format.date(entry.effectiveOn) }}</p>
            </div>
            <ul v-if="rows(entry).length > 0" class="mt-1.5 grid gap-1 text-sm text-ink-muted">
                <li v-for="row in rows(entry)" :key="row.key">
                    <span class="text-ink">{{ t(`hrm.history.fields.${row.field}`) }}</span>:
                    <template v-if="entry.event === 'joined'">{{ row.to }}</template>
                    <template v-else>{{ row.from }} <ArrowRight class="inline size-3.5 align-text-bottom" aria-hidden="true" /><span class="sr-only">{{ t('hrm.history.changedTo') }}</span> {{ row.to }}</template>
                </li>
            </ul>
            <p v-if="entry.reason" class="mt-1.5 text-sm text-ink-muted">{{ t('hrm.fields.reason') }}: {{ entry.reason }}</p>
        </li>
    </ol>
</template>
