<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from 'lucide-vue-next';
import { DropdownMenuItem, DropdownMenuSeparator } from 'reka-ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Core/Components/Dropdown.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/**
 * The bell in the workspace top bar: the member's unread notification count and the latest few, from the
 * Communication app's summary endpoint (a JSON call, never a shared prop, so other pages pay nothing). Shown only to a
 * member who may view notifications; the server narrows every query to the signed-in membership.
 */
interface BellItem {
    id: string;
    severity: string;
    title: string;
    url: string | null;
    createdAt: string;
}

/** Asks again while the tab is visible; opening the menu and every page visit ask at once. */
const REFRESH_MS = 60_000;

const { t } = useI18n();
const format = useFormat();

const open = ref(false);
const unread = ref(0);
const items = ref<BellItem[]>([]);
const loaded = ref(false);
let request: AbortController | null = null;
let timer: ReturnType<typeof setInterval> | null = null;
let stopNavigate: (() => void) | undefined;

const badge = computed(() => (unread.value > 9 ? t('shell.notifications.manyUnread', { count: format.number(9) }) : format.number(unread.value)));
const label = computed(() =>
    unread.value > 0 ? t('shell.notifications.labelUnread', { count: format.number(unread.value) }) : t('shell.notifications.label'),
);

async function refresh(): Promise<void> {
    if (request !== null || document.hidden) return;

    const controller = new AbortController();
    request = controller;

    try {
        const response = await fetch(route('communication.notifications.summary'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });

        if (!response.ok) return;

        const body = (await response.json()) as { unread?: unknown; items?: unknown };

        if (typeof body.unread === 'number' && Array.isArray(body.items)) {
            unread.value = body.unread;
            items.value = body.items as BellItem[];
            loaded.value = true;
        }
    } catch {
        // Offline, or the request was stopped: the next refresh tries again.
    } finally {
        // A newer request may have replaced this one; only clear our own.
        if (request === controller) request = null;
    }
}

/** Drops a summary still on its way: it was read before a local change and would bring the old unread items back. */
function cancelRefresh(): void {
    request?.abort();
    request = null;
}

/** Marks one read, then follows its link (or stays, refreshing the count). */
function openItem(item: BellItem): void {
    cancelRefresh();
    unread.value = Math.max(0, unread.value - 1);
    items.value = items.value.filter((candidate) => candidate.id !== item.id);

    router.post(route('communication.notifications.read', { notification: item.id }), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (item.url) router.visit(item.url);
        },
    });
}

function markAllRead(): void {
    cancelRefresh();
    unread.value = 0;
    items.value = [];
    router.post(route('communication.notifications.read-all'), {}, { preserveScroll: true, preserveState: true });
}

function onVisibility(): void {
    if (!document.hidden) void refresh();
}

watch(open, (isOpen) => {
    if (isOpen) void refresh();
});

onMounted(() => {
    void refresh();
    timer = setInterval(() => void refresh(), REFRESH_MS);
    document.addEventListener('visibilitychange', onVisibility);
    stopNavigate = router.on('navigate', () => void refresh());
});

onBeforeUnmount(() => {
    if (timer !== null) clearInterval(timer);
    request?.abort();
    document.removeEventListener('visibilitychange', onVisibility);
    stopNavigate?.();
});

const itemClass =
    'flex w-full cursor-pointer items-start gap-2.5 rounded-control px-2.5 py-2 text-left outline-none select-none transition-colors data-[highlighted]:bg-hover';
</script>

<template>
    <Dropdown v-model:open="open" width="w-[min(22rem,calc(100vw-1.5rem))]">
        <template #trigger>
            <button
                type="button"
                class="relative flex size-9 shrink-0 items-center justify-center rounded-control text-ink-muted transition-colors hover:bg-hover hover:text-ink"
                :aria-label="label"
                :title="label"
                data-testid="notification-bell"
            >
                <Bell class="size-[1.125rem]" aria-hidden="true" />
                <span
                    v-if="unread > 0"
                    class="tabular absolute -top-0.5 -right-0.5 h-[1.125rem] min-w-[1.125rem] rounded-full bg-accent px-1 text-center text-[0.625rem] leading-[1.125rem] font-semibold text-accent-ink"
                    aria-hidden="true"
                    data-testid="notification-bell-count"
                >
                    {{ badge }}
                </span>
            </button>
        </template>

        <div class="flex items-center justify-between gap-2 px-2.5 pt-1.5 pb-2">
            <p class="text-sm font-semibold text-ink">{{ t('shell.notifications.title') }}</p>
            <span v-if="unread > 0" class="text-xs text-ink-subtle">{{ t('shell.notifications.unreadCount', { count: format.number(unread) }) }}</span>
        </div>

        <p v-if="loaded && items.length === 0" class="px-2.5 pb-3 text-sm text-ink-subtle" data-testid="notification-bell-empty">
            {{ t('shell.notifications.empty') }}
        </p>

        <DropdownMenuItem v-for="item in items" :key="item.id" :class="itemClass" data-testid="notification-bell-item" @select="openItem(item)">
            <span
                :class="['mt-1.5 size-2 shrink-0 rounded-full', item.severity === 'critical' ? 'bg-danger' : item.severity === 'warning' ? 'bg-warn' : 'bg-brand']"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1">
                <span class="line-clamp-2 text-sm text-ink">{{ item.title }}</span>
                <time class="tabular mt-0.5 block text-xs text-ink-subtle" :datetime="item.createdAt">{{ format.relative(item.createdAt) }}</time>
            </span>
        </DropdownMenuItem>

        <p v-if="unread > items.length" class="px-2.5 pt-1 text-xs text-ink-subtle">
            {{ t('shell.notifications.more', { count: format.number(unread - items.length) }) }}
        </p>

        <DropdownMenuSeparator class="my-1.5 h-px bg-line" />

        <DropdownMenuItem v-if="unread > 0" :class="[itemClass, 'items-center text-sm text-ink']" @select="markAllRead">
            <CheckCheck class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            {{ t('shell.notifications.markAllRead') }}
        </DropdownMenuItem>
        <DropdownMenuItem :class="[itemClass, 'items-center text-sm font-medium text-brand-text']" @select="router.visit(route('communication.notifications.index'))">
            {{ t('shell.notifications.viewAll') }}
        </DropdownMenuItem>
    </Dropdown>
</template>
