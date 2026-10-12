<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Download, Save, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { moduleLabel, scopeLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import SaveReportDialog from '../Components/SaveReportDialog.vue';
import {
    barWidth,
    buildQuery,
    cleanFilters,
    filterToDraft,
    forcedColumns,
    isNumericType,
    orderedSelection,
    rangeProblem,
} from '../Components/reportFormat';
import type { Bounds, ReportDefinition, ReportFilter, ReportResult, RunValues, SavedLimit, SavedRow, ShareOptions } from '../Components/types';
import { useReportFormat } from '../Components/useReportFormat';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    report: ReportDefinition;
    values: RunValues;
    result: ReportResult;
    saved: SavedRow | null;
    bounds: Bounds;
    canExport: boolean;
    canSave: boolean;
    share: ShareOptions;
    savedLimit: SavedLimit;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const { cell } = useReportFormat();

function initialDraft() {
    const filters: Record<string, string> = {};

    for (const filter of props.report.filters) {
        filters[filter.key] = filterToDraft(props.values.filters[filter.key]);
    }

    return { from: props.values.from, to: props.values.to, columns: [...props.values.columns], filters };
}

const draft = reactive(initialDraft());

watch(
    () => [props.report.key, props.saved?.id, props.saved?.updatedAt, props.values],
    () => Object.assign(draft, initialDraft()),
    { deep: true },
);

const saveOpen = ref(false);
const saveMode = ref<'create' | 'update'>('create');

function openSave(mode: 'create' | 'update'): void {
    saveMode.value = mode;
    saveOpen.value = true;
}
const deleteOpen = ref(false);
const deleting = ref(false);

// Columns --------------------------------------------------------------------------------------
const forced = computed(() => forcedColumns(props.report.columns, draft.columns));
const chosen = computed(() => orderedSelection(props.report.columns, [...draft.columns, ...forced.value]));

function isChecked(key: string): boolean {
    return draft.columns.includes(key) || forced.value.includes(key);
}

function toggle(key: string, on: boolean): void {
    draft.columns = on ? [...draft.columns, key] : draft.columns.filter((column) => column !== key);
}

function useDefaultColumns(): void {
    draft.columns = [...props.report.defaultColumns];
}

// Running ---------------------------------------------------------------------------------------
const problem = computed(() => (props.report.usesDateRange ? rangeProblem(draft.from, draft.to, props.bounds.maxDays) : null));
const filters = computed(() => cleanFilters(props.report.filters, draft.filters));
const query = computed(() => buildQuery({ usesDateRange: props.report.usesDateRange, from: draft.from, to: draft.to, columns: chosen.value, filters: filters.value }));

const appliedQuery = computed(() => buildQuery({
    usesDateRange: props.report.usesDateRange,
    from: props.values.from,
    to: props.values.to,
    columns: props.values.columns,
    filters: Object.fromEntries(Object.entries(props.values.filters).map(([key, value]) => [key, filterToDraft(value)])),
}));
const dirty = computed(() => JSON.stringify(query.value) !== JSON.stringify(appliedQuery.value));

const canRun = computed(() => problem.value === null && chosen.value.length > 0);

function run(): void {
    if (!canRun.value) {
        return;
    }

    router.get(route('reports.run', { provider: props.report.key }), query.value as Record<string, never>, { preserveScroll: true });
}

const errors = computed(() => (page.props.errors ?? {}) as Record<string, string | undefined>);
const serverErrors = computed(() => Object.values(errors.value).filter((message): message is string => typeof message === 'string' && message !== ''));

// Results ---------------------------------------------------------------------------------------
function pageUrl(number: number): string {
    if (props.saved !== null) {
        return route('reports.saved.show', { saved: props.saved.id, rows_page: number });
    }

    return route('reports.run', { provider: props.report.key, ...appliedQuery.value, rows_page: number });
}

const paginator = computed(() => {
    const { currentPage, lastPage, perPage, total } = props.result.page;

    return {
        prev_page_url: currentPage > 1 ? pageUrl(currentPage - 1) : null,
        next_page_url: currentPage < lastPage ? pageUrl(currentPage + 1) : null,
        from: total === 0 ? null : (currentPage - 1) * perPage + 1,
        to: total === 0 ? null : Math.min(currentPage * perPage, total),
        total,
    };
});

const exportUrl = computed(() => {
    if (props.saved !== null) {
        return route('reports.saved.export', { saved: props.saved.id });
    }

    return route('reports.export', { provider: props.report.key, ...appliedQuery.value });
});

const limitReached = computed(() => props.savedLimit.max !== null && props.savedLimit.used >= props.savedLimit.max);
const canManageSaved = computed(() => props.canSave && props.saved !== null && props.saved.canManage);

function removeSaved(): void {
    if (props.saved === null) {
        return;
    }

    deleting.value = true;
    router.delete(route('reports.saved.destroy', { saved: props.saved.id }), {
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
}

function filterOptions(filter: ReportFilter): { value: string; label: string }[] {
    if (filter.type === 'boolean') {
        return [
            { value: '', label: t('reports.run.any') },
            { value: '1', label: t('reports.common.yes') },
            { value: '0', label: t('reports.common.no') },
        ];
    }

    return [{ value: '', label: t('reports.run.any') }, ...filter.options];
}

function filterHint(filter: ReportFilter): string | undefined {
    switch (filter.type) {
        case 'currency':
            return t('reports.run.currencyHint');
        case 'id':
            return t('reports.run.idHint');
        case 'integer':
            return filter.min !== null && filter.max !== null
                ? t('reports.run.integerHint', { min: format.number(filter.min), max: format.number(filter.max) })
                : undefined;
        default:
            return undefined;
    }
}

const rowsText = computed(() => t('reports.run.rowCount', { count: format.number(props.result.page.total) }));
const rangeText = computed(() => (props.report.usesDateRange
    ? t('reports.run.covering', { from: format.date(props.result.from, 'date'), to: format.date(props.result.to, 'date') })
    : t('reports.run.snapshot')));
</script>

<template>
    <PageHeader
        :eyebrow="moduleLabel(report.module)"
        :title="saved?.name ?? report.label"
        :description="saved ? `${report.label}. ${report.description}` : report.description"
    >
        <template #actions>
            <Button variant="ghost" :href="route('reports.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('reports.run.back') }}
            </Button>
            <a
                v-if="canExport"
                :href="exportUrl"
                class="inline-flex h-[2.375rem] items-center justify-center gap-2 rounded-control border border-line-strong bg-control px-4 text-sm font-medium whitespace-nowrap text-ink hover:bg-control-hover"
                data-testid="export"
            >
                <Download class="size-4" aria-hidden="true" />
                {{ t('reports.run.export') }}
            </a>
            <Button v-if="canManageSaved" variant="danger" @click="deleteOpen = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('reports.run.delete') }}
            </Button>
        </template>
    </PageHeader>

    <div v-if="saved && !saved.isOwner && saved.ownerName" class="mb-3 text-[0.8125rem] text-ink-muted" data-testid="shared-by">
        {{ t('reports.run.sharedBy', { name: saved.ownerName }) }}
    </div>

    <Card class="mb-3" :title="t('reports.run.settings')">
        <form class="grid gap-5" novalidate @submit.prevent="run">
            <div v-if="report.usesDateRange">
                <div class="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
                    <Input v-model="draft.from" type="date" :label="t('reports.run.from')" :error="errors.from" />
                    <Input v-model="draft.to" type="date" :label="t('reports.run.to')" :error="errors.to" />
                </div>
                <p v-if="problem" class="mt-2 text-[0.8125rem] text-danger" role="alert" data-testid="range-problem">
                    {{ t(`reports.run.range.${problem}`, { days: format.number(bounds.maxDays) }) }}
                </p>
                <p v-else class="mt-2 text-[0.8125rem] text-ink-subtle">
                    {{ t('reports.run.rangeHint', { days: format.number(bounds.maxDays), timezone: page.props.app.timezone }) }}
                </p>
            </div>
            <p v-else class="text-[0.8125rem] text-ink-muted">{{ t('reports.run.snapshotHint') }}</p>

            <fieldset v-if="report.filters.length > 0" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <legend class="eyebrow mb-2">{{ t('reports.run.filters') }}</legend>
                <template v-for="filter in report.filters" :key="filter.key">
                    <Select
                        v-if="filter.type === 'select' || filter.type === 'boolean'"
                        v-model="draft.filters[filter.key]"
                        :options="filterOptions(filter)"
                        :label="filter.label"
                        :error="errors[`filters.${filter.key}`]"
                    />
                    <Input
                        v-else-if="filter.type === 'integer'"
                        v-model="draft.filters[filter.key]"
                        type="number"
                        inputmode="numeric"
                        :min="filter.min ?? undefined"
                        :max="filter.max ?? undefined"
                        :label="filter.label"
                        :hint="filterHint(filter)"
                        :error="errors[`filters.${filter.key}`]"
                    />
                    <Input
                        v-else
                        v-model="draft.filters[filter.key]"
                        :maxlength="filter.type === 'currency' ? 3 : filter.type === 'id' ? 26 : 100"
                        :mono="filter.type === 'currency' || filter.type === 'id'"
                        :label="filter.label"
                        :hint="filterHint(filter)"
                        :error="errors[`filters.${filter.key}`]"
                        autocomplete="off"
                    />
                </template>
            </fieldset>

            <fieldset>
                <legend class="eyebrow mb-2">{{ t('reports.run.columns') }}</legend>
                <div class="grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                    <Checkbox
                        v-for="column in report.columns"
                        :key="column.key"
                        :model-value="isChecked(column.key)"
                        :disabled="forced.includes(column.key) && !draft.columns.includes(column.key)"
                        :label="column.label"
                        :description="forced.includes(column.key) && !draft.columns.includes(column.key) ? t('reports.run.currencyIncluded') : undefined"
                        @update:model-value="toggle(column.key, $event)"
                    />
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <Button size="sm" variant="ghost" @click="useDefaultColumns">{{ t('reports.run.defaultColumns') }}</Button>
                    <p v-if="chosen.length === 0" class="text-[0.8125rem] text-danger" role="alert">{{ t('reports.run.noColumns') }}</p>
                </div>
            </fieldset>

            <p v-for="message in serverErrors" :key="message" class="text-[0.8125rem] text-danger" role="alert">{{ message }}</p>

            <div class="flex flex-wrap items-center gap-2">
                <Button type="submit" variant="primary" :disabled="!canRun">{{ t('reports.run.run') }}</Button>
                <Button v-if="canManageSaved" variant="secondary" :disabled="!canRun" @click="openSave('update')">
                    <template #icon><Save class="size-4" aria-hidden="true" /></template>
                    {{ t('reports.run.saveChanges') }}
                </Button>
                <Button v-if="canSave && !limitReached" variant="secondary" :disabled="!canRun" @click="openSave('create')">
                    <template #icon><Save class="size-4" aria-hidden="true" /></template>
                    {{ saved ? t('reports.run.saveAsNew') : t('reports.run.save') }}
                </Button>
            </div>

            <p v-if="dirty" class="text-[0.8125rem] text-ink-muted" data-testid="dirty">{{ t('reports.run.dirty') }}</p>
            <p v-if="saved" class="text-[0.8125rem] text-ink-subtle">{{ t('reports.run.savedHint') }}</p>
            <p v-if="canSave" class="text-[0.8125rem] text-ink-subtle" data-testid="saved-limit">
                <template v-if="limitReached">{{ t('reports.run.limitReached', { max: format.number(savedLimit.max ?? 0) }) }}</template>
                <template v-else-if="savedLimit.max !== null">
                    {{ t('reports.run.limit', { used: format.number(savedLimit.used), max: format.number(savedLimit.max) }) }}
                </template>
                <template v-else>{{ t('reports.run.limitUnlimited', { used: format.number(savedLimit.used) }) }}</template>
            </p>
        </form>
    </Card>

    <div v-if="result.scope !== 'all'" class="mb-3 rounded-control bg-info-soft px-4 py-3 text-sm text-info" role="note" data-testid="scope-notice">
        {{ t('reports.run.scopeNotice') }}
        <span class="text-[0.8125rem]">({{ scopeLabel(result.scope) }})</span>
    </div>
    <div v-if="result.truncated" class="mb-3 rounded-control bg-warn-soft px-4 py-3 text-sm text-warn" role="status" data-testid="truncated">
        {{ t('reports.run.truncated', { limit: format.number(result.rowLimit) }) }}
    </div>

    <Card :title="t('reports.run.results')" :description="`${rangeText} ${rowsText}`" flush>
        <EmptyState
            v-if="result.rows.length === 0"
            :title="t('reports.run.noRowsTitle')"
            :description="t('reports.run.noRowsBody')"
            data-testid="no-rows"
        />
        <template v-else>
            <div
                class="max-h-[70vh] overflow-auto border-t border-line"
                role="region"
                tabindex="0"
                :aria-label="t('reports.run.tableLabel', { report: saved?.name ?? report.label })"
            >
                <table class="w-full min-w-max text-left text-sm" data-testid="results">
                    <caption class="sr-only">{{ saved?.name ?? report.label }}. {{ rangeText }} {{ rowsText }}</caption>
                    <thead>
                        <tr>
                            <th
                                v-for="column in result.columns"
                                :key="column.key"
                                scope="col"
                                :class="[
                                    'eyebrow sticky top-0 z-10 border-b border-line bg-canvas px-4 py-2 font-normal whitespace-nowrap',
                                    isNumericType(column.type) ? 'text-right' : '',
                                ]"
                            >{{ column.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in result.rows" :key="index" class="border-b border-line last:border-b-0 hover:bg-hover">
                            <td
                                v-for="column in result.columns"
                                :key="column.key"
                                :class="['px-4 py-2', isNumericType(column.type) ? 'tabular text-right' : 'text-ink']"
                            >
                                <template v-if="cell(column, row, report.key) === null">
                                    <span class="text-ink-subtle" aria-hidden="true">{{ t('reports.common.dash') }}</span>
                                    <span class="sr-only">{{ t('reports.common.notReported') }}</span>
                                </template>
                                <span v-else-if="column.type === 'basis_points'" class="inline-flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-12 overflow-hidden rounded-chip bg-hover" aria-hidden="true">
                                        <span class="block h-full rounded-chip bg-brand" :style="{ width: `${barWidth(Number(row[column.key]))}%` }" />
                                    </span>
                                    <span>{{ cell(column, row, report.key) }}</span>
                                </span>
                                <span v-else :class="isNumericType(column.type) ? 'text-ink-muted' : ''">{{ cell(column, row, report.key) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-2 text-[0.8125rem] text-ink-subtle">
                {{ t('reports.run.nullLegend') }}
            </p>
            <Pagination :meta="paginator" />
        </template>
    </Card>

    <SaveReportDialog
        v-model:open="saveOpen"
        :report="report"
        :saved="saveMode === 'update' ? saved : null"
        :setup="{ from: draft.from, to: draft.to, columns: chosen, filters }"
        :share="share"
        :bounds="bounds"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('reports.delete.title')"
        :description="t('reports.delete.body', { name: saved?.name ?? '' })"
        :confirm-label="t('reports.delete.confirm')"
        :loading="deleting"
        @confirm="removeSaved"
    />
</template>
