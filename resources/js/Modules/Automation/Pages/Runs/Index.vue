<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Clock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import WorkflowBadge from '../../Components/WorkflowBadge.vue';
import type { Paginated, RunRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    runs: Paginated<RunRow>;
    filters: { status: string; source: string; workflow: string; mode: string };
    statuses: string[];
    workflows: { id: string; name: string }[];
    abilities: { manage: boolean; viewWorkflows: boolean };
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status);
const source = ref(props.filters.source);
const workflow = ref(props.filters.workflow);
const mode = ref(props.filters.mode);

const statusOptions = computed(() => [
    { value: '', label: t('automation.runs.filters.allStatuses') },
    ...props.statuses.map((value) => ({ value, label: t(`automation.status.run.${value}`) })),
]);
const sourceOptions = computed(() => [
    { value: '', label: t('automation.runs.filters.allSources') },
    ...['event', 'webhook', 'schedule', 'manual', 'test'].map((value) => ({ value, label: t(`automation.source.${value}`) })),
]);
const workflowOptions = computed(() => [
    { value: '', label: t('automation.runs.filters.allWorkflows') },
    ...props.workflows.map((item) => ({ value: item.id, label: item.name })),
]);
const modeOptions = computed(() => [
    { value: '', label: t('automation.runs.filters.allModes') },
    { value: 'live', label: t('automation.mode.live') },
    { value: 'dry_run', label: t('automation.mode.dry_run') },
]);
const filtered = computed(() => status.value !== '' || source.value !== '' || workflow.value !== '' || mode.value !== '');

const columns = computed<Column[]>(() => [
    { key: 'workflow', label: t('automation.runs.workflow') },
    { key: 'status', label: t('automation.common.status') },
    { key: 'source', label: t('automation.runs.source'), hideable: true },
    { key: 'startedBy', label: t('automation.runs.startedBy'), hideable: true, hiddenByDefault: true },
    { key: 'steps', label: t('automation.runs.steps'), align: 'right', hideable: true },
    { key: 'started', label: t('automation.runs.started'), hideable: true },
]);

function apply(): void {
    router.get(
        route('automation.runs.index'),
        {
            status: status.value || undefined,
            source: source.value || undefined,
            workflow: workflow.value || undefined,
            mode: mode.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    status.value = '';
    source.value = '';
    workflow.value = '';
    mode.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('automation.eyebrow')" :title="t('automation.runs.title')" :description="t('automation.runs.description')" />

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <div class="w-full sm:w-44"><Select v-model="status" :options="statusOptions" :label="t('automation.runs.filters.status')" /></div>
            <div class="w-full sm:w-44"><Select v-model="source" :options="sourceOptions" :label="t('automation.runs.filters.source')" /></div>
            <div class="w-full sm:w-52"><Select v-model="workflow" :options="workflowOptions" :label="t('automation.runs.filters.workflow')" /></div>
            <div class="w-full sm:w-36"><Select v-model="mode" :options="modeOptions" :label="t('automation.runs.filters.mode')" /></div>
            <Button type="submit" size="sm">{{ t('automation.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('automation.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="runs.data" row-key="id" :caption="t('automation.runs.caption')" storage-key="automation-runs">
            <template #cell-workflow="{ row }">
                <Link :href="route('automation.runs.show', { run: row.id })" class="font-medium text-ink hover:text-brand-text">
                    {{ row.workflowName ?? t('automation.common.unnamed') }}
                </Link>
                <span class="block text-xs text-ink-subtle">
                    {{ t('automation.runs.version') }} {{ row.versionNumber === null ? t('automation.common.dash') : format.number(row.versionNumber) }}
                </span>
            </template>
            <template #cell-status="{ row }">
                <WorkflowBadge kind="run" :value="row.status" />
                <Badge v-if="row.mode === 'dry_run'" tone="info" class="ml-1">{{ t('automation.mode.dry_run') }}</Badge>
            </template>
            <template #cell-source="{ row }"><span class="text-ink-muted">{{ t(`automation.source.${row.source}`) }}</span></template>
            <template #cell-startedBy="{ row }"><span class="text-ink-muted">{{ row.startedBy ?? t('automation.common.dash') }}</span></template>
            <template #cell-steps="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.stepsExecuted) }}</span></template>
            <template #cell-started="{ row }">
                <span class="text-ink-muted">{{ format.date(row.startedAt ?? row.createdAt, 'datetime') }}</span>
            </template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('automation.common.noMatches') : t('automation.runs.empty')"
                    :description="filtered ? t('automation.common.noMatchesBody') : t('automation.runs.emptyBody')"
                    compact
                >
                    <template #icon><Clock /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="runs" />
    </Card>
</template>
