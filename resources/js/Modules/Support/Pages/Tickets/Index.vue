<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LifeBuoy, Plus, Search } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
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
import type { Paginated } from '@/Core/types';
import SlaClock from '../../Components/SlaClock.vue';
import SupportNav from '../../Components/SupportNav.vue';
import TicketBadges from '../../Components/TicketBadges.vue';
import { primaryClock } from '../../Components/supportFormat';
import type { TicketFormOptions, TicketSummary } from '../../Components/types';

defineOptions({ layout: TenantLayout });

interface Filters {
    search: string;
    status: string;
    priority: string | null;
    queueId: string | null;
    categoryId: string | null;
    assignee: string | null;
    contactId: string | null;
    companyId: string | null;
    tag: string | null;
    sla: string | null;
}

const props = defineProps<{
    tickets: Paginated<TicketSummary>;
    filters: Filters;
    counts: { active: number; mine: number; unassigned: number; breached: number };
    options: TicketFormOptions;
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const now = Date.now();

const draft = reactive({
    search: props.filters.search,
    status: props.filters.status,
    priority: props.filters.priority ?? '',
    queue_id: props.filters.queueId ?? '',
    category_id: props.filters.categoryId ?? '',
    assignee: props.filters.assignee ?? '',
    sla: props.filters.sla ?? '',
    tag: props.filters.tag ?? '',
});

const statusOptions = computed(() => [
    { value: 'active', label: t('support.filters.active') },
    { value: 'all', label: t('support.filters.all') },
    ...props.options.statuses.map((value) => ({ value, label: t(`support.status.${value}`) })),
]);
const priorityOptions = computed(() => [
    { value: '', label: t('support.filters.anyPriority') },
    ...props.options.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) })),
]);
const queueOptions = computed(() => [
    { value: '', label: t('support.filters.anyQueue') },
    ...props.options.queues.map((queue) => ({ value: queue.id, label: queue.name })),
]);
const categoryOptions = computed(() => [
    { value: '', label: t('support.filters.anyCategory') },
    ...props.options.categories.map((category) => ({ value: category.id, label: category.name })),
]);
const assigneeOptions = computed(() => [
    { value: '', label: t('support.filters.anyone') },
    { value: 'me', label: t('support.filters.me') },
    { value: 'unassigned', label: t('support.filters.unassigned') },
    ...props.options.agents.map((agent) => ({ value: agent.id, label: agent.name })),
]);
const slaOptions = computed(() => [
    { value: '', label: t('support.filters.anySla') },
    { value: 'breached', label: t('support.sla.state.breached') },
    { value: 'paused', label: t('support.sla.state.paused') },
]);

const hasFilters = computed(
    () =>
        props.filters.search !== '' ||
        props.filters.status !== 'active' ||
        [props.filters.priority, props.filters.queueId, props.filters.categoryId, props.filters.assignee, props.filters.sla, props.filters.tag].some(
            (value) => value !== null && value !== '',
        ),
);

function visit(query: Record<string, string>): void {
    const clean = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== ''));

    router.get(route('support.tickets.index'), clean, { preserveState: true, preserveScroll: true });
}

function apply(): void {
    visit({ ...draft });
}

watch(
    () => [draft.status, draft.priority, draft.queue_id, draft.category_id, draft.assignee, draft.sla],
    () => apply(),
);

function clear(): void {
    Object.assign(draft, { search: '', status: 'active', priority: '', queue_id: '', category_id: '', assignee: '', sla: '', tag: '' });
    visit({});
}

const columns = computed<Column[]>(() => [
    { key: 'ticket', label: t('support.tickets.columns.ticket') },
    { key: 'requester', label: t('support.tickets.columns.requester') },
    { key: 'status', label: t('support.tickets.columns.status') },
    { key: 'priority', label: t('support.tickets.columns.priority'), hideable: true },
    { key: 'assignee', label: t('support.tickets.columns.assignee'), hideable: true },
    { key: 'sla', label: t('support.tickets.columns.sla'), hideable: true },
    { key: 'activity', label: t('support.tickets.columns.activity'), hideable: true },
]);

const tiles = computed(() => [
    { key: 'active', value: props.counts.active, query: {} as Record<string, string> },
    { key: 'mine', value: props.counts.mine, query: { assignee: 'me' } },
    { key: 'unassigned', value: props.counts.unassigned, query: { assignee: 'unassigned' } },
    { key: 'breached', value: props.counts.breached, query: { sla: 'breached' } },
]);
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.tickets.title')" :description="t('support.tickets.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" :href="route('support.tickets.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.tickets.new') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="tickets" />

    <ul class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4" :aria-label="t('support.tickets.counts')">
        <li v-for="tile in tiles" :key="tile.key">
            <Link
                :href="route('support.tickets.index', tile.query)"
                class="glass block rounded-card px-5 py-4 transition-colors hover:bg-hover focus-visible:outline-2"
            >
                <span class="eyebrow">{{ t(`support.tickets.tiles.${tile.key}`) }}</span>
                <span class="figure mt-2 block text-3xl" :class="tile.key === 'breached' && tile.value > 0 ? 'text-danger' : 'text-ink'">
                    {{ format.number(tile.value) }}
                </span>
            </Link>
        </li>
    </ul>

    <Card class="mb-3">
        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="search" @submit.prevent="apply">
            <Input v-model="draft.search" class="sm:col-span-2" :label="t('support.filters.search')" :placeholder="t('support.filters.searchPlaceholder')" maxlength="100">
                <template #leading><Search class="size-4" aria-hidden="true" /></template>
            </Input>
            <Select v-model="draft.status" :options="statusOptions" :label="t('support.filters.status')" />
            <Select v-model="draft.priority" :options="priorityOptions" :label="t('support.filters.priority')" />
            <Select v-model="draft.queue_id" :options="queueOptions" :label="t('support.filters.queue')" />
            <Select v-model="draft.category_id" :options="categoryOptions" :label="t('support.filters.category')" />
            <Select v-model="draft.assignee" :options="assigneeOptions" :label="t('support.filters.assignee')" />
            <Select v-model="draft.sla" :options="slaOptions" :label="t('support.filters.sla')" />
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <Button type="submit" variant="primary" size="sm">{{ t('support.filters.apply') }}</Button>
                <Button v-if="hasFilters" size="sm" variant="ghost" @click="clear">{{ t('support.filters.clear') }}</Button>
                <Badge v-if="filters.tag" tone="info">{{ t('support.filters.tag', { tag: filters.tag }) }}</Badge>
            </div>
        </form>
    </Card>

    <Card flush>
        <DataTable :columns="columns" :rows="tickets.data" row-key="id" :caption="t('support.tickets.caption')" storage-key="support-tickets">
            <template #cell-ticket="{ row }">
                <div class="min-w-0">
                    <Link :href="route('support.tickets.show', { ticket: row.id })" class="font-medium text-ink hover:underline">
                        <span class="tabular text-ink-muted">{{ row.number }}</span>
                        <span class="mx-1.5 text-ink-subtle" aria-hidden="true">/</span>
                        <span class="wrap-anywhere">{{ row.subject }}</span>
                    </Link>
                    <p v-if="row.queue || row.tags.length" class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-ink-subtle">
                        <span v-if="row.queue">{{ row.queue.name }}</span>
                        <span v-for="tag in row.tags" :key="tag">#{{ tag }}</span>
                    </p>
                </div>
            </template>
            <template #cell-requester="{ row }">
                <span class="text-ink-muted wrap-anywhere">{{ row.requester.name ?? row.requester.email ?? t('support.common.none') }}</span>
            </template>
            <template #cell-status="{ row }"><TicketBadges kind="status" :value="row.status" /></template>
            <template #cell-priority="{ row }"><TicketBadges kind="priority" :value="row.priority" /></template>
            <template #cell-assignee="{ row }">
                <span :class="row.assignee ? 'text-ink' : 'text-ink-subtle'">{{ row.assignee?.name ?? t('support.tickets.unassigned') }}</span>
            </template>
            <template #cell-sla="{ row }">
                <SlaClock v-bind="{ state: primaryClock(row, now).state, dueAt: primaryClock(row, now).dueAt, now }" />
            </template>
            <template #cell-activity="{ row }">
                <span class="tabular text-ink-muted">{{ format.relative(row.lastActivityAt ?? row.createdAt) }}</span>
            </template>
            <template #empty>
                <EmptyState :title="t(hasFilters ? 'support.tickets.emptyFiltered' : 'support.tickets.empty')" :description="hasFilters ? undefined : t('support.tickets.emptyBody')">
                    <template #icon><LifeBuoy /></template>
                    <Button v-if="canCreate && !hasFilters" variant="primary" :href="route('support.tickets.create')">{{ t('support.tickets.new') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="tickets" />
    </Card>
</template>
