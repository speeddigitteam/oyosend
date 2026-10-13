<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Columns3, Inbox, List, Search } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CommunicationNav from '../../Components/CommunicationNav.vue';

defineOptions({ layout: TenantLayout });

export type InboxState = 'unassigned' | 'active' | 'closed';
type StateFilter = 'open' | InboxState | 'all';
export interface InboxRow { key: string; source: string; sourceLabel: string; channel: string; title: string; preview: string | null; at: string; state: InboxState; owner: string | null; unread: number; url: string; contactId: string | null }

const props = defineProps<{
    sources: Array<{ key: string; label: string; channel: string }>;
    filters: { view: 'list' | 'board'; source: string | null; owner: 'me' | 'unassigned' | null; state: StateFilter; search: string };
    counts: Record<InboxState, number>;
    list: { items: InboxRow[]; next: string | null; after: string | null } | null;
    columns: Array<{ state: InboxState; total: number; items: InboxRow[] }> | null;
}>();

const { t } = useI18n();
const format = useFormat();
const loading = ref(false);
const draft = reactive({ search: props.filters.search, source: props.filters.source ?? '', owner: props.filters.owner ?? '', state: props.filters.state });
const rows = ref<InboxRow[]>(props.list?.items ?? []);
const STATE_TONES: Record<InboxState, BadgeTone> = { unassigned: 'warn', active: 'info', closed: 'neutral' };

watch(() => props.list, (list) => {
    rows.value = list === null ? [] : list.after ? [...rows.value, ...list.items] : [...list.items];
});

const viewOptions = computed(() => [
    { value: 'list' as const, label: t('communication.inbox.views.list'), icon: List },
    { value: 'board' as const, label: t('communication.inbox.views.board'), icon: Columns3 },
]);
const sourceOptions = computed(() => [{ value: '', label: t('communication.inbox.anySource') }, ...props.sources.map((source) => ({ value: source.key, label: source.label }))]);
const ownerOptions = computed(() => [
    { value: '', label: t('communication.inbox.anyOwner') },
    { value: 'me', label: t('communication.inbox.mine') },
    { value: 'unassigned', label: t('communication.inbox.nobody') },
]);
const stateOptions = computed(() => (['open', 'unassigned', 'active', 'closed', 'all'] as StateFilter[]).map((value) => ({ value, label: t(`communication.inbox.states.${value}`) })));
const filtered = computed(() => props.filters.search !== '' || props.filters.source !== null || props.filters.owner !== null || props.filters.state !== 'open');

function query(view: 'list' | 'board' = props.filters.view): Record<string, string | undefined> {
    return {
        view: view === 'list' ? undefined : view,
        source: draft.source || undefined,
        owner: draft.owner || undefined,
        state: view === 'list' && draft.state !== 'open' ? draft.state : undefined,
        search: draft.search.trim() || undefined,
    };
}

function visit(params: Record<string, string | undefined>): void {
    router.get(route('communication.inbox.index'), params, { preserveState: true, replace: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } });
}

function apply(): void { visit(query()); }
function clear(): void { draft.search = ''; draft.source = ''; draft.owner = ''; draft.state = 'open'; visit(query()); }
function switchView(view: 'list' | 'board'): void { visit(query(view)); }

function loadMore(): void {
    if (!props.list?.next) return;
    router.get(route('communication.inbox.index'), { ...query(), after: props.list.next }, {
        preserveState: true,
        preserveScroll: true,
        only: ['list'],
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
        // The position belongs to this page only: a reload starts again from the newest.
        onSuccess: () => {
            const url = new URL(window.location.href);
            url.searchParams.delete('after');
            window.history.replaceState(window.history.state, '', url.toString());
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.inbox.title')" :description="t('communication.inbox.description')">
        <template v-if="sources.length" #actions>
            <SegmentedControl :model-value="filters.view" :options="viewOptions" :label="t('communication.inbox.views.label')" icon-only @update:model-value="switchView" />
        </template>
    </PageHeader>
    <CommunicationNav current="inbox" />

    <Card v-if="sources.length === 0">
        <EmptyState :title="t('communication.inbox.noSources')" :description="t('communication.inbox.noSourcesBody')"><template #icon><Inbox /></template></EmptyState>
    </Card>

    <template v-else>
        <Card class="mb-3" :aria-busy="loading || undefined">
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <Input v-model="draft.search" :label="t('communication.inbox.search')" class="min-w-48 flex-1" type="search" maxlength="100"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input>
                <Select v-if="sources.length > 1" v-model="draft.source" :options="sourceOptions" :label="t('communication.inbox.source')" class="w-full sm:w-44" />
                <Select v-model="draft.owner" :options="ownerOptions" :label="t('communication.inbox.owner')" class="w-full sm:w-36" />
                <Select v-if="filters.view === 'list'" v-model="draft.state" :options="stateOptions" :label="t('communication.inbox.state')" class="w-full sm:w-40" />
                <Button type="submit" size="sm" :loading="loading">{{ t('communication.inbox.apply') }}</Button>
                <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('communication.inbox.clear') }}</Button>
            </form>
        </Card>

        <Card v-if="filters.view === 'list'" flush :aria-busy="loading || undefined">
            <EmptyState v-if="rows.length === 0" :title="t('communication.inbox.empty')" :description="t('communication.inbox.emptyBody')" compact><template #icon><Inbox /></template></EmptyState>
            <ul v-else class="divide-y divide-line" :aria-label="t('communication.inbox.caption')">
                <li v-for="row in rows" :key="row.key">
                    <Link :href="row.url" class="flex min-w-0 items-start gap-3 px-5 py-3 transition hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand">
                        <span class="min-w-0 flex-1">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="truncate text-sm font-semibold text-ink">{{ row.title }}</span>
                                <Badge tone="neutral" class="shrink-0">{{ row.sourceLabel }}</Badge>
                            </span>
                            <span v-if="row.preview" class="mt-0.5 block truncate text-sm text-ink-muted">{{ row.preview }}</span>
                            <span class="mt-1 block text-xs text-ink-subtle">{{ row.owner ? t('communication.inbox.assignedTo', { name: row.owner }) : t('communication.inbox.unassignedOwner') }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <span class="tabular text-xs text-ink-subtle">{{ format.date(row.at, 'datetime') }}</span>
                            <Badge :tone="STATE_TONES[row.state]">{{ t(`communication.inbox.states.${row.state}`) }}</Badge>
                            <Badge v-if="row.unread > 0" tone="brand">{{ t('communication.inbox.unread', { count: format.number(row.unread) }) }}</Badge>
                        </span>
                    </Link>
                </li>
            </ul>
            <template v-if="list?.next" #footer>
                <Button size="sm" :loading="loading" @click="loadMore">{{ t('communication.inbox.loadMore') }}</Button>
            </template>
        </Card>

        <div v-else class="overflow-x-auto pb-2">
            <div role="region" class="grid min-w-3xl grid-cols-3 gap-3" :aria-label="t('communication.inbox.caption')">
                <section v-for="column in columns ?? []" :key="column.state" class="glass-well min-w-0 rounded-card p-2" :aria-labelledby="`inbox-column-${column.state}`">
                    <header class="mb-2 flex items-center justify-between gap-2 px-1 py-1">
                        <h3 :id="`inbox-column-${column.state}`" class="text-sm font-semibold text-ink">{{ t(`communication.inbox.states.${column.state}`) }}</h3>
                        <span class="rounded-chip bg-hover px-2 font-mono text-xs text-ink-muted">{{ format.number(column.total) }}</span>
                    </header>
                    <div class="grid gap-2">
                        <Link v-for="row in column.items" :key="row.key" :href="row.url" class="glass block rounded-control p-3 text-left transition hover:border-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-ink">{{ row.title }}</span>
                                <Badge v-if="row.unread > 0" tone="brand" class="shrink-0">{{ t('communication.inbox.unread', { count: format.number(row.unread) }) }}</Badge>
                            </span>
                            <span v-if="row.preview" class="mt-1 block truncate text-xs text-ink-muted">{{ row.preview }}</span>
                            <span class="mt-2 flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                                <Badge tone="neutral">{{ row.sourceLabel }}</Badge>
                                <span class="tabular">{{ format.date(row.at, 'datetime') }}</span>
                            </span>
                            <span v-if="row.owner" class="mt-1 block truncate text-xs text-ink-subtle">{{ t('communication.inbox.assignedTo', { name: row.owner }) }}</span>
                        </Link>
                        <p v-if="column.items.length === 0" class="rounded-control border border-dashed border-line px-3 py-5 text-center text-xs text-ink-subtle">{{ t('communication.inbox.columnEmpty') }}</p>
                        <p v-else-if="column.items.length < column.total" class="px-1 text-xs text-ink-subtle">{{ t('communication.inbox.columnShown', { shown: format.number(column.items.length), total: format.number(column.total) }) }}</p>
                    </div>
                </section>
            </div>
        </div>
    </template>
</template>
