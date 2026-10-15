<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, Columns3, Inbox, List, MessageSquareOff, MessagesSquare, Search, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { useFullHeightPage } from '@/Core/composables/useFullHeightPage';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import {
    appendPage,
    createPoller,
    createReadTracker,
    type DayLabels,
    type InboxThreadView,
    LIST_POLL_INTERVAL,
    listTimeLabel,
    matchesMedia,
    mergeFirstPage,
    NARROW_QUERY,
    POLL_INTERVAL,
    postMarkRead,
    REALTIME_POLL_INTERVAL,
    threadKey,
} from '../../Components/inboxChat';
import InboxAvatar from '../../Components/InboxAvatar.vue';
import InboxChannelIcon from '../../Components/InboxChannelIcon.vue';
import InboxThreadPane from '../../Components/InboxThreadPane.vue';
import { type InboxRealtimeConfig, type InboxSignal, useInboxRealtime } from '../../Components/useInboxRealtime';

defineOptions({ layout: TenantLayout });

export type InboxState = 'unassigned' | 'active' | 'closed';
type StateFilter = 'open' | InboxState | 'all';
/** `chat`: the conversation opens beside the list and is answered here; otherwise `url` opens the owning app. */
export interface InboxRow { key: string; source: string; sourceLabel: string; channel: string; title: string; preview: string | null; at: string; state: InboxState; owner: string | null; unread: number; url: string; contactId: string | null; chat?: boolean }
/** `unread`: only conversations with something unread, applied by the server (every page, the refresh and the counts). */
type Filters = { view: 'list' | 'board'; source: string | null; owner: 'me' | 'unassigned' | null; state: StateFilter; search: string; unread?: boolean; open?: string | null };

const props = defineProps<{
    sources: Array<{ key: string; label: string; channel: string }>;
    filters: Filters;
    counts: Record<InboxState, number>;
    list: { items: InboxRow[]; next: string | null; after: string | null } | null;
    columns: Array<{ state: InboxState; total: number; items: InboxRow[] }> | null;
    thread?: InboxThreadView | null;
    /** ADR 0035: the websocket details while realtime is on; null keeps the page on polling alone. */
    realtime?: InboxRealtimeConfig | null;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const loading = ref(false);
const opening = ref<string | null>(null);
const olderLoading = ref(false);
const draft = reactive({ search: props.filters.search, source: props.filters.source ?? '', owner: props.filters.owner ?? '', state: props.filters.state, unread: props.filters.unread === true });
const STATE_TONES: Record<InboxState, BadgeTone> = { unassigned: 'warn', active: 'info', closed: 'neutral' };
const STATES: StateFilter[] = ['open', 'unassigned', 'active', 'closed', 'all'];

// The list view is a full-height chat screen whose panes scroll on their own; the board scrolls like any page.
const chatScreen = computed(() => props.filters.view === 'list' && props.sources.length > 0);
useFullHeightPage(chatScreen);

// ---- the list: its rows, "Load more" and the refresh ---------------------------------------------------------------

const rows = ref<InboxRow[]>(props.list?.items ?? []);
// The position for "Load more". A refresh merges the newest page in and keeps it, so loaded pages are never lost.
const next = ref<string | null>(props.list?.next ?? null);
// "Unread" is a server filter like the others: a conversation read here stays listed until the list is asked again.
const unreadOnly = computed(() => props.filters.unread === true);

/** Which filters a list belongs to; the open conversation is not one of them. */
const signature = (filters: Filters): string => JSON.stringify([filters.view, filters.source, filters.owner, filters.state, filters.search, filters.unread === true]);
let listSignature = signature(props.filters);
let generation = 0;

// Conversations read here: the badge stays cleared until the conversation has news, even if a refresh that left
// before the read was recorded still counts them.
const readAt = new Map<string, string>();
function withReads(list: InboxRow[]): InboxRow[] {
    return list.map((row) => {
        const at = readAt.get(row.key);

        if (at === undefined || row.unread === 0) return row;
        if (at !== row.at) {
            readAt.delete(row.key);

            return row;
        }

        return { ...row, unread: 0 };
    });
}

watch(() => props.list, (list) => {
    const current = signature(props.filters);

    if (list === null) {
        rows.value = [];
        next.value = null;
    } else if (list.after) {
        // "Load more": the next page goes below.
        rows.value = appendPage(rows.value, list.items);
        next.value = list.next;
    } else if (current === listSignature) {
        // The same filters again (after a reply, for instance): news merges in, loaded pages stay.
        rows.value = withReads(mergeFirstPage(rows.value, list.items));
    } else {
        rows.value = [...list.items];
        next.value = list.next;
    }

    listSignature = current;
    generation++;
});

const openThread = computed<InboxThreadView | null>(() => (props.filters.view === 'list' ? props.thread ?? null : null));
// A conversation named in the address. When the server sends no thread for it (gone, or no longer the member's: the
// two look alike) the pane says it is no longer available, and polling stops with the thread.
const requested = computed(() => props.filters.view === 'list' && Boolean(props.filters.open));
const unavailable = computed(() => requested.value && openThread.value === null);
const openKey = computed(() => opening.value ?? openThread.value?.key ?? null);
// As in WhatsApp, the reading pane is always beside the list on a wide screen, with a prompt until a conversation opens.
const chatLayout = computed(() => chatScreen.value);

// Reading the open conversation marks it read with an explicit POST (never as a side effect of loading the page): when
// it opens with an unread badge, and when a poll brings new inbound messages, only while the tab is visible. The badge
// clears at once; the server answers 204 and the list is not reloaded.
const readTracker = createReadTracker();

watch(openThread, (thread) => {
    const row = thread === null ? undefined : rows.value.find((candidate) => candidate.key === thread.key);

    if (!readTracker.observe(thread, row?.unread ?? null, document.hidden) || thread === null) {
        return;
    }

    rows.value = rows.value.map((candidate) => {
        if (candidate.key !== thread.key || candidate.unread === 0) return candidate;
        readAt.set(candidate.key, candidate.at);

        return { ...candidate, unread: 0 };
    });
    void postMarkRead(route('communication.inbox.read', { source: thread.source, id: thread.id }));
}, { immediate: true });

// ---- filters and visits ----------------------------------------------------------------------------------------------

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
const stateOptions = computed(() => STATES.map((value) => ({ value, label: t(`communication.inbox.states.${value}`) })));
const filtered = computed(() => props.filters.search !== '' || props.filters.source !== null || props.filters.owner !== null || props.filters.state !== 'open' || unreadOnly.value);
const sourceName = computed(() => props.sources.find((source) => source.key === props.filters.source)?.label ?? t('communication.inbox.anySource'));

/** The board's form: what is typed and chosen there. */
function query(view: 'list' | 'board' = props.filters.view): Record<string, string | undefined> {
    return {
        view: view === 'list' ? undefined : view,
        source: draft.source || undefined,
        owner: draft.owner || undefined,
        state: view === 'list' && draft.state !== 'open' ? draft.state : undefined,
        search: draft.search.trim() || undefined,
        unread: draft.unread ? '1' : undefined,
        // The open conversation stays open while the list is filtered; the board has no reading pane.
        open: view === 'list' ? openThread.value?.key : undefined,
    };
}

/** The list as it is shown now (what the server applied, not what is half typed), with changes. */
function listQuery(changes: Partial<{ source: string | null; owner: string | null; state: StateFilter; search: string; unread: boolean; open: string | null }> = {}): Record<string, string | undefined> {
    const merged = {
        source: props.filters.source,
        owner: props.filters.owner as string | null,
        state: props.filters.state,
        search: props.filters.search,
        unread: unreadOnly.value,
        open: openThread.value?.key ?? null,
        ...changes,
    };

    return {
        source: merged.source || undefined,
        owner: merged.owner || undefined,
        state: merged.state !== 'open' ? merged.state : undefined,
        search: merged.search.trim() || undefined,
        unread: merged.unread ? '1' : undefined,
        open: merged.open || undefined,
    };
}

/** The same filters for the refresh endpoint, without empty values. */
function pollParams(): Record<string, string> {
    const params = listQuery();
    delete params.open;

    return Object.fromEntries(Object.entries(params).filter((entry): entry is [string, string] => entry[1] !== undefined));
}

function visit(params: Record<string, string | undefined>): void {
    router.get(route('communication.inbox.index'), params, { preserveState: true, replace: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } });
}

function apply(): void { visit(query()); }
function clear(): void { draft.search = ''; draft.source = ''; draft.owner = ''; draft.state = 'open'; draft.unread = false; visit(query()); }
function switchView(view: 'list' | 'board'): void { visit(query(view)); }

function search(): void { visit(listQuery({ search: draft.search })); }
function clearSearch(): void {
    draft.search = '';

    if (props.filters.search !== '') visit(listQuery({ search: '' }));
}
function setOwner(owner: '' | 'me' | 'unassigned'): void {
    draft.owner = owner;
    visit(listQuery({ owner: owner || null, search: draft.search }));
}
function setState(state: StateFilter): void {
    draft.state = state;
    visit(listQuery({ state, search: draft.search }));
}
function setSource(source: string): void {
    draft.source = source;
    visit(listQuery({ source: source || null, search: draft.search }));
}
function setUnread(unread: boolean): void {
    draft.unread = unread;
    visit(listQuery({ unread, search: draft.search }));
}
/** "All": neither the unread nor an owner filter. */
function showAll(): void {
    if (!unreadOnly.value && props.filters.owner === null) return;
    draft.owner = '';
    draft.unread = false;
    visit(listQuery({ owner: null, unread: false, search: draft.search }));
}

const chipClass = (on: boolean): string[] => [
    'inline-flex h-7 shrink-0 items-center gap-1 rounded-full border px-2.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand',
    on ? 'border-transparent bg-brand-soft text-brand-text' : 'border-line-strong text-ink-muted hover:bg-hover hover:text-ink',
];

function loadMore(): void {
    if (!next.value) return;
    router.get(route('communication.inbox.index'), { ...listQuery(), after: next.value }, {
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

// ---- times in the list -------------------------------------------------------------------------------------------------

const now = ref(Date.now());
const labels = computed<DayLabels>(() => ({
    today: t('communication.inbox.chat.today'),
    yesterday: t('communication.inbox.chat.yesterday'),
    time: (value) => format.date(value, 'time'),
    weekday: (value) => format.date(value, 'weekday'),
    short: (value) => format.date(value, 'short'),
    date: (value) => format.date(value, 'date'),
}));
const listTime = (at: string): string => listTimeLabel(at, now.value, page.props.app.timezone, labels.value);

function rowMeta(row: InboxRow): string[] {
    return [t(`communication.inbox.states.${row.state}`), ...(row.owner ? [t('communication.inbox.assignedTo', { name: row.owner })] : [])];
}

// ---- the open conversation (ADR 0030) ----------------------------------------------------------------------------

let cancelPoll: (() => void) | null = null;
const poller = createPoller({
    interval: POLL_INTERVAL,
    tick: (done) => {
        if (openThread.value === null) {
            done();
            return;
        }

        router.reload({
            only: ['thread'],
            async: true,
            showProgress: false,
            onCancelToken: (token) => { cancelPoll = () => token.cancel(); },
            onFinish: () => { cancelPoll = null; done(); },
        });
    },
});

function stopPolling(): void {
    cancelPoll?.();
    cancelPoll = null;
}

// The list asks for its newest page every 20 seconds while the tab is visible, one request at a time, and merges it in.
let listRequest: AbortController | null = null;
const listPoller = createPoller({
    interval: LIST_POLL_INTERVAL,
    tick: (done) => { void refreshList().finally(done); },
});

async function refreshList(): Promise<void> {
    if (!chatScreen.value || loading.value) return;

    const asked = signature(props.filters);
    const startedAt = generation;
    listRequest = new AbortController();
    now.value = Date.now();

    try {
        const response = await fetch(route('communication.inbox.poll', pollParams()), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: listRequest.signal,
        });

        if (!response.ok) return;

        const body = (await response.json()) as { items?: unknown };

        // The filters changed, or the list was replaced, while this was on its way: its rows belong to another list.
        if (!Array.isArray(body.items) || asked !== signature(props.filters) || startedAt !== generation) return;

        rows.value = withReads(mergeFirstPage(rows.value, body.items as InboxRow[]));

        // News in the conversation on screen: fetch it now, so it is read while the member looks at it.
        const open = openThread.value;
        if (open?.marksRead && rows.value.some((row) => row.key === open.key && row.unread > 0)) poller.fire();
    } catch {
        // Offline, or stopped: the next tick tries again.
    } finally {
        listRequest = null;
    }
}

// Realtime (ADR 0035): a signal refreshes the list, and the open thread when it is the one that changed, at once. While
// subscribed, polling slows down to a safety net; without realtime, or when it drops, the normal pace comes back.
const live = useInboxRealtime({
    config: () => props.realtime,
    onChange: (signals: InboxSignal[]) => {
        listPoller.request();
        const open = openThread.value;

        if (open !== null && signals.some((signal) => threadKey(signal.source, signal.id) === open.key)) poller.request();
    },
    onResync: () => {
        listPoller.request();
        poller.request();
    },
});
watch(live.connected, (on) => {
    poller.retime(on ? REALTIME_POLL_INTERVAL : POLL_INTERVAL);
    listPoller.retime(on ? REALTIME_POLL_INTERVAL : LIST_POLL_INTERVAL);
});

function onVisibility(): void {
    if (!document.hidden) {
        poller.fire();
        listPoller.fire();
    }
}

// Escape closes the open conversation on a narrow screen, where it covers the list.
function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || event.defaultPrevented || !requested.value || !matchesMedia(NARROW_QUERY)) return;

    const active = document.activeElement;
    if (active instanceof Element && active.closest('[role="dialog"], [role="menu"], [role="listbox"]')) return;

    event.preventDefault();
    close();
}

let clock: ReturnType<typeof setInterval> | null = null;

watch(() => openThread.value?.key ?? null, (key) => { if (key === null) { poller.stop(); } else { poller.start(); } }, { immediate: true });
watch(chatScreen, (on) => { if (on) { listPoller.start(); } else { listPoller.stop(); listRequest?.abort(); } }, { immediate: true });
onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('keydown', onKeydown);
    clock = setInterval(() => { now.value = Date.now(); }, 60_000);
});
onBeforeUnmount(() => {
    poller.stop();
    stopPolling();
    listPoller.stop();
    listRequest?.abort();
    if (clock !== null) clearInterval(clock);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('keydown', onKeydown);
});

function focusRow(key: string | null): void {
    if (key === null) return;

    const row = Array.from(document.querySelectorAll<HTMLElement>('[data-row-key]')).find((element) => element.dataset.rowKey === key);
    row?.focus();
}

function open(row: InboxRow): void {
    stopPolling();
    opening.value = row.key;
    const board = props.filters.view === 'board';
    // From the board the conversation opens in the list, beside the other conversations.
    router.get(route('communication.inbox.index'), { ...(board ? query('list') : listQuery()), open: row.key }, {
        preserveState: true,
        preserveScroll: !board,
        only: board ? [] : ['thread', 'filters'],
        onFinish: () => { opening.value = null; },
    });
}

function close(): void {
    stopPolling();
    const key = openThread.value?.key ?? props.filters.open ?? null;
    router.get(route('communication.inbox.index'), { ...listQuery(), open: undefined }, {
        preserveState: true,
        preserveScroll: true,
        only: ['thread', 'filters'],
        // Back on the list (a narrow screen), the keyboard continues from the conversation that was open.
        onFinish: () => { void nextTick(() => focusRow(key)); },
    });
}

function older(limit: number): void {
    if (!openThread.value) return;
    stopPolling();
    olderLoading.value = true;
    router.get(route('communication.inbox.index'), { ...listQuery(), messages: String(limit) }, {
        preserveState: true,
        preserveScroll: true,
        only: ['thread'],
        onFinish: () => { olderLoading.value = false; },
    });
}

const rowClass = (row: InboxRow): string => [
    'flex w-full min-w-0 items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand sm:px-4',
    openKey.value === row.key ? 'bg-hover shadow-[inset_3px_0_0_var(--brand)]' : '',
].join(' ');
</script>

<template>
    <div :class="chatScreen ? 'flex min-h-0 flex-1 flex-col' : ''">
        <!-- On a narrow screen an open conversation takes the whole height. -->
        <div :class="chatScreen && requested ? 'hidden md:block' : ''">
            <PageHeader
                :eyebrow="t('communication.eyebrow')"
                :title="t('communication.inbox.title')"
                :description="chatScreen ? undefined : t('communication.inbox.description')"
                :compact="chatScreen"
            >
                <template v-if="sources.length" #actions>
                    <SegmentedControl :model-value="filters.view" :options="viewOptions" :label="t('communication.inbox.views.label')" icon-only @update:model-value="switchView" />
                </template>
            </PageHeader>
            <!-- On the chat screen the sections move into the rail on the card's left edge (md and up). -->
            <CommunicationNav current="inbox" :class="chatScreen ? 'md:hidden' : ''" />
        </div>

        <Card v-if="sources.length === 0">
            <EmptyState :title="t('communication.inbox.noSources')" :description="t('communication.inbox.noSourcesBody')"><template #icon><Inbox /></template></EmptyState>
        </Card>

        <!-- The chat screen: the conversations on the left, the open one on the right; each pane scrolls on its own. -->
        <div v-else-if="filters.view === 'list'" class="glass flex min-h-88 flex-1 overflow-hidden rounded-card">
            <CommunicationNav current="inbox" variant="rail" />
            <div
                :class="[
                    'min-h-0 w-full flex-col',
                    chatLayout ? 'md:w-90 md:shrink-0 md:border-r md:border-line lg:w-80 xl:w-96' : '',
                    requested ? 'hidden md:flex' : 'flex',
                ]"
                :aria-busy="loading || undefined"
            >
                <div class="grid gap-2.5 border-b border-line px-3 pt-3 pb-2.5 sm:px-4">
                    <form role="search" @submit.prevent="search">
                        <Input v-model="draft.search" type="search" maxlength="100" :aria-label="t('communication.inbox.search')" :placeholder="t('communication.inbox.search')">
                            <template #leading><Search class="size-4" aria-hidden="true" /></template>
                            <template v-if="draft.search !== ''" #trailing>
                                <Button variant="ghost" size="sm" icon @click="clearSearch">
                                    <template #icon><X class="size-4" aria-hidden="true" /></template>
                                    {{ t('communication.inbox.clearSearch') }}
                                </Button>
                            </template>
                        </Input>
                    </form>
                    <div role="group" :aria-label="t('communication.inbox.filters.label')" class="flex flex-wrap gap-1.5">
                        <button type="button" :class="chipClass(!unreadOnly && filters.owner === null)" :aria-pressed="!unreadOnly && filters.owner === null" data-chip="all" @click="showAll">{{ t('communication.inbox.filters.all') }}</button>
                        <button type="button" :class="chipClass(unreadOnly)" :aria-pressed="unreadOnly" data-chip="unread" @click="setUnread(!unreadOnly)">{{ t('communication.inbox.filters.unread') }}</button>
                        <button type="button" :class="chipClass(filters.owner === 'me')" :aria-pressed="filters.owner === 'me'" data-chip="mine" @click="setOwner(filters.owner === 'me' ? '' : 'me')">{{ t('communication.inbox.filters.mine') }}</button>
                        <button type="button" :class="chipClass(filters.owner === 'unassigned')" :aria-pressed="filters.owner === 'unassigned'" data-chip="unassigned" @click="setOwner(filters.owner === 'unassigned' ? '' : 'unassigned')">{{ t('communication.inbox.filters.unassigned') }}</button>
                        <Dropdown align="start" width="w-52">
                            <template #trigger>
                                <button type="button" :class="chipClass(filters.state !== 'open')" :aria-label="t('communication.inbox.filters.statusIs', { state: t(`communication.inbox.states.${filters.state}`) })" data-chip="state">
                                    {{ t(`communication.inbox.states.${filters.state}`) }}
                                    <ChevronDown class="size-3.5" aria-hidden="true" />
                                </button>
                            </template>
                            <DropdownItem v-for="option in stateOptions" :key="option.value" :active="filters.state === option.value" @select="setState(option.value)">
                                {{ option.label }}
                                <template v-if="filters.state === option.value" #trailing><Check class="size-4 text-brand-text" aria-hidden="true" /></template>
                            </DropdownItem>
                        </Dropdown>
                        <Dropdown v-if="sources.length > 1" align="start" width="w-56">
                            <template #trigger>
                                <button type="button" :class="chipClass(filters.source !== null)" :aria-label="t('communication.inbox.filters.channelIs', { name: sourceName })" data-chip="source">
                                    <span class="max-w-36 truncate">{{ sourceName }}</span>
                                    <ChevronDown class="size-3.5" aria-hidden="true" />
                                </button>
                            </template>
                            <DropdownItem v-for="option in sourceOptions" :key="option.value" :active="(filters.source ?? '') === option.value" @select="setSource(String(option.value))">
                                {{ option.label }}
                                <template v-if="(filters.source ?? '') === option.value" #trailing><Check class="size-4 text-brand-text" aria-hidden="true" /></template>
                            </DropdownItem>
                        </Dropdown>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <EmptyState v-if="rows.length === 0 && unreadOnly" :title="t('communication.inbox.unreadEmpty')" :description="t('communication.inbox.unreadEmptyBody')" compact>
                        <template #icon><Inbox /></template>
                        <Button size="sm" variant="secondary" @click="setUnread(false)">{{ t('communication.inbox.filters.all') }}</Button>
                    </EmptyState>
                    <EmptyState v-else-if="rows.length === 0" :title="t('communication.inbox.empty')" :description="t('communication.inbox.emptyBody')" compact><template #icon><Inbox /></template></EmptyState>
                    <ul v-else class="divide-y divide-line" :aria-label="t('communication.inbox.caption')">
                        <li v-for="row in rows" :key="row.key">
                            <component
                                :is="row.chat ? 'button' : Link"
                                v-bind="row.chat ? { type: 'button', 'aria-current': openKey === row.key ? 'true' : undefined } : { href: row.url }"
                                :class="rowClass(row)"
                                :data-chat="row.chat ? 'true' : undefined"
                                :data-row-key="row.key"
                                @click="row.chat ? open(row) : undefined"
                            >
                                <InboxAvatar :title="row.title" :seed="row.key" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex min-w-0 items-baseline gap-2">
                                        <span :class="['min-w-0 flex-1 truncate text-[0.9375rem] text-ink', row.unread > 0 ? 'font-semibold' : 'font-medium']">{{ row.title }}</span>
                                        <time :class="['tabular shrink-0 text-xs', row.unread > 0 ? 'font-semibold text-ink' : 'text-ink-subtle']" :datetime="row.at" :title="format.date(row.at, 'datetime')">{{ listTime(row.at) }}</time>
                                    </span>
                                    <span class="mt-0.5 flex min-w-0 items-center gap-1.5">
                                        <InboxChannelIcon :channel="row.channel" :source-label="row.sourceLabel" />
                                        <span :class="['min-w-0 flex-1 truncate text-[0.8125rem]', row.unread > 0 ? 'text-ink' : 'text-ink-muted']">{{ row.preview }}</span>
                                        <template v-if="row.unread > 0">
                                            <span class="tabular h-5 min-w-5 shrink-0 rounded-full bg-accent px-1.5 text-center text-[0.6875rem] leading-5 font-semibold text-accent-ink" aria-hidden="true">{{ format.number(row.unread) }}</span>
                                            <span class="sr-only">{{ t('communication.inbox.unread', { count: format.number(row.unread) }) }}</span>
                                        </template>
                                    </span>
                                    <span class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-ink-subtle">
                                        <template v-for="(part, index) in rowMeta(row)" :key="index">
                                            <span v-if="index > 0" class="size-0.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
                                            <span :class="index === 0 ? 'shrink-0' : 'truncate'">{{ part }}</span>
                                        </template>
                                    </span>
                                </span>
                            </component>
                        </li>
                    </ul>
                    <div v-if="next" class="flex justify-center border-t border-line p-3">
                        <Button size="sm" variant="secondary" :loading="loading" @click="loadMore">{{ t('communication.inbox.loadMore') }}</Button>
                    </div>
                </div>
            </div>

            <div v-if="chatLayout" :class="['min-w-0 flex-1 flex-col', requested ? 'flex' : 'hidden md:flex']" :aria-busy="opening !== null || undefined">
                <InboxThreadPane v-if="openThread" :thread="openThread" :older-loading="olderLoading" @close="close" @older="older" />
                <div v-else-if="unavailable" class="chat-wallpaper flex min-h-0 flex-1 items-center justify-center p-4" data-testid="inbox-thread-unavailable">
                    <div role="status">
                        <EmptyState :title="t('communication.inbox.chat.unavailable')" :description="t('communication.inbox.chat.unavailableBody')" compact>
                            <template #icon><MessageSquareOff /></template>
                            <Button size="sm" @click="close">{{ t('communication.inbox.chat.back') }}</Button>
                        </EmptyState>
                    </div>
                </div>
                <div v-else class="chat-wallpaper flex min-h-0 flex-1 items-center justify-center p-4" data-testid="inbox-thread-empty">
                    <EmptyState :title="t('communication.inbox.chat.pick')" :description="t('communication.inbox.chat.pickBody')" compact><template #icon><MessagesSquare /></template></EmptyState>
                </div>
            </div>
        </div>

        <template v-else>
            <div class="mb-3">
                <Card :aria-busy="loading || undefined">
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                        <Input v-model="draft.search" :label="t('communication.inbox.search')" class="min-w-48 flex-1" type="search" maxlength="100"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input>
                        <Select v-if="sources.length > 1" v-model="draft.source" :options="sourceOptions" :label="t('communication.inbox.source')" class="w-full sm:w-44" />
                        <Select v-model="draft.owner" :options="ownerOptions" :label="t('communication.inbox.owner')" class="w-full sm:w-36" />
                        <Checkbox v-model="draft.unread" :label="t('communication.inbox.unreadOnly')" class="pb-2" data-filter="unread" />
                        <Button type="submit" size="sm" :loading="loading">{{ t('communication.inbox.apply') }}</Button>
                        <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('communication.inbox.clear') }}</Button>
                    </form>
                </Card>
            </div>

            <div class="overflow-x-auto pb-2">
                <div role="region" class="grid min-w-3xl grid-cols-3 gap-3" :aria-label="t('communication.inbox.caption')">
                    <section v-for="column in columns ?? []" :key="column.state" class="glass-well min-w-0 rounded-card p-2" :aria-labelledby="`inbox-column-${column.state}`">
                        <header class="mb-2 flex items-center justify-between gap-2 px-1 py-1">
                            <h3 :id="`inbox-column-${column.state}`" class="text-sm font-semibold text-ink">{{ t(`communication.inbox.states.${column.state}`) }}</h3>
                            <span class="rounded-chip bg-hover px-2 font-mono text-xs text-ink-muted">{{ format.number(column.total) }}</span>
                        </header>
                        <div class="grid gap-2">
                            <component
                                :is="row.chat ? 'button' : Link"
                                v-for="row in column.items"
                                :key="row.key"
                                v-bind="row.chat ? { type: 'button' } : { href: row.url }"
                                class="glass block w-full rounded-control p-3 text-left transition hover:border-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                                :data-chat="row.chat ? 'true' : undefined"
                                @click="row.chat ? open(row) : undefined"
                            >
                                <span class="flex min-w-0 items-center justify-between gap-2">
                                    <span class="truncate text-sm font-semibold text-ink">{{ row.title }}</span>
                                    <Badge v-if="row.unread > 0" tone="brand" class="shrink-0">{{ t('communication.inbox.unread', { count: format.number(row.unread) }) }}</Badge>
                                </span>
                                <span v-if="row.preview" class="mt-1 block truncate text-xs text-ink-muted">{{ row.preview }}</span>
                                <span class="mt-2 flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                                    <InboxChannelIcon :channel="row.channel" :source-label="row.sourceLabel" />
                                    <Badge tone="neutral">{{ row.sourceLabel }}</Badge>
                                    <span class="tabular">{{ format.date(row.at, 'datetime') }}</span>
                                </span>
                                <span v-if="row.owner" class="mt-1 block truncate text-xs text-ink-subtle">{{ t('communication.inbox.assignedTo', { name: row.owner }) }}</span>
                            </component>
                            <p v-if="column.items.length === 0" class="rounded-control border border-dashed border-line px-3 py-5 text-center text-xs text-ink-subtle">{{ t('communication.inbox.columnEmpty') }}</p>
                            <p v-else-if="column.items.length < column.total" class="px-1 text-xs text-ink-subtle">{{ t('communication.inbox.columnShown', { shown: format.number(column.items.length), total: format.number(column.total) }) }}</p>
                        </div>
                    </section>
                </div>
            </div>
        </template>
    </div>
</template>
