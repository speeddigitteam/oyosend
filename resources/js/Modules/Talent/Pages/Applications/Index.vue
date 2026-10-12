<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Kanban, List } from 'lucide-vue-next';
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
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ApplicationBoard from '../../Components/ApplicationBoard.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import type { ApplicationRow, ApplicationStatus, OpeningStatus, Paginated, Stage } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    applications: Paginated<ApplicationRow>;
    filters: { opening_id: string; stage_id: string; status: string; q: string };
    stages: Stage[];
    openings: { id: string; title: string; status: OpeningStatus }[];
    statuses: ApplicationStatus[];
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const STORAGE_KEY = 'oyo:talent:applications-view';

function storedView(): 'board' | 'list' {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'list' ? 'list' : 'board';
    } catch {
        return 'board';
    }
}

const view = ref<'board' | 'list'>(storedView());
const status = ref(props.filters.status);
const opening = ref(props.filters.opening_id);
const search = ref(props.filters.q);

const hasBoard = computed(() => props.stages.length > 0);
const showBoard = computed(() => hasBoard.value && view.value === 'board');
const filtered = computed(() => status.value !== 'open' || opening.value !== '' || search.value.trim() !== '');

const viewOptions = computed(() => [
    { value: 'board' as const, label: t('talent.applications.viewBoard'), icon: Kanban },
    { value: 'list' as const, label: t('talent.applications.viewList'), icon: List },
]);
const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`talent.status.application.${value}`) })),
]);
const openingOptions = computed(() => [{ value: '', label: t('talent.common.all') }, ...props.openings.map((item) => ({ value: item.id, label: item.title }))]);

const columns = computed<Column[]>(() => [
    { key: 'candidate', label: t('talent.fields.candidate') },
    { key: 'opening', label: t('talent.fields.opening') },
    { key: 'stage', label: t('talent.fields.stage') },
    { key: 'status', label: t('talent.fields.status') },
    { key: 'appliedOn', label: t('talent.applications.appliedLabel'), hideable: true },
]);

function remember(value: 'board' | 'list'): void {
    view.value = value;

    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // Not persisted; the choice still applies to this page view.
    }
}

function apply(): void {
    router.get(route('talent.applications.index'), {
        status: status.value === 'open' ? undefined : status.value,
        opening_id: opening.value || undefined,
        q: search.value.trim() || undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'open';
    opening.value = '';
    search.value = '';
    apply();
}

function move(application: ApplicationRow, stage: Stage): void {
    router.post(route('talent.applications.move', { application: application.id }), { stage_id: stage.id }, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.applications.title')" :description="t('talent.applications.description')">
        <template #actions>
            <SegmentedControl
                :model-value="view"
                :options="viewOptions"
                :label="t('talent.applications.viewLabel')"
                icon-only
                @update:model-value="remember"
            />
        </template>
    </PageHeader>

    <Card class="mb-3">
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('talent.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="opening" :options="openingOptions" :label="t('talent.fields.opening')" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-44" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>
        <p v-if="view === 'board' && !hasBoard" class="mt-3 text-xs text-ink-subtle" data-testid="board-hint">{{ t('talent.applications.boardNeedsOpening') }}</p>
    </Card>

    <template v-if="showBoard">
        <ApplicationBoard :applications="applications.data" :stages="stages" :can-move="can('talent.applications.manage')" @move="move" />
        <Pagination :meta="applications" class="mt-3" />
    </template>

    <Card v-else flush>
        <DataTable :columns="columns" :rows="applications.data" row-key="id" :caption="t('talent.applications.caption')" storage-key="talent-applications">
            <template #cell-candidate="{ row }">
                <Link :href="route('talent.applications.show', { application: row.id })" class="text-ink hover:text-brand-text">
                    {{ row.candidate.name ?? t('talent.candidates.erased') }}
                </Link>
                <span v-if="row.candidate.headline" class="block text-xs text-ink-subtle">{{ row.candidate.headline }}</span>
            </template>
            <template #cell-opening="{ row }"><span class="text-ink-muted">{{ row.opening.title }}</span></template>
            <template #cell-stage="{ row }"><span class="text-ink-muted">{{ row.stage.name }}</span></template>
            <template #cell-status="{ row }"><TalentBadge kind="application" :value="row.status" /></template>
            <template #cell-appliedOn="{ row }"><span class="text-ink-muted">{{ format.date(row.appliedOn) }}</span></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.applications.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.applications.emptyBody')"
                    compact
                />
            </template>
        </DataTable>
        <Pagination :meta="applications" />
    </Card>
</template>
