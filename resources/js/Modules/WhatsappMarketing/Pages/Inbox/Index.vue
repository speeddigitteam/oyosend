<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Clock, KanbanSquare, List, MessageCircle, Plus, Search } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChannelBanner from '../../Components/ChannelBanner.vue';
import StartConversationDialog from '../../Components/StartConversationDialog.vue';
import type { AgentOption, ChannelInfo, ContactMatch, ConversationSummary, KanbanColumn, Paginated, SendableTemplate } from '../../Components/types';
import { initialsOf } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

interface Filters {
    view: 'list' | 'kanban';
    status: string;
    assignee: string | null;
    tag: string | null;
    unread: boolean;
    search: string;
}

const props = defineProps<{
    conversations: Paginated<ConversationSummary> | null;
    kanbanColumns: KanbanColumn[];
    filters: Filters;
    counts: { open: number; mine: number; unassigned: number; unread: number };
    agents: AgentOption[];
    channel: ChannelInfo;
    canStart: boolean;
    contactMatches: ContactMatch[];
    templates: SendableTemplate[];
}>();

const { t } = useI18n();
const format = useFormat();

const draft = reactive({
    search: props.filters.search,
    status: props.filters.status,
    assignee: props.filters.assignee ?? '',
    unread: props.filters.unread,
    view: props.filters.view,
});
const starting = ref(false);

const statusOptions = computed(() => [
    { value: 'open', label: t('whatsapp.inbox.statusOpen') },
    { value: 'closed', label: t('whatsapp.inbox.statusClosed') },
    { value: 'all', label: t('whatsapp.inbox.statusAll') },
]);
const assigneeOptions = computed(() => [
    { value: '', label: t('whatsapp.inbox.anyone') },
    { value: 'me', label: t('whatsapp.inbox.me') },
    { value: 'unassigned', label: t('whatsapp.inbox.unassigned') },
    ...props.agents.map((agent) => ({ value: agent.id, label: agent.name })),
]);

const query = computed<Record<string, string>>(() => {
    const out: Record<string, string> = {};

    if (draft.view !== 'list') {
        out.view = draft.view;
    }

    if (draft.view === 'list') {
        if (draft.status !== 'open') {
            out.status = draft.status;
        }
        if (draft.assignee !== '') {
            out.assignee = draft.assignee;
        }
        if (draft.unread) {
            out.unread = '1';
        }
    }

    if (props.filters.tag) {
        out.tag = props.filters.tag;
    }

    if (draft.search.trim() !== '') {
        out.search = draft.search.trim();
    }

    return out;
});

const hasFilters = computed(() => Object.keys(query.value).filter((k) => k !== 'view').length > 0);

function visit(data: Record<string, string>): void {
    router.get(route('whatsapp_marketing.inbox.index'), data, { preserveState: true, preserveScroll: true });
}

function apply(): void {
    visit(query.value);
}

watch(
    () => [draft.status, draft.assignee, draft.unread, draft.view],
    () => apply(),
);

function setView(v: 'list' | 'kanban'): void {
    draft.view = v;
}

function clear(): void {
    const currentView = draft.view;
    Object.assign(draft, { search: '', status: 'open', assignee: '', unread: false });
    visit(currentView !== 'list' ? { view: currentView } : {});
}

const tiles = computed(() => [
    { key: 'open', value: props.counts.open, query: {} as Record<string, string> },
    { key: 'mine', value: props.counts.mine, query: { assignee: 'me' } },
    { key: 'unassigned', value: props.counts.unassigned, query: { assignee: 'unassigned' } },
    { key: 'unread', value: props.counts.unread, query: { unread: '1' } },
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.inbox.title')" :description="t('whatsapp.inbox.description')">
        <template v-if="canStart" #actions>
            <Button variant="primary" @click="starting = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.inbox.start') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="inbox" />
    <ChannelBanner :channel="channel" />

    <ul class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4" :aria-label="t('whatsapp.inbox.counts')">
        <li v-for="tile in tiles" :key="tile.key">
            <Link :href="route('whatsapp_marketing.inbox.index', tile.query)" class="glass block rounded-card px-5 py-4 transition-colors hover:bg-hover focus-visible:outline-2">
                <span class="eyebrow">{{ t(`whatsapp.inbox.tiles.${tile.key}`) }}</span>
                <span class="figure mt-2 block text-3xl" :class="tile.key === 'unread' && tile.value > 0 ? 'text-brand-text' : 'text-ink'">
                    {{ format.number(tile.value) }}
                </span>
            </Link>
        </li>
    </ul>

    <!-- View toggle -->
    <div class="mb-3 flex justify-end">
        <div class="flex gap-1 rounded-control bg-hover p-1" :aria-label="t('whatsapp.inbox.title')">
            <Button size="sm" :variant="draft.view === 'list' ? 'secondary' : 'ghost'" :aria-pressed="draft.view === 'list'" @click="setView('list')">
                <template #icon><List class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.inbox.viewList') }}
            </Button>
            <Button size="sm" :variant="draft.view === 'kanban' ? 'secondary' : 'ghost'" :aria-pressed="draft.view === 'kanban'" @click="setView('kanban')">
                <template #icon><KanbanSquare class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.inbox.viewBoard') }}
            </Button>
        </div>
    </div>

    <!-- Filter bar -->
    <Card class="mb-3">
        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="search" @submit.prevent="apply">
            <Input v-model="draft.search" :class="draft.view === 'kanban' ? 'sm:col-span-2 lg:col-span-4' : 'sm:col-span-2'" :label="t('whatsapp.inbox.search')" :placeholder="t('whatsapp.inbox.searchPlaceholder')" maxlength="100">
                <template #leading><Search class="size-4" aria-hidden="true" /></template>
            </Input>
            <template v-if="draft.view === 'list'">
                <Select v-model="draft.status" :options="statusOptions" :label="t('whatsapp.inbox.status')" />
                <Select v-model="draft.assignee" :options="assigneeOptions" :label="t('whatsapp.inbox.assignee')" />
            </template>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-4">
                <Checkbox v-if="draft.view === 'list'" v-model="draft.unread" :label="t('whatsapp.inbox.unreadOnly')" />
                <Button type="submit" variant="primary" size="sm">{{ t('whatsapp.common.apply') }}</Button>
                <Button v-if="hasFilters" size="sm" variant="ghost" @click="clear">{{ t('whatsapp.common.clear') }}</Button>
                <Badge v-if="filters.tag" tone="info">{{ t('whatsapp.inbox.tag', { tag: filters.tag }) }}</Badge>
            </div>
        </form>
    </Card>

    <!-- List view -->
    <Card v-if="draft.view === 'list'" flush>
        <EmptyState v-if="conversations && conversations.data.length === 0" :title="t(hasFilters ? 'whatsapp.inbox.emptyFiltered' : 'whatsapp.inbox.empty')" :description="hasFilters ? undefined : t('whatsapp.inbox.emptyBody')">
            <template #icon><MessageCircle /></template>
            <Button v-if="canStart && !hasFilters" variant="primary" @click="starting = true">{{ t('whatsapp.inbox.start') }}</Button>
        </EmptyState>
        <ul v-else-if="conversations" class="divide-y divide-line" :aria-label="t('whatsapp.inbox.listLabel')">
            <li v-for="conversation in conversations.data" :key="conversation.id">
                <Link
                    :href="route('whatsapp_marketing.inbox.show', { conversation: conversation.id })"
                    class="flex items-start gap-3 px-5 py-3.5 transition-colors hover:bg-hover focus-visible:outline-2"
                >
                    <Avatar :initials="initialsOf(conversation.displayName) || '#'" :name="conversation.phone" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <p class="truncate font-medium text-ink" :class="conversation.unread > 0 ? 'font-semibold' : ''">{{ conversation.displayName }}</p>
                            <time v-if="conversation.lastMessageAt" class="tabular shrink-0 text-xs text-ink-subtle">{{ format.relative(conversation.lastMessageAt) }}</time>
                        </div>
                        <p class="mt-0.5 truncate text-sm text-ink-muted">
                            <span v-if="conversation.lastDirection === 'outbound'" class="text-ink-subtle">{{ t('whatsapp.inbox.you') }} </span>{{ conversation.preview ?? t('whatsapp.inbox.noPreview') }}
                        </p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <Badge v-if="conversation.unread > 0" tone="brand">{{ t('whatsapp.inbox.unreadCount', { count: format.number(conversation.unread) }) }}</Badge>
                            <Badge v-if="conversation.status === 'closed'" tone="neutral">{{ t('whatsapp.conversationStatus.closed') }}</Badge>
                            <span v-if="conversation.owner" class="text-ink-muted">{{ conversation.owner.name }}</span>
                            <span v-else class="text-ink-subtle">{{ t('whatsapp.inbox.unassignedLabel') }}</span>
                            <span v-if="conversation.windowOpen" class="inline-flex items-center gap-1 text-ok">
                                <Clock class="size-3" aria-hidden="true" />{{ t('whatsapp.inbox.windowOpen') }}
                            </span>
                            <span v-for="tag in conversation.tags" :key="tag" class="text-ink-subtle wrap-anywhere">#{{ tag }}</span>
                        </div>
                    </div>
                </Link>
            </li>
        </ul>
        <Pagination v-if="conversations" :meta="conversations" />
    </Card>

    <!-- Kanban view -->
    <div v-else class="overflow-x-auto pb-4">
        <div class="flex gap-4" :style="{ minWidth: `${kanbanColumns.length * 288 + (kanbanColumns.length - 1) * 16}px` }">
            <div v-for="col in kanbanColumns" :key="col.key" class="w-72 shrink-0">
                <!-- Column header -->
                <div class="mb-2 flex items-center justify-between px-1">
                    <h3 class="text-sm font-semibold text-ink">{{ t(`whatsapp.inbox.col.${col.key}`) }}</h3>
                    <Badge tone="neutral" class="tabular-nums">{{ format.number(col.count) }}</Badge>
                </div>
                <!-- Cards -->
                <div class="flex flex-col gap-2">
                    <Link
                        v-for="conv in col.items"
                        :key="conv.id"
                        :href="route('whatsapp_marketing.inbox.show', { conversation: conv.id })"
                        class="glass block rounded-card px-3.5 py-3 transition-colors hover:bg-hover focus-visible:outline-2"
                    >
                        <div class="flex items-start gap-2.5">
                            <Avatar :initials="initialsOf(conv.displayName) || '#'" :name="conv.phone" class="shrink-0" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="truncate text-sm text-ink" :class="conv.unread > 0 ? 'font-semibold' : 'font-medium'">{{ conv.displayName }}</p>
                                    <time v-if="conv.lastMessageAt" class="tabular-nums shrink-0 text-xs text-ink-subtle">{{ format.relative(conv.lastMessageAt) }}</time>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-ink-muted">
                                    <span v-if="conv.lastDirection === 'outbound'" class="text-ink-subtle">{{ t('whatsapp.inbox.you') }} </span>{{ conv.preview ?? t('whatsapp.inbox.noPreview') }}
                                </p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-1 text-xs">
                                    <Badge v-if="conv.unread > 0" tone="brand">{{ t('whatsapp.inbox.unreadCount', { count: format.number(conv.unread) }) }}</Badge>
                                    <span v-if="conv.windowOpen" class="inline-flex items-center gap-0.5 text-ok">
                                        <Clock class="size-3" aria-hidden="true" />{{ t('whatsapp.inbox.windowOpen') }}
                                    </span>
                                    <span v-if="conv.owner" class="text-ink-muted">{{ conv.owner.name }}</span>
                                    <span v-else class="text-ink-subtle">{{ t('whatsapp.inbox.unassignedLabel') }}</span>
                                </div>
                            </div>
                        </div>
                    </Link>

                    <!-- Empty column -->
                    <p v-if="col.items.length === 0" class="rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted">
                        {{ t('whatsapp.inbox.emptyFiltered') }}
                    </p>

                    <!-- More indicator -->
                    <p v-if="col.count > col.items.length" class="px-1 text-xs text-ink-subtle">
                        {{ t('whatsapp.inbox.boardMore', { count: format.number(col.count - col.items.length) }) }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <StartConversationDialog v-if="canStart" v-model:open="starting" :templates="templates" :contact-matches="contactMatches" :query="query" />
</template>
