<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Clock, MessageCircle, Plus, Search } from 'lucide-vue-next';
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
import type { AgentOption, ChannelInfo, ContactMatch, ConversationSummary, Paginated, SendableTemplate } from '../../Components/types';
import { initialsOf } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

interface Filters {
    status: string;
    assignee: string | null;
    tag: string | null;
    unread: boolean;
    search: string;
}

const props = defineProps<{
    conversations: Paginated<ConversationSummary>;
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

    if (draft.status !== 'open') {
        out.status = draft.status;
    }

    if (draft.assignee !== '') {
        out.assignee = draft.assignee;
    }

    if (props.filters.tag) {
        out.tag = props.filters.tag;
    }

    if (draft.unread) {
        out.unread = '1';
    }

    if (draft.search.trim() !== '') {
        out.search = draft.search.trim();
    }

    return out;
});

const hasFilters = computed(() => Object.keys(query.value).length > 0);

function visit(data: Record<string, string>): void {
    router.get(route('whatsapp_marketing.inbox.index'), data, { preserveState: true, preserveScroll: true });
}

function apply(): void {
    visit(query.value);
}

watch(
    () => [draft.status, draft.assignee, draft.unread],
    () => apply(),
);

function clear(): void {
    Object.assign(draft, { search: '', status: 'open', assignee: '', unread: false });
    visit({});
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

    <Card class="mb-3">
        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="search" @submit.prevent="apply">
            <Input v-model="draft.search" class="sm:col-span-2" :label="t('whatsapp.inbox.search')" :placeholder="t('whatsapp.inbox.searchPlaceholder')" maxlength="100">
                <template #leading><Search class="size-4" aria-hidden="true" /></template>
            </Input>
            <Select v-model="draft.status" :options="statusOptions" :label="t('whatsapp.inbox.status')" />
            <Select v-model="draft.assignee" :options="assigneeOptions" :label="t('whatsapp.inbox.assignee')" />
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-4">
                <Checkbox v-model="draft.unread" :label="t('whatsapp.inbox.unreadOnly')" />
                <Button type="submit" variant="primary" size="sm">{{ t('whatsapp.common.apply') }}</Button>
                <Button v-if="hasFilters" size="sm" variant="ghost" @click="clear">{{ t('whatsapp.common.clear') }}</Button>
                <Badge v-if="filters.tag" tone="info">{{ t('whatsapp.inbox.tag', { tag: filters.tag }) }}</Badge>
            </div>
        </form>
    </Card>

    <Card flush>
        <EmptyState v-if="conversations.data.length === 0" :title="t(hasFilters ? 'whatsapp.inbox.emptyFiltered' : 'whatsapp.inbox.empty')" :description="hasFilters ? undefined : t('whatsapp.inbox.emptyBody')">
            <template #icon><MessageCircle /></template>
            <Button v-if="canStart && !hasFilters" variant="primary" @click="starting = true">{{ t('whatsapp.inbox.start') }}</Button>
        </EmptyState>
        <ul v-else class="divide-y divide-line" :aria-label="t('whatsapp.inbox.listLabel')">
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
        <Pagination :meta="conversations" />
    </Card>

    <StartConversationDialog v-if="canStart" v-model:open="starting" :templates="templates" :contact-matches="contactMatches" :query="query" />
</template>
