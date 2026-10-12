<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Workflow } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import WorkflowBadge from '../../Components/WorkflowBadge.vue';
import type { Paginated, TemplateRow, UsageFigure, WorkflowAbilities, WorkflowRow } from '../../Components/types';
import { definitionLabel } from '../../lib/labels';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    workflows: Paginated<WorkflowRow>;
    filters: { search: string; status: string };
    statuses: string[];
    templates: TemplateRow[];
    usage: { workflows: UsageFigure; runs: UsageFigure };
    abilities: WorkflowAbilities;
}>();

const { t } = useI18n();
const format = useFormat();

const search = ref(props.filters.search);
const status = ref(props.filters.status === '' ? 'all' : props.filters.status);
const creating = ref(false);

const statusOptions = computed(() => [
    { value: 'all', label: t('automation.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`automation.status.workflow.${value}`) })),
]);
const filtered = computed(() => search.value.trim() !== '' || status.value !== 'all');

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('automation.common.name') },
    { key: 'status', label: t('automation.common.status') },
    { key: 'trigger', label: t('automation.workflows.trigger'), hideable: true },
    { key: 'owner', label: t('automation.workflows.owner'), hideable: true },
    { key: 'lastRun', label: t('automation.workflows.lastRun'), hideable: true },
    { key: 'updated', label: t('automation.common.updated'), hideable: true, hiddenByDefault: true },
]);

function apply(): void {
    router.get(
        route('automation.workflows.index'),
        { search: search.value.trim() || undefined, status: status.value === 'all' ? undefined : status.value },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    search.value = '';
    status.value = 'all';
    apply();
}

function usageText(figure: UsageFigure): string {
    return figure.limit === null
        ? t('automation.workflows.usageUnlimited')
        : t('automation.workflows.usageOf', { limit: format.number(figure.limit) });
}

const form = useForm({ name: '', description: '', template: '' });
const templateOptions = computed(() => [
    { value: '', label: t('automation.workflows.create.blank') },
    ...props.templates.map((template) => ({ value: template.key, label: template.name })),
]);
const chosenTemplate = computed(() => props.templates.find((template) => template.key === form.template));

function open(): void {
    form.reset();
    form.clearErrors();
    creating.value = true;
}

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
        template: data.template === '' ? null : data.template,
    })).post(route('automation.workflows.store'), {
        onSuccess: () => {
            creating.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('automation.eyebrow')" :title="t('automation.workflows.title')" :description="t('automation.workflows.description')">
        <template v-if="abilities.manage" #actions>
            <Button variant="primary" type="button" @click="open">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.workflows.add') }}
            </Button>
        </template>
    </PageHeader>

    <div class="mb-3 grid gap-3 sm:grid-cols-2">
        <StatTile
            :label="t('automation.workflows.usageWorkflows')"
            :value="format.number(usage.workflows.used)"
            :hint="usageText(usage.workflows)"
        />
        <StatTile :label="t('automation.workflows.usageRuns')" :value="format.number(usage.runs.used)" :hint="usageText(usage.runs)" />
    </div>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <div class="w-full sm:w-64">
                <Input v-model="search" type="search" :label="t('automation.common.search')" maxlength="100" />
            </div>
            <div class="w-full sm:w-44">
                <Select v-model="status" :options="statusOptions" :label="t('automation.common.status')" />
            </div>
            <Button type="submit" size="sm">{{ t('automation.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('automation.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="workflows.data" row-key="id" :caption="t('automation.workflows.caption')" storage-key="automation-workflows">
            <template #cell-name="{ row }">
                <Link :href="route('automation.workflows.show', { workflow: row.id })" class="font-medium text-ink hover:text-brand-text">{{ row.name }}</Link>
                <span v-if="row.description" class="block max-w-md truncate text-xs text-ink-subtle">{{ row.description }}</span>
            </template>
            <template #cell-status="{ row }"><WorkflowBadge kind="workflow" :value="row.status" /></template>
            <template #cell-trigger="{ row }">
                <span class="text-ink-muted">{{ row.triggerKey ? definitionLabel('automation.triggers.' + row.triggerKey, row.triggerKey) : t('automation.workflows.noTrigger') }}</span>
            </template>
            <template #cell-owner="{ row }"><span class="text-ink-muted">{{ row.actor?.name ?? t('automation.common.dash') }}</span></template>
            <template #cell-lastRun="{ row }">
                <span v-if="row.lastRunAt" class="text-ink-muted">
                    {{ format.relative(row.lastRunAt) }}
                    <WorkflowBadge v-if="row.lastRunStatus" kind="run" :value="row.lastRunStatus" class="ml-1" />
                </span>
                <span v-else class="text-ink-subtle">{{ t('automation.workflows.never') }}</span>
            </template>
            <template #cell-updated="{ row }"><span class="text-ink-muted">{{ format.date(row.updatedAt, 'datetime') }}</span></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('automation.common.noMatches') : t('automation.workflows.empty')"
                    :description="filtered ? t('automation.common.noMatchesBody') : t('automation.workflows.emptyBody')"
                    compact
                >
                    <template #icon><Workflow /></template>
                    <Button v-if="abilities.manage && !filtered" size="sm" variant="primary" type="button" @click="open">{{ t('automation.workflows.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="workflows" />
    </Card>

    <Modal v-model:open="creating" :title="t('automation.workflows.create.title')" :description="t('automation.workflows.create.description')" :persistent="form.processing">
        <form id="create-workflow" class="space-y-4" @submit.prevent="submit">
            <Input v-model="form.name" :label="t('automation.workflows.create.name')" :error="form.errors.name" maxlength="160" required />
            <Textarea v-model="form.description" :label="t('automation.workflows.create.descriptionLabel')" :error="form.errors.description" optional maxlength="2000" />
            <Select
                v-model="form.template"
                :options="templateOptions"
                :label="t('automation.workflows.create.template')"
                :error="form.errors.template"
                :hint="chosenTemplate?.description"
            />
        </form>
        <template #footer>
            <Button variant="ghost" type="button" :disabled="form.processing" @click="creating = false">{{ t('automation.common.cancel') }}</Button>
            <Button variant="primary" type="submit" form="create-workflow" :loading="form.processing" :disabled="form.name.trim() === ''">
                {{ t('automation.workflows.create.submit') }}
            </Button>
        </template>
    </Modal>
</template>
