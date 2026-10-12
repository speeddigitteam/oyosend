<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ListChecks, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
import BarMeter from '../../Components/BarMeter.vue';
import ChecklistStartDialog from '../../Components/ChecklistStartDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import type { ChecklistKind, ChecklistRow, Paginated, PersonOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    checklists: Paginated<ChecklistRow>;
    filters: { kind: string; status: string };
    templates: { id: string; name: string; kind: ChecklistKind; isDefault: boolean }[];
    employees: PersonOption[];
    kinds: ChecklistKind[];
    canStart: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const kind = ref(props.filters.kind);
const status = ref(props.filters.status);
const starting = ref(false);

const kindOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...props.kinds.map((value) => ({ value, label: t(`talent.status.kind.${value}`) })),
]);
const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...(['open', 'completed', 'cancelled'] as const).map((value) => ({ value, label: t(`talent.status.checklist.${value}`) })),
]);
const filtered = computed(() => kind.value !== 'all' || status.value !== 'open');

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('talent.fields.name') },
    { key: 'employee', label: t('talent.fields.employee') },
    { key: 'kind', label: t('talent.fields.kind'), hideable: true },
    { key: 'progress', label: t('talent.checklists.progress') },
    { key: 'anchor', label: t('talent.checklists.anchorDate'), hideable: true },
    { key: 'status', label: t('talent.fields.status') },
]);

function apply(): void {
    router.get(route('talent.checklists.index'), {
        kind: kind.value === 'all' ? undefined : kind.value,
        status: status.value === 'open' ? undefined : status.value,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    kind.value = 'all';
    status.value = 'open';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.checklists.title')" :description="t('talent.checklists.description')">
        <template v-if="canStart" #actions>
            <Button variant="primary" @click="starting = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.checklists.start') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="kind" :options="kindOptions" :label="t('talent.fields.kind')" class="w-full sm:w-44" />
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-44" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="checklists.data" row-key="id" :caption="t('talent.checklists.caption')" storage-key="talent-checklists">
            <template #cell-name="{ row }">
                <Link :href="route('talent.checklists.show', { checklist: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
            </template>
            <template #cell-employee="{ row }"><span class="text-ink-muted">{{ row.employee?.name ?? t('talent.common.dash') }}</span></template>
            <template #cell-kind="{ row }"><span class="text-ink-muted">{{ t(`talent.status.kind.${row.kind}`) }}</span></template>
            <template #cell-progress="{ row }">
                <BarMeter
                    :bp="row.taskCount === 0 ? 0 : Math.floor((row.doneCount * 10000) / row.taskCount)"
                    :label="t('talent.checklists.progressOf', { name: row.name })"
                    compact
                />
                <span class="tabular text-xs text-ink-subtle">{{ t('talent.checklists.doneOf', { done: format.number(row.doneCount), total: format.number(row.taskCount) }) }}</span>
            </template>
            <template #cell-anchor="{ row }"><span class="text-ink-muted">{{ format.date(row.anchorDate) }}</span></template>
            <template #cell-status="{ row }"><TalentBadge kind="checklist" :value="row.status" /></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.checklists.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.checklists.emptyBody')"
                    compact
                >
                    <template #icon><ListChecks /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="checklists" />
    </Card>

    <ChecklistStartDialog v-if="canStart" v-model:open="starting" :employees="employees" :templates="templates" :kinds="kinds" />
</template>
