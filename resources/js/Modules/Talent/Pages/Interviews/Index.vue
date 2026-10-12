<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CalendarClock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
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
import { useTalentFormat } from '../../Components/talentFormat';
import type { InterviewRow, InterviewStatus, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    interviews: Paginated<InterviewRow>;
    filters: { status: string; from: string; to: string; mine: boolean };
    statuses: InterviewStatus[];
    timezone: string;
    today: string;
    hasPanelProfile: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const status = ref(props.filters.status);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const mine = ref(props.filters.mine);

const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`talent.status.interview.${value}`) })),
]);
const filtered = computed(() => status.value !== 'scheduled' || from.value !== '' || to.value !== '' || mine.value);

const columns = computed<Column[]>(() => [
    { key: 'when', label: t('talent.interviews.when') },
    { key: 'candidate', label: t('talent.fields.candidate') },
    { key: 'opening', label: t('talent.fields.opening'), hideable: true },
    { key: 'panel', label: t('talent.interviews.panel'), hideable: true },
    { key: 'mode', label: t('talent.fields.mode'), hideable: true, hiddenByDefault: true },
    { key: 'status', label: t('talent.fields.status') },
]);

function apply(): void {
    router.get(route('talent.interviews.index'), {
        status: status.value === 'scheduled' ? undefined : status.value,
        from: from.value || undefined,
        to: to.value || undefined,
        mine: mine.value ? 1 : undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'scheduled';
    from.value = '';
    to.value = '';
    mine.value = false;
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.interviews.title')" :description="t('talent.interviews.description', { timezone })" />

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-44" />
            <Input v-model="from" type="date" :label="t('talent.fields.from')" class="w-full sm:w-40" />
            <Input v-model="to" type="date" :label="t('talent.fields.to')" class="w-full sm:w-40" />
            <Checkbox v-if="hasPanelProfile" v-model="mine" :label="t('talent.interviews.mine')" class="pb-2" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="interviews.data" row-key="id" :caption="t('talent.interviews.caption')" storage-key="talent-interviews">
            <template #cell-when="{ row }">
                <Link :href="route('talent.interviews.show', { interview: row.id })" class="text-ink hover:text-brand-text">
                    <span class="tabular">{{ format.date(row.date) }} {{ talent.clock(row.time) }}</span>
                </Link>
                <span class="block text-xs text-ink-subtle">{{ row.roundName }} - {{ talent.duration(row.durationMinutes) }}</span>
            </template>
            <template #cell-candidate="{ row }">
                <span class="text-ink">{{ row.candidate?.name ?? t('talent.candidates.erased') }}</span>
            </template>
            <template #cell-opening="{ row }"><span class="text-ink-muted">{{ row.opening?.title }}</span></template>
            <template #cell-panel="{ row }">
                <span class="text-ink-muted">{{ row.panel.map((person) => person.name).join(', ') }}</span>
            </template>
            <template #cell-mode="{ row }"><span class="text-ink-muted">{{ t(`talent.status.mode.${row.mode}`) }}</span></template>
            <template #cell-status="{ row }"><TalentBadge kind="interview" :value="row.status" /></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.interviews.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.interviews.emptyBody')"
                    compact
                >
                    <template #icon><CalendarClock /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="interviews" />
    </Card>
</template>
