<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Star, UserRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { CycleStatus, Paginated, ReviewRow, ReviewStatus } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    reviews: Paginated<ReviewRow>;
    filters: { cycle_id: string; status: string; mine: boolean };
    cycles: { id: string; name: string; status: CycleStatus }[];
    statuses: ReviewStatus[];
    hasProfile: boolean;
}>();

const { t } = useI18n();
const talent = useTalentFormat();

const cycle = ref(props.filters.cycle_id);
const status = ref(props.filters.status);
const mine = ref(props.filters.mine);

const cycleOptions = computed(() => [{ value: '', label: t('talent.common.all') }, ...props.cycles.map((item) => ({ value: item.id, label: item.name }))]);
const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`talent.status.review.${value}`) })),
]);
const filtered = computed(() => cycle.value !== '' || status.value !== 'all' || mine.value);

const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('talent.fields.employee') },
    { key: 'cycle', label: t('talent.fields.cycle'), hideable: true },
    { key: 'reviewer', label: t('talent.fields.reviewer'), hideable: true },
    { key: 'self', label: t('talent.reviews.selfScore'), align: 'right', hideable: true },
    { key: 'manager', label: t('talent.reviews.managerScore'), align: 'right', hideable: true },
    { key: 'final', label: t('talent.reviews.finalScore'), align: 'right' },
    { key: 'status', label: t('talent.fields.status') },
]);

function apply(): void {
    router.get(route('talent.reviews.index'), {
        cycle_id: cycle.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
        mine: mine.value ? 1 : undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    cycle.value = '';
    status.value = 'all';
    mine.value = false;
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.reviews.title')" :description="t('talent.reviews.description')" />

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="cycle" :options="cycleOptions" :label="t('talent.fields.cycle')" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-52" />
            <Checkbox v-if="hasProfile" v-model="mine" :label="t('talent.reviews.mine')" class="pb-2" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="reviews.data" row-key="id" :caption="t('talent.reviews.caption')" storage-key="talent-reviews">
            <template #cell-employee="{ row }">
                <Link :href="route('talent.reviews.show', { review: row.id })" class="text-ink hover:text-brand-text">
                    {{ row.employee?.name ?? t('talent.common.dash') }}
                </Link>
            </template>
            <template #cell-cycle="{ row }"><span class="text-ink-muted">{{ row.cycle?.name }}</span></template>
            <template #cell-reviewer="{ row }"><span class="text-ink-muted">{{ row.reviewer?.name ?? t('talent.common.dash') }}</span></template>
            <template #cell-self="{ row }"><span class="tabular text-ink-muted">{{ talent.score(row.selfScore) }}</span></template>
            <template #cell-manager="{ row }"><span class="tabular text-ink-muted">{{ talent.score(row.managerScore) }}</span></template>
            <template #cell-final="{ row }">
                <span class="tabular text-ink">{{ talent.score(row.finalScore) }}</span>
                <span v-if="row.finalOverridden" class="block text-xs text-ink-subtle">{{ t('talent.reviews.overridden') }}</span>
            </template>
            <template #cell-status="{ row }"><TalentBadge kind="review" :value="row.status" /></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.reviews.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.reviews.emptyBody')"
                    compact
                >
                    <template #icon><Star v-if="hasProfile" /><UserRound v-else /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="reviews" />
    </Card>
</template>
