<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ChartNoAxesCombined } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ProgressBar from '../../Components/ProgressBar.vue';
import ProjectsNav from '../../Components/ProjectsNav.vue';
import ProjectStatusBadge from '../../Components/ProjectStatusBadge.vue';
import type { ProjectFinancials, ProjectStatus } from '../../Components/types';
defineOptions({ layout: TenantLayout });
interface ReportProject { id: string; code: string; name: string; status: ProjectStatus; progress: number; dueDate: string | null; taskCount: number; completedTaskCount: number; financials: ProjectFinancials | null }
interface WorkloadRow { membershipId: string; name: string; allocatedMinutes: number; taskCount: number }
interface ReportPagination { currentPage: number; perPage: number; total: number; lastPage: number; hasMorePages: boolean; previousPageUrl: string | null; nextPageUrl: string | null }
const props = defineProps<{ summary: { projectCount: number; activeCount: number; overdueCount: number; tasks: number; completedTasks: number; workload: WorkloadRow[] }; filters: { from: string | null; to: string | null }; projects: ReportProject[]; projectsPagination: ReportPagination }>();
const { t } = useI18n(); const format = useFormat();
const reportFilters = useForm({ from: props.filters.from ?? '', to: props.filters.to ?? '' });
const completion = computed(() => props.summary.tasks > 0 ? Math.round((props.summary.completedTasks / props.summary.tasks) * 100) : 0);
const pagination = computed(() => ({ prev_page_url: props.projectsPagination.previousPageUrl, next_page_url: props.projectsPagination.nextPageUrl, from: props.projects.length ? ((props.projectsPagination.currentPage - 1) * props.projectsPagination.perPage) + 1 : null, to: props.projects.length ? ((props.projectsPagination.currentPage - 1) * props.projectsPagination.perPage) + props.projects.length : null, total: props.projectsPagination.total }));
const columns = computed<Column[]>(() => [{ key: 'project', label: t('projects.fields.project') }, { key: 'status', label: t('projects.fields.status') }, { key: 'progress', label: t('projects.fields.progress') }, { key: 'tasks', label: t('projects.tasks.title'), align: 'right' }, { key: 'budget', label: t('projects.financials.budget'), align: 'right', hideable: true }, { key: 'spent', label: t('projects.financials.spent'), align: 'right', hideable: true }]);
function apply(): void {
    reportFilters.transform((data) => ({ from: data.from || undefined, to: data.to || undefined }))
        .get(route('projects.reports.index'), { preserveState: true, replace: true });
}
</script>
<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="t('projects.reports.title')" :description="t('projects.reports.description')" /><ProjectsNav current="reports" />
    <form class="glass mb-3 flex flex-wrap items-end gap-2 rounded-card p-4" @submit.prevent="apply"><Input v-model="reportFilters.from" type="date" :max="reportFilters.to || undefined" :label="t('projects.reports.from')" :error="reportFilters.errors.from" /><Input v-model="reportFilters.to" type="date" :min="reportFilters.from || undefined" :label="t('projects.reports.to')" :error="reportFilters.errors.to" /><Button type="submit" size="sm" :loading="reportFilters.processing">{{ t('projects.common.apply') }}</Button></form>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><StatTile :label="t('projects.reports.totalProjects')" :value="format.number(summary.projectCount)" /><StatTile :label="t('projects.dashboard.activeProjects')" :value="format.number(summary.activeCount)" /><StatTile :label="t('projects.reports.overdueProjects')" :value="format.number(summary.overdueCount)" :accent="summary.overdueCount > 0" /><StatTile :label="t('projects.reports.completion')" :value="`${format.number(completion)}%`" /></div>
    <div class="mt-3 grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]"><Card flush><DataTable :columns="columns" :rows="projects" row-key="id" :caption="t('projects.reports.delivery')" storage-key="project-report"><template #cell-project="{ row }"><div><Button size="sm" variant="ghost" :href="route('projects.show', { project: row.id })">{{ row.name }}</Button><p class="px-3 font-mono text-xs text-ink-subtle">{{ row.code }}</p></div></template><template #cell-status="{ row }"><ProjectStatusBadge :value="row.status" /></template><template #cell-progress="{ row }"><ProgressBar :value="row.progress" :label="t('projects.projects.progressLabel', { name: row.name })" compact /></template><template #cell-tasks="{ row }"><span class="font-mono">{{ format.number(row.completedTaskCount) }}/{{ format.number(row.taskCount) }}</span></template><template #cell-budget="{ row }"><span v-if="row.financials" class="font-mono">{{ format.money(row.financials.budgetAmount, row.financials.currency) }}</span><span v-else class="text-ink-subtle">{{ t('projects.financials.unavailable') }}</span></template><template #cell-spent="{ row }"><span v-if="row.financials" class="font-mono">{{ format.money(row.financials.spentAmount, row.financials.currency) }}</span><span v-else class="text-ink-subtle">{{ t('projects.financials.unavailable') }}</span></template><template #empty><EmptyState :title="t('projects.reports.noData')"><template #icon><ChartNoAxesCombined /></template></EmptyState></template></DataTable><Pagination :meta="pagination" /></Card><Card :title="t('projects.workload.title')" flush><EmptyState v-if="summary.workload.length === 0" :title="t('projects.workload.empty')" compact /><ul v-else class="divide-y divide-line"><li v-for="member in summary.workload" :key="member.membershipId" class="px-5 py-3"><div class="flex justify-between gap-3"><p class="text-sm font-medium text-ink">{{ member.name }}</p><span class="font-mono text-sm text-ink">{{ format.number(member.allocatedMinutes / 60, { maximumFractionDigits: 1 }) }} {{ t('projects.common.hoursShort') }}</span></div><p class="mt-1 text-xs text-ink-subtle">{{ t('projects.workload.taskCount', { count: format.number(member.taskCount) }) }}</p></li></ul></Card></div>
</template>

