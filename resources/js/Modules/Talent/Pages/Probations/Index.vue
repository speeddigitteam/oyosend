<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Hourglass } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { Paginated, ProbationRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    probations: Paginated<ProbationRow>;
    filters: { status: string };
    today: string;
    reminderDays: number;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const status = ref(props.filters.status);

const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...(['in_progress', 'confirmed', 'ended'] as const).map((value) => ({ value, label: t(`talent.status.probation.${value}`) })),
]);

const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('talent.fields.employee') },
    { key: 'startedOn', label: t('talent.probations.started'), hideable: true },
    { key: 'endsOn', label: t('talent.probations.ends') },
    { key: 'daysLeft', label: t('talent.probations.daysLeft'), align: 'right' },
    { key: 'status', label: t('talent.fields.status') },
]);

watch(status, () => apply());

function apply(): void {
    router.get(route('talent.probations.index'), { status: status.value === 'in_progress' ? undefined : status.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('talent.eyebrow')"
        :title="t('talent.probations.title')"
        :description="t('talent.probations.description', { days: format.number(reminderDays) })"
    />

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-48" />
        </form>

        <DataTable :columns="columns" :rows="probations.data" row-key="id" :caption="t('talent.probations.caption')" storage-key="talent-probations">
            <template #cell-employee="{ row }">
                <Link :href="route('talent.probations.show', { probation: row.id })" class="text-ink hover:text-brand-text">
                    {{ row.employee?.name ?? t('talent.common.dash') }}
                </Link>
                <span v-if="row.employee" class="tabular block text-xs text-ink-subtle">{{ row.employee.number }}</span>
            </template>
            <template #cell-startedOn="{ row }"><span class="text-ink-muted">{{ format.date(row.startedOn) }}</span></template>
            <template #cell-endsOn="{ row }">
                <span class="text-ink">{{ format.date(row.endsOn) }}</span>
                <Badge v-if="row.isOverdue" tone="danger" class="ml-2">{{ t('talent.probations.overdue') }}</Badge>
            </template>
            <template #cell-daysLeft="{ row }">
                <span v-if="row.status === 'in_progress' && row.daysLeft !== null" class="tabular" :class="row.daysLeft < 0 ? 'text-danger' : row.daysLeft <= reminderDays ? 'text-warn' : 'text-ink'">
                    {{ talent.days(Math.abs(row.daysLeft)) }}
                </span>
                <span v-else class="text-ink-subtle">{{ t('talent.common.dash') }}</span>
            </template>
            <template #cell-status="{ row }"><TalentBadge kind="probation" :value="row.status" /></template>
            <template #empty>
                <EmptyState :title="t('talent.probations.empty')" :description="t('talent.probations.emptyBody')" compact>
                    <template #icon><Hourglass /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="probations" />
    </Card>
</template>
