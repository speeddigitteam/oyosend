<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ListChecks, MessageCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
import CommentRow from '../../Components/comments/CommentRow.vue';
import CommentThread from '../../Components/comments/CommentThread.vue';
import type { AudienceComment, CommentCan, CommentThreadData, InboxStatus, Member } from '../../Components/comments/commentTypes';
import type { Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    comments: Paginated<AudienceComment>;
    filters: { status: InboxStatus; account_id: string | null; post_id: string | null; q: string | null };
    counts: Record<InboxStatus, number>;
    thread: CommentThreadData | null;
    accounts: { id: string; name: string; platform: string }[];
    posts: { id: string; title: string }[];
    members: Member[];
    can: CommentCan;
    limits: { reply: number; note: number; tag: number; bulk: number };
    hasSources: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const STATUSES: InboxStatus[] = ['open', 'unread', 'needs_attention', 'replied', 'hidden', 'mine', 'handled'];

const search = ref(props.filters.q ?? '');
const account = ref(props.filters.account_id ?? '');
const post = ref(props.filters.post_id ?? '');
const selected = ref<string[]>([]);
const bulkMember = ref('');
const bulkTag = ref('');

const accountOptions = computed(() => [{ value: '', label: t('socialComments.filters.allAccounts') }, ...props.accounts.map((item) => ({ value: item.id, label: item.name }))]);
const postOptions = computed(() => [{ value: '', label: t('socialComments.filters.allPosts') }, ...props.posts.map((item) => ({ value: item.id, label: item.title }))]);
const memberOptions = computed(() => [{ value: '', label: t('socialComments.bulk.chooseMember') }, ...props.members.map((item) => ({ value: item.id, label: item.name }))]);
const canSelect = true;
const allSelected = computed(() => props.comments.data.length > 0 && selected.value.length === props.comments.data.length);
const filtered = computed(() => account.value !== '' || post.value !== '' || search.value.trim() !== '');

function query(extra: Record<string, string | undefined> = {}): Record<string, string | undefined> {
    return {
        status: props.filters.status === 'open' ? undefined : props.filters.status,
        account_id: account.value || undefined,
        post_id: post.value || undefined,
        q: search.value.trim() || undefined,
        ...extra,
    };
}

function visit(extra: Record<string, string | undefined> = {}, keep = false): void {
    selected.value = [];
    router.get(route('social.comments.index'), query(extra), { preserveState: true, preserveScroll: keep, replace: true });
}

function setStatus(status: InboxStatus): void {
    visit({ status: status === 'open' ? undefined : status, comment: undefined });
}

function open(comment: AudienceComment): void {
    router.get(route('social.comments.index'), query({ comment: comment.id }), { preserveState: true, preserveScroll: true, replace: true });

    if (comment.unread) {
        router.post(route('social.comments.read', { comment: comment.id }), {}, { preserveScroll: true, preserveState: true, only: ['comments', 'counts'] });
    }
}

function toggleAll(): void {
    selected.value = allSelected.value ? [] : props.comments.data.map((item) => item.id);
}

function toggle(id: string, on: boolean): void {
    selected.value = on ? [...selected.value, id] : selected.value.filter((item) => item !== id);
}

function bulk(operation: string, extra: Record<string, string> = {}): void {
    router.post(route('social.comments.bulk'), { operation, ids: selected.value, ...extra }, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            bulkTag.value = '';
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('socialComments.title')" :description="t('socialComments.description')">
        <template #actions>
            <Button v-if="can.rules" :href="route('social.comments.rules.index')">
                <template #icon><ListChecks class="size-4" aria-hidden="true" /></template>
                {{ t('socialComments.rulesAndPages') }}
            </Button>
        </template>
    </PageHeader>

    <EmptyState v-if="!hasSources && comments.data.length === 0" :title="t('socialComments.empty.noPagesTitle')" :description="can.rules ? t('socialComments.empty.noPagesBody') : t('socialComments.empty.noPagesAsk')">
        <template #icon><MessageCircle /></template>
        <Button v-if="can.rules" variant="primary" :href="route('social.comments.rules.index')">{{ t('socialComments.empty.turnOn') }}</Button>
    </EmptyState>

    <template v-else>
        <nav class="mb-3 flex gap-1 overflow-x-auto rounded-control bg-hover p-1" :aria-label="t('socialComments.filters.status')">
            <button
                v-for="status in STATUSES"
                :key="status"
                type="button"
                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-control px-3 text-[0.8125rem] font-medium whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-brand"
                :class="filters.status === status ? 'bg-control text-ink' : 'text-ink-muted hover:text-ink'"
                :aria-pressed="filters.status === status"
                :data-testid="`status-${status}`"
                @click="setStatus(status)"
            >
                {{ t(`socialComments.status.${status}`) }}
                <span class="tabular text-xs text-ink-subtle">{{ format.number(counts[status] ?? 0) }}</span>
            </button>
        </nav>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <Card flush :class="thread ? 'hidden lg:block' : ''">
                <form class="grid gap-2 border-b border-line px-3 py-3 sm:grid-cols-2" @submit.prevent="visit({ comment: undefined })">
                    <Input v-model="search" type="search" :label="t('socialComments.filters.search')" maxlength="100" class="sm:col-span-2" />
                    <Select v-model="account" :options="accountOptions" :label="t('socialComments.filters.account')" />
                    <Select v-model="post" :options="postOptions" :label="t('socialComments.filters.post')" />
                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" size="sm">{{ t('social.common.apply') }}</Button>
                        <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="search = ''; account = ''; post = ''; visit({ comment: undefined })">
                            {{ t('social.common.clearFilters') }}
                        </Button>
                    </div>
                </form>

                <div v-if="comments.data.length > 0 && canSelect" class="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 text-[0.8125rem] text-ink-muted">
                        <input type="checkbox" class="size-4 accent-[var(--brand)]" :checked="allSelected" @change="toggleAll" />
                        {{ selected.length > 0 ? t('socialComments.bulk.selected', { count: format.number(selected.length) }) : t('socialComments.bulk.selectAll') }}
                    </label>
                    <template v-if="selected.length > 0">
                        <Button size="sm" @click="bulk('read')">{{ t('socialComments.bulk.read') }}</Button>
                        <Button v-if="can.reply" size="sm" @click="bulk('handled')">{{ t('socialComments.actions.markHandled') }}</Button>
                        <Button v-if="can.moderate" size="sm" @click="bulk('hide')">{{ t('socialComments.actions.hide') }}</Button>
                        <Button v-if="can.moderate" size="sm" @click="bulk('unhide')">{{ t('socialComments.actions.unhide') }}</Button>
                        <template v-if="can.moderate">
                            <Select v-model="bulkMember" :options="memberOptions" :label="t('socialComments.bulk.assign')" class="w-44" />
                            <Button size="sm" :disabled="bulkMember === ''" @click="bulk('assign', { membership_id: String(bulkMember) })">{{ t('socialComments.bulk.apply') }}</Button>
                            <Input v-model="bulkTag" :label="t('socialComments.bulk.tag')" :maxlength="limits.tag" class="w-36" />
                            <Button size="sm" :disabled="bulkTag.trim() === ''" @click="bulk('tag', { tag: bulkTag })">{{ t('socialComments.bulk.apply') }}</Button>
                        </template>
                    </template>
                </div>

                <ul v-if="comments.data.length > 0" :aria-label="t('socialComments.title')">
                    <CommentRow
                        v-for="comment in comments.data"
                        :key="comment.id"
                        :comment="comment"
                        :active="thread?.focus === comment.id"
                        :selectable="canSelect"
                        :selected="selected.includes(comment.id)"
                        @update:selected="toggle(comment.id, $event)"
                        @open="open(comment)"
                    />
                </ul>
                <EmptyState v-else compact :title="filtered ? t('social.common.noMatches') : t('socialComments.empty.listTitle')" :description="filtered ? t('social.common.noMatchesBody') : t('socialComments.empty.listBody')">
                    <template #icon><MessageCircle /></template>
                </EmptyState>
                <Pagination :meta="comments" />
            </Card>

            <CommentThread v-if="thread" :thread="thread" :can="can" :members="members" :limits="limits" @back="visit({ comment: undefined }, true)" />
            <Card v-else class="hidden lg:block">
                <EmptyState compact :title="t('socialComments.empty.pickTitle')" :description="t('socialComments.empty.pickBody')">
                    <template #icon><MessageCircle /></template>
                </EmptyState>
            </Card>
        </div>
    </template>
</template>
