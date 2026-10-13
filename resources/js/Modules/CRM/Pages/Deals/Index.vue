<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BriefcaseBusiness, Columns3, List, Plus, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CrmStatusBadge from '../../Components/CrmStatusBadge.vue';
import DealBoard from '../../Components/DealBoard.vue';
import DealFormDialog from '../../Components/DealFormDialog.vue';
import type { DealListProps, DealStatus } from '../../Components/types';
import { usePrefillCreate } from '../../Components/usePrefillCreate';

defineOptions({ layout: TenantLayout });
const props = defineProps<DealListProps>();
const { t } = useI18n();
const format = useFormat();
const loading = ref(false); const { open: formOpen, initialContactId } = usePrefillCreate(props.prefillContact, props.canCreate);
const search = ref(props.filters.search); const status = ref(props.filters.status ?? ''); const pipeline = ref(props.filters.pipeline ?? ''); const stage = ref(props.filters.stage ?? ''); const segment = ref(props.filters.segment ?? '');
const view = ref<'list' | 'board'>(props.filters.view ?? 'list');
const viewOptions = computed(() => [
    { value: 'list' as const, label: t('crm.deals.listView'), icon: List },
    { value: 'board' as const, label: t('crm.deals.boardView'), icon: Columns3 },
]);
const selectedPipeline = computed(() => props.pipelines.find((item) => item.id === pipeline.value));
const statusOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...(['open', 'won', 'lost'] as DealStatus[]).map((value) => ({ value, label: t(`crm.status.${value}`) }))]);
const pipelineOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...props.pipelines.map((item) => ({ value: item.id, label: item.name }))]);
const stageOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...(selectedPipeline.value?.stages ?? props.pipelines.flatMap((item) => item.stages)).map((item) => ({ value: item.id, label: item.name }))]);
const segmentOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...props.segments.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => Boolean(props.filters.search || props.filters.status || props.filters.pipeline || props.filters.stage || props.filters.segment));
const count = computed(() => t(`crm.deals.count.${props.deals.total === 1 ? 'one' : 'other'}`, { count: format.number(props.deals.total) }));
const columns = computed<Column[]>(() => [
    { key: 'title', label: t('crm.fields.title') }, { key: 'status', label: t('crm.fields.status') }, { key: 'stage', label: t('crm.fields.stage') },
    { key: 'amount', label: t('crm.fields.amount'), align: 'right' }, { key: 'expected', label: t('crm.fields.expectedClose'), hideable: true },
    { key: 'owner', label: t('crm.fields.owner'), hideable: true, hiddenByDefault: true }, { key: 'actions', label: t('crm.common.actions'), srOnly: true, align: 'right' },
]);
function navigate(overrides: Record<string, string | undefined> = {}): void {
    router.get(route('crm.deals.index'), { search: search.value || undefined, status: status.value || undefined, pipeline: pipeline.value || undefined, stage: stage.value || undefined, segment: segment.value || undefined, view: view.value === 'list' ? undefined : view.value, ...overrides }, { preserveState: true, replace: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } });
}
function apply(): void { navigate(); }
function clear(): void { search.value = ''; status.value = ''; pipeline.value = ''; stage.value = ''; segment.value = ''; navigate(); }
function switchView(next: 'list' | 'board'): void { view.value = next; navigate({ view: next === 'list' ? undefined : next }); }
</script>

<template>
    <PageHeader :eyebrow="t('crm.eyebrow')" :title="t('crm.deals.title')" :description="t('crm.deals.description')">
        <template #actions>
            <SegmentedControl :model-value="view" :options="viewOptions" :label="t('crm.deals.viewLabel')" icon-only @update:model-value="switchView" />
            <Button v-if="canCreate" variant="primary" @click="formOpen = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('crm.deals.add') }}</Button>
        </template>
    </PageHeader>

    <template v-if="view === 'board'">
        <Card flush :aria-busy="loading || undefined">
            <div class="flex flex-wrap items-end gap-2 px-5 py-3">
                <Select v-model="pipeline" :options="pipelineOptions" :label="t('crm.fields.pipeline')" class="w-full sm:w-44" @change="navigate()" />
                <p v-if="boardDeals !== null" class="eyebrow tabular ml-auto" role="status">{{ t(`crm.deals.count.${boardDeals.length === 1 ? 'one' : 'other'}`, { count: format.number(boardDeals.length) }) }}</p>
            </div>
            <div class="px-5 pb-5">
                <DealBoard :pipelines="pipelines" :board-deals="boardDeals ?? []" :selected-pipeline-id="filters.pipeline" />
            </div>
        </Card>
    </template>

    <template v-else>
        <Card v-if="deals.total === 0 && !filtered"><EmptyState :title="t('crm.deals.empty.title')" :description="canCreate ? t('crm.deals.empty.body') : t('crm.deals.empty.viewOnly')"><template #icon><BriefcaseBusiness /></template><Button v-if="canCreate" size="sm" variant="primary" @click="formOpen = true">{{ t('crm.deals.add') }}</Button></EmptyState></Card>
        <Card v-else flush :aria-busy="loading || undefined">
            <DataTable :columns="columns" :rows="deals.data" row-key="id" :caption="t('crm.deals.caption')" :loading="loading" storage-key="crm-deals">
                <template #toolbar><form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply"><Input v-model="search" :label="t('crm.deals.search')" class="min-w-48 flex-1" type="search"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input><Select v-model="status" :options="statusOptions" :label="t('crm.fields.status')" class="w-full sm:w-36" /><Select v-model="pipeline" :options="pipelineOptions" :label="t('crm.fields.pipeline')" class="w-full sm:w-40" /><Select v-model="stage" :options="stageOptions" :label="t('crm.fields.stage')" class="w-full sm:w-40" /><Select v-if="segments.length" v-model="segment" :options="segmentOptions" :label="t('crm.settings.segments')" class="w-full sm:w-44" /><Button type="submit" size="sm" :loading="loading">{{ t('crm.common.search') }}</Button><Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('crm.common.clearFilters') }}</Button></form><p class="eyebrow tabular" role="status">{{ count }}</p></template>
                <template #cell-title="{ row }"><Button variant="ghost" size="sm" :href="route('crm.deals.show', { deal: row.id })" class="max-w-64 justify-start truncate">{{ row.title }}</Button></template>
                <template #cell-status="{ row }"><CrmStatusBadge :status="row.status" /></template><template #cell-stage="{ row }"><span class="text-ink-muted">{{ row.stage.name }}</span></template>
                <template #cell-amount="{ row }"><span class="tabular font-mono text-ink">{{ format.money(row.amount, row.currency) }}</span></template>
                <template #cell-expected="{ row }"><span class="tabular text-ink-muted">{{ row.expectedCloseDate ? format.date(row.expectedCloseDate) : t('crm.deals.noExpectedDate') }}</span></template>
                <template #cell-owner="{ row }"><span class="text-ink-muted">{{ row.owner?.name ?? t('crm.common.unassigned') }}</span></template><template #cell-actions="{ row }"><Button size="sm" variant="ghost" :href="route('crm.deals.show', { deal: row.id })">{{ t('crm.common.view') }}</Button></template>
                <template #empty><EmptyState :title="t('crm.common.noMatches')" :description="t('crm.common.noMatchesBody')"><template #icon><Search /></template><Button size="sm" @click="clear">{{ t('crm.common.clearFilters') }}</Button></EmptyState></template>
            </DataTable><Pagination :meta="deals" /><template v-if="!canCreate" #footer><p class="text-[0.8125rem] text-ink-subtle">{{ t('crm.common.viewOnly') }}</p></template>
        </Card>
    </template>

    <DealFormDialog v-if="canCreate" v-model:open="formOpen" :pipelines="pipelines" :leads="leads" :contacts="contacts" :companies="companies" :owners="owners" :initial-contact-id="initialContactId" />
</template>
