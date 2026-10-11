<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BriefcaseBusiness, Plus, Search } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ProgressBar from '../../Components/ProgressBar.vue';
import ProjectFormDialog from '../../Components/ProjectFormDialog.vue';
import ProjectsNav from '../../Components/ProjectsNav.vue';
import ProjectStatusBadge from '../../Components/ProjectStatusBadge.vue';
import type { Member, Option, Paginated, Project, ProjectFormOptions, ProjectStatus } from '../../Components/types';
defineOptions({ layout: TenantLayout });
const props = defineProps<{ projects: Paginated<Project>; filters: { search: string; status: ProjectStatus | null; category: string | null }; categories: Option[]; templates: Array<Option & { category_id?: string | null }>; contacts: Option[]; companies: Option[]; members: Member[]; currency: string; canCreate: boolean; canManageFinancials: boolean; openCreate?: boolean }>();
const { t } = useI18n(); const format = useFormat(); const loading = ref(false); const formOpen = ref(false); const search = ref(props.filters.search); const status = ref(props.filters.status ?? ''); const category = ref(props.filters.category ?? '');
const filtered = computed(() => Boolean(props.filters.search || props.filters.status || props.filters.category));
const columns = computed<Column[]>(() => [{ key: 'project', label: t('projects.fields.project') }, { key: 'status', label: t('projects.fields.status') }, { key: 'owner', label: t('projects.fields.owner'), hideable: true }, { key: 'client', label: t('projects.fields.client'), hideable: true }, { key: 'progress', label: t('projects.fields.progress'), hideable: true }, { key: 'due', label: t('projects.fields.dueDate'), hideable: true }, { key: 'actions', label: t('projects.common.actions'), srOnly: true, align: 'right' }]);
const statusOptions = computed(() => [{ value: '', label: t('projects.common.all') }, ...(['planned', 'active', 'on_hold', 'completed', 'cancelled'] as ProjectStatus[]).map((value) => ({ value, label: t(`projects.status.${value}`) }))]);
const categoryOptions = computed(() => [{ value: '', label: t('projects.common.all') }, ...props.categories.map((item) => ({ value: item.id, label: item.name }))]);
const formOptions = computed<ProjectFormOptions>(() => ({ categories: props.categories, templates: props.templates.map((item) => ({ id: item.id, name: item.name, categoryId: item.category_id ?? null })), contacts: props.contacts, companies: props.companies, owners: props.members, currency: props.currency }));
function apply(): void { router.get(route('projects.index'), { search: search.value || undefined, status: status.value || undefined, category: category.value || undefined }, { preserveState: true, replace: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } }); }
function clear(): void { search.value = ''; status.value = ''; category.value = ''; apply(); }
onMounted(() => { formOpen.value = props.openCreate === true || new URLSearchParams(window.location.search).has('create'); });
</script>
<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="t('projects.projects.title')" :description="t('projects.projects.description')"><template v-if="canCreate" #actions><Button variant="primary" @click="formOpen = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.projects.create') }}</Button></template></PageHeader>
    <ProjectsNav current="projects" />
    <Card v-if="projects.total === 0 && !filtered"><EmptyState :title="t('projects.projects.empty.title')" :description="canCreate ? t('projects.projects.empty.body') : t('projects.projects.empty.viewOnly')"><template #icon><BriefcaseBusiness /></template><Button v-if="canCreate" size="sm" variant="primary" @click="formOpen = true">{{ t('projects.projects.create') }}</Button></EmptyState></Card>
    <Card v-else flush :aria-busy="loading || undefined"><DataTable :columns="columns" :rows="projects.data" row-key="id" :caption="t('projects.projects.caption')" :loading="loading" storage-key="projects-list"><template #toolbar><form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply"><Input v-model="search" class="min-w-48 flex-1" type="search" :label="t('projects.projects.search')"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input><Select v-model="status" class="w-full sm:w-40" :options="statusOptions" :label="t('projects.fields.status')" /><Select v-model="category" class="w-full sm:w-44" :options="categoryOptions" :label="t('projects.fields.category')" /><Button type="submit" size="sm" :loading="loading">{{ t('projects.common.search') }}</Button><Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('projects.common.clearFilters') }}</Button></form></template><template #cell-project="{ row }"><div class="min-w-0"><Button size="sm" variant="ghost" class="max-w-64 justify-start truncate" :href="route('projects.show', { project: row.id })">{{ row.name }}</Button><p class="px-3 text-xs text-ink-subtle">{{ row.category?.name ?? t('projects.common.notSet') }}</p></div></template><template #cell-status="{ row }"><ProjectStatusBadge :value="row.status" /></template><template #cell-owner="{ row }"><span class="text-ink-muted">{{ row.owner?.name ?? t('projects.common.unassigned') }}</span></template><template #cell-client="{ row }"><span class="text-ink-muted">{{ row.company?.name ?? row.contact?.name ?? t('projects.common.notSet') }}</span></template><template #cell-progress="{ row }"><ProgressBar :value="row.progress" :label="t('projects.projects.progressLabel', { name: row.name })" compact /></template><template #cell-due="{ row }"><span class="tabular text-ink-muted">{{ row.dueOn ? format.date(row.dueOn) : t('projects.common.notSet') }}</span></template><template #cell-actions="{ row }"><Button size="sm" variant="ghost" :href="route('projects.show', { project: row.id })">{{ t('projects.common.view') }}</Button></template><template #empty><EmptyState :title="t('projects.common.noMatches')" :description="t('projects.common.noMatchesBody')"><template #icon><Search /></template><Button size="sm" @click="clear">{{ t('projects.common.clearFilters') }}</Button></EmptyState></template></DataTable><Pagination :meta="projects" /></Card>
    <ProjectFormDialog v-if="canCreate" v-model:open="formOpen" :options="formOptions" :can-manage-financials="canManageFinancials" />
</template>

