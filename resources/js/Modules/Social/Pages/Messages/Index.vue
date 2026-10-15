<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Inbox } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import MessageCapturePanel from '../../Components/messages/MessageCapturePanel.vue';
import MessageThread from '../../Components/messages/MessageThread.vue';
import type {
    CapturePage,
    ConversationRow,
    ConversationThread,
    MessageFilters,
    MessagesCan,
    MessagingAvailability,
} from '../../Components/messages/messageTypes';
import type { Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

/**
 * Messenger and Instagram direct messages of connected Facebook Pages (ADR 0031): the conversations, one thread with
 * a composer, and the per-Page capture switch. Every state is honest: switched off by the operator, no Page
 * capturing, nothing received yet. Message text is shown as text only.
 */
const props = defineProps<{
    availability: MessagingAvailability;
    pages: CapturePage[];
    conversations: Paginated<ConversationRow>;
    filters: MessageFilters;
    thread: ConversationThread | null;
    members: { id: string; name: string }[];
    can: MessagesCan;
}>();

const { t } = useI18n();
const format = useFormat();

const search = ref(props.filters.q ?? '');
const platform = ref<string>(props.filters.platform ?? '');
const status = ref<string>(props.filters.status);

const capturing = computed(() => props.pages.some((page) => page.enabled));
const filtered = computed(() => platform.value !== '' || search.value.trim() !== '' || status.value !== 'open');
const platformOptions = computed(() => [
    { value: '', label: t('social.messages.filters.allPlatforms') },
    ...props.availability.platforms.map((value) => ({ value, label: t(`social.messages.platforms.${value}`) })),
]);
const statusOptions = computed(() => [
    { value: 'open', label: t('social.messages.status.open') },
    { value: 'closed', label: t('social.messages.status.closed') },
    { value: 'all', label: t('social.messages.filters.all') },
]);

function query(extra: Record<string, string | undefined> = {}): Record<string, string | undefined> {
    return {
        platform: platform.value || undefined,
        status: status.value === 'open' ? undefined : status.value,
        q: search.value.trim() || undefined,
        ...extra,
    };
}

function visit(extra: Record<string, string | undefined> = {}, keep = false): void {
    router.get(route('social.messages.index'), query(extra), { preserveState: true, preserveScroll: keep, replace: true });
}

function clearFilters(): void {
    search.value = '';
    platform.value = '';
    status.value = 'open';
    visit({ conversation: undefined });
}

function open(row: ConversationRow): void {
    router.get(route('social.messages.index'), query({ conversation: row.id }), { preserveState: true, preserveScroll: true, replace: true });
}

// Opening a conversation changes nothing on the server; reading it is posted once it is shown.
watch(
    () => props.thread?.id,
    (id) => {
        if (id && props.thread && props.thread.unread > 0) {
            router.post(route('social.messages.read', { conversation: id }), {}, { preserveScroll: true, preserveState: true, only: ['conversations', 'thread'] });
        }
    },
    { immediate: true },
);

function initialsOf(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    return parts.length === 0 ? '?' : parts.slice(0, 2).map((part) => Array.from(part)[0]).join('').toUpperCase();
}

function problemText(): string {
    switch (props.availability.problem) {
        case 'not_configured':
            return t('social.messages.empty.notConfiguredBody');
        case 'webhook_not_configured':
            return t('social.messages.empty.webhookBody');
        default:
            return t('social.messages.empty.offBody');
    }
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.messages.title')" :description="t('social.messages.description')" />

    <EmptyState v-if="availability.problem !== null" :title="t('social.messages.empty.offTitle')" :description="problemText()" data-testid="messages-off">
        <template #icon><Inbox /></template>
    </EmptyState>

    <template v-else>
        <EmptyState
            v-if="pages.length === 0"
            class="mb-4"
            :title="t('social.messages.empty.noFacebookTitle')"
            :description="t('social.messages.empty.noFacebookBody')"
            data-testid="messages-no-pages"
        >
            <template #icon><Inbox /></template>
            <Button v-if="can.manage" variant="primary" :href="route('social.accounts.index')">{{ t('social.messages.empty.connect') }}</Button>
        </EmptyState>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <Card flush :class="thread ? 'hidden lg:block' : ''">
                    <form class="grid gap-2 border-b border-line px-3 py-3 sm:grid-cols-2" @submit.prevent="visit({ conversation: undefined })">
                        <Input v-model="search" type="search" :label="t('social.messages.filters.search')" maxlength="100" class="sm:col-span-2" />
                        <Select v-model="platform" :options="platformOptions" :label="t('social.messages.filters.platform')" />
                        <Select v-model="status" :options="statusOptions" :label="t('social.messages.filters.status')" />
                        <div class="flex gap-2 sm:col-span-2">
                            <Button type="submit" size="sm">{{ t('social.common.apply') }}</Button>
                            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clearFilters">{{ t('social.common.clearFilters') }}</Button>
                        </div>
                    </form>

                    <ul v-if="conversations.data.length > 0" :aria-label="t('social.messages.title')">
                        <li v-for="row in conversations.data" :key="row.id" class="border-b border-line last:border-b-0" :class="thread?.id === row.id ? 'bg-hover' : ''">
                            <button
                                type="button"
                                class="flex w-full min-w-0 items-start gap-3 px-3 py-3 text-start focus-visible:outline-2 focus-visible:outline-brand"
                                :aria-current="thread?.id === row.id ? 'true' : undefined"
                                :data-testid="`conversation-${row.id}`"
                                @click="open(row)"
                            >
                                <Avatar :initials="initialsOf(row.title)" :name="row.title" class="mt-0.5 shrink-0" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-sm text-ink" :class="row.unread > 0 ? 'font-semibold' : 'font-medium'">{{ row.title }}</span>
                                        <span class="tabular shrink-0 text-xs text-ink-subtle">{{ format.relative(row.lastMessageAt) }}</span>
                                    </span>
                                    <span class="mt-0.5 line-clamp-2 break-words text-[0.8125rem] text-ink-muted">{{ row.preview ?? t('social.messages.list.attachment') }}</span>
                                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <Badge tone="info">{{ t(`social.messages.platforms.${row.platform}`) }}</Badge>
                                        <span class="truncate text-xs text-ink-subtle">{{ row.accountName }}</span>
                                        <Badge v-if="row.unread > 0" tone="brand" dot>{{ t('social.messages.list.unread', { count: format.number(row.unread) }) }}</Badge>
                                        <Badge v-if="row.status === 'closed'" tone="neutral">{{ t('social.messages.status.closed') }}</Badge>
                                        <span v-if="row.ownerName" class="truncate text-xs text-ink-subtle">{{ t('social.messages.list.assignedTo', { name: row.ownerName }) }}</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        compact
                        :title="filtered ? t('social.common.noMatches') : capturing ? t('social.messages.empty.listTitle') : t('social.messages.empty.noCaptureTitle')"
                        :description="filtered ? t('social.common.noMatchesBody') : capturing ? t('social.messages.empty.listBody') : can.manage ? t('social.messages.empty.noCaptureBody') : t('social.messages.empty.noCaptureAsk')"
                    >
                        <template #icon><Inbox /></template>
                    </EmptyState>
                    <Pagination :meta="conversations" />
                </Card>

                <MessageThread v-if="thread" :thread="thread" :can="can" :members="members" @back="visit({ conversation: undefined }, true)" />
                <Card v-else class="hidden lg:block">
                    <EmptyState compact :title="t('social.messages.empty.pickTitle')" :description="t('social.messages.empty.pickBody')">
                        <template #icon><Inbox /></template>
                    </EmptyState>
                </Card>
            </div>

            <MessageCapturePanel v-if="pages.length > 0" :pages="pages" :availability="availability" :can-manage="can.manage" />
        </div>
    </template>
</template>
