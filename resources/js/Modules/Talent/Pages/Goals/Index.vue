<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Plus, Target } from 'lucide-vue-next';
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
import GoalDialog from '../../Components/GoalDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { Goal, GoalKind, NamedRef, Paginated, PersonOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    goals: Paginated<Goal>;
    filters: { employee_id: string; status: string; cycle_id: string; kind: string };
    form: { kinds: GoalKind[]; employees: PersonOption[]; cycles: NamedRef[]; ownEmployeeId: string | null };
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const status = ref(props.filters.status);
const kind = ref(props.filters.kind);
const cycle = ref(props.filters.cycle_id);
const creating = ref(false);

const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...(['active', 'achieved', 'missed', 'cancelled'] as const).map((value) => ({ value, label: t(`talent.status.goal.${value}`) })),
]);
const kindOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...props.form.kinds.map((value) => ({ value, label: t(`talent.status.goalKind.${value}`) })),
]);
const cycleOptions = computed(() => [{ value: '', label: t('talent.common.all') }, ...props.form.cycles.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => status.value !== 'active' || kind.value !== 'all' || cycle.value !== '');

const columns = computed<Column[]>(() => [
    { key: 'title', label: t('talent.fields.title') },
    { key: 'employee', label: t('talent.fields.employee'), hideable: true },
    { key: 'progress', label: t('talent.goals.progress') },
    { key: 'dueOn', label: t('talent.fields.dueOn'), hideable: true },
    { key: 'status', label: t('talent.fields.status') },
]);

function apply(): void {
    router.get(route('talent.goals.index'), {
        status: status.value === 'active' ? undefined : status.value,
        kind: kind.value === 'all' ? undefined : kind.value,
        cycle_id: cycle.value || undefined,
        employee_id: props.filters.employee_id || undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'active';
    kind.value = 'all';
    cycle.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.goals.title')" :description="t('talent.goals.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" @click="creating = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.goals.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-44" />
            <Select v-model="kind" :options="kindOptions" :label="t('talent.fields.type')" class="w-full sm:w-40" />
            <Select v-model="cycle" :options="cycleOptions" :label="t('talent.fields.cycle')" class="w-full sm:w-52" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="goals.data" row-key="id" :caption="t('talent.goals.caption')" storage-key="talent-goals">
            <template #cell-title="{ row }">
                <Link :href="route('talent.goals.show', { goal: row.id })" class="text-ink hover:text-brand-text">{{ row.title }}</Link>
                <span class="block text-xs text-ink-subtle">{{ t(`talent.status.goalKind.${row.kind}`) }}</span>
            </template>
            <template #cell-employee="{ row }"><span class="text-ink-muted">{{ row.employee?.name ?? t('talent.common.dash') }}</span></template>
            <template #cell-progress="{ row }">
                <BarMeter :bp="row.progressBp" :label="t('talent.goals.progressOf', { title: row.title })" compact />
                <span class="tabular text-xs text-ink-subtle">
                    {{ talent.value(row.currentValue, row.unit) }} / {{ talent.value(row.targetValue, row.unit) }}
                </span>
            </template>
            <template #cell-dueOn="{ row }"><span class="text-ink-muted">{{ row.dueOn ? format.date(row.dueOn) : t('talent.common.dash') }}</span></template>
            <template #cell-status="{ row }"><TalentBadge kind="goal" :value="row.status" /></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.goals.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.goals.emptyBody')"
                    compact
                >
                    <template #icon><Target /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="goals" />
    </Card>

    <GoalDialog
        v-if="canCreate"
        v-model:open="creating"
        :goal="null"
        :kinds="form.kinds"
        :employees="form.employees"
        :cycles="form.cycles"
        :own-employee-id="form.ownEmployeeId"
    />
</template>
