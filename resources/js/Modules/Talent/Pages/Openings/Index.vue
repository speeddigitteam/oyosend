<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Briefcase, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import TalentBadge from '../../Components/TalentBadge.vue';
import type { NamedRef, OpeningRow, OpeningStatus, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    openings: Paginated<OpeningRow>;
    filters: { status: string; department_id: string; q: string };
    statuses: OpeningStatus[];
    departments: NamedRef[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status);
const department = ref(props.filters.department_id);
const search = ref(props.filters.q);

const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`talent.status.opening.${value}`) })),
]);
const departmentOptions = computed(() => [{ value: '', label: t('talent.common.all') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => status.value !== 'all' || department.value !== '' || search.value.trim() !== '');

const columns = computed<Column[]>(() => [
    { key: 'title', label: t('talent.fields.title') },
    { key: 'status', label: t('talent.fields.status') },
    { key: 'department', label: t('talent.fields.department'), hideable: true },
    { key: 'manager', label: t('talent.fields.hiringManager'), hideable: true },
    { key: 'hires', label: t('talent.openings.hires'), align: 'right' },
    { key: 'applications', label: t('talent.openings.applications'), align: 'right', hideable: true },
    { key: 'closesOn', label: t('talent.openings.closesOn'), hideable: true, hiddenByDefault: true },
]);

function apply(): void {
    router.get(route('talent.openings.index'), {
        status: status.value === 'all' ? undefined : status.value,
        department_id: department.value || undefined,
        q: search.value.trim() || undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'all';
    department.value = '';
    search.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.openings.title')" :description="t('talent.openings.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" :href="route('talent.openings.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.openings.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('talent.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-44" />
            <Select v-model="department" :options="departmentOptions" :label="t('talent.fields.department')" class="w-full sm:w-48" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="openings.data" row-key="id" :caption="t('talent.openings.caption')" storage-key="talent-openings">
            <template #cell-title="{ row }">
                <Link :href="route('talent.openings.show', { opening: row.id })" class="text-ink hover:text-brand-text">{{ row.title }}</Link>
                <span v-if="row.location" class="block text-xs text-ink-subtle">{{ row.location }}</span>
            </template>
            <template #cell-status="{ row }"><TalentBadge kind="opening" :value="row.status" /></template>
            <template #cell-department="{ row }"><span class="text-ink-muted">{{ row.department?.name ?? t('talent.common.dash') }}</span></template>
            <template #cell-manager="{ row }"><span class="text-ink-muted">{{ row.hiringManager?.name ?? t('talent.common.dash') }}</span></template>
            <template #cell-hires="{ row }">
                <span class="tabular text-ink">{{ t('talent.openings.hiresOf', { hired: format.number(row.hired), total: format.number(row.headcount) }) }}</span>
            </template>
            <template #cell-applications="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.applications) }}</span></template>
            <template #cell-closesOn="{ row }"><span class="text-ink-muted">{{ row.closesOn ? format.date(row.closesOn) : t('talent.common.dash') }}</span></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.openings.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.openings.emptyBody')"
                    compact
                >
                    <template #icon><Briefcase /></template>
                    <Button v-if="canCreate && !filtered" size="sm" variant="primary" :href="route('talent.openings.create')">{{ t('talent.openings.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="openings" />
    </Card>
</template>
