<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Link2, RefreshCw, Unplug } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ReadinessChecklist from '../../Components/ReadinessChecklist.vue';
import type { AdAccountRow, ConnectionRow, ReadinessItem, SyncState } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    readiness: ReadinessItem[];
    connections: ConnectionRow[];
    accounts: AdAccountRow[];
    limit: { limit: number | null; used: number };
    appConfigured: boolean;
    requestsManagement: boolean;
    canManage: boolean;
    redirectUri: string | null;
    socialPages: { available: boolean; pages: { id: string; name: string; status: string }[] };
}>();

const { t } = useI18n();
const format = useFormat();

const connecting = ref(false);
const busy = ref<string | null>(null);
const disconnecting = ref<ConnectionRow | null>(null);
const disconnectOpen = ref(false);

const seatsFull = computed(() => props.limit.limit !== null && props.limit.used >= props.limit.limit);
const hasUsable = computed(() => props.connections.some((connection) => connection.status === 'connected'));
const needsAuth = computed(() => props.connections.some((connection) => connection.status === 'needs_reauthorization' || connection.expired));

const SYNC_TONES: Record<SyncState, BadgeTone> = { ok: 'ok', partial: 'info', throttled: 'warn', error: 'danger' };

function connect(): void {
    connecting.value = true;
    router.post(route('meta_ads.accounts.connect'), {}, { onFinish: () => (connecting.value = false) });
}

function act(kind: 'track' | 'untrack' | 'sync', account: AdAccountRow): void {
    busy.value = `${kind}-${account.id}`;
    const options = { preserveScroll: true, onFinish: () => (busy.value = null) };

    if (kind === 'track') {
        router.post(route('meta_ads.accounts.track', { account: account.id }), {}, options);
    } else if (kind === 'untrack') {
        router.delete(route('meta_ads.accounts.untrack', { account: account.id }), options);
    } else {
        router.post(route('meta_ads.accounts.sync', { account: account.id }), {}, options);
    }
}

function askDisconnect(connection: ConnectionRow): void {
    disconnecting.value = connection;
    disconnectOpen.value = true;
}

function disconnect(): void {
    if (!disconnecting.value) {
        return;
    }

    busy.value = 'disconnect';
    router.delete(route('meta_ads.accounts.destroy', { connection: disconnecting.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
            disconnectOpen.value = false;
        },
    });
}

function connectionTone(connection: ConnectionRow): BadgeTone {
    return connection.status === 'connected' && !connection.expired ? 'ok' : 'warn';
}

function connectionLabel(connection: ConnectionRow): string {
    return connection.expired && connection.status === 'connected' ? t('metaAds.accounts.expired') : t(`metaAds.accounts.connection.${connection.status}`);
}
</script>

<template>
    <PageHeader :eyebrow="t('metaAds.eyebrow')" :title="t('metaAds.accounts.title')" :description="t('metaAds.accounts.description')" />

    <p v-if="needsAuth" class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="attention">
        {{ t('metaAds.accounts.attention') }}
    </p>

    <Card :title="t('metaAds.readiness.title')" :description="t('metaAds.readiness.description')">
        <ReadinessChecklist :items="readiness" />
    </Card>

    <Card class="mt-3" :title="t('metaAds.accounts.authorisation')" :description="t('metaAds.accounts.authorisationHint')" flush>
        <template v-if="canManage" #actions>
            <Button size="sm" variant="primary" :loading="connecting" :disabled="!appConfigured" @click="connect">
                <template #icon><Link2 class="size-3.5" aria-hidden="true" /></template>
                {{ connections.length > 0 ? t('metaAds.accounts.connectAnother') : t('metaAds.accounts.connect') }}
            </Button>
        </template>

        <p v-if="!appConfigured" class="px-5 pb-4 text-[0.8125rem] text-ink-muted" data-testid="not-configured">{{ t('metaAds.accounts.notConfigured') }}</p>

        <ul v-if="connections.length > 0" class="divide-y divide-line border-t border-line" data-testid="connections">
            <li v-for="connection in connections" :key="connection.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <!-- The name wraps below its own line rather than being cut; the state and buttons move under it when narrow. -->
                <div class="min-w-48 flex-1">
                    <p class="text-sm font-medium wrap-break-word text-ink">{{ connection.userName ?? t('metaAds.accounts.unnamed') }}</p>
                    <p class="text-xs text-ink-subtle">
                        {{ connection.canManage ? t('metaAds.accounts.canManage') : t('metaAds.accounts.readOnly') }}
                        <template v-if="connection.tokenExpiresAt"> · {{ t('metaAds.accounts.expires', { when: format.date(connection.tokenExpiresAt, 'date') }) }}</template>
                    </p>
                    <p v-if="connection.expiresSoon && connection.status === 'connected'" class="mt-1 text-xs text-warn">{{ t('metaAds.accounts.expiresSoon') }}</p>
                    <p v-if="connection.statusReason" class="mt-1 text-xs break-words text-warn">{{ connection.statusReason }}</p>
                </div>
                <Badge :tone="connectionTone(connection)" dot>{{ connectionLabel(connection) }}</Badge>
                <div v-if="canManage" class="flex gap-1.5">
                    <Button v-if="connection.status !== 'connected' || connection.expiresSoon" size="sm" variant="primary" :loading="connecting" :disabled="!appConfigured" @click="connect">
                        <template #icon><RefreshCw class="size-3.5" aria-hidden="true" /></template>
                        {{ t('metaAds.accounts.reconnect') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="askDisconnect(connection)">
                        <template #icon><Unplug class="size-3.5" aria-hidden="true" /></template>
                        {{ t('metaAds.accounts.disconnect') }}
                    </Button>
                </div>
            </li>
        </ul>
        <EmptyState v-else-if="appConfigured" :title="t('metaAds.accounts.noConnection')" :description="t('metaAds.accounts.noConnectionBody')" compact />
    </Card>

    <Card
        v-if="hasUsable || accounts.length > 0"
        class="mt-3"
        :title="t('metaAds.accounts.adAccounts')"
        :description="limit.limit === null
            ? t('metaAds.accounts.seatsUnlimited', { used: format.number(limit.used) })
            : t('metaAds.accounts.seats', { used: format.number(limit.used), limit: format.number(limit.limit) })"
        flush
    >
        <p v-if="seatsFull && canManage" class="px-5 pb-3 text-[0.8125rem] text-warn" role="status" data-testid="seats-full">{{ t('metaAds.accounts.seatsFull') }}</p>

        <ul v-if="accounts.length > 0" class="divide-y divide-line border-t border-line" data-testid="accounts">
            <li v-for="account in accounts" :key="account.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <div class="min-w-48 flex-1">
                    <p class="text-sm font-medium wrap-break-word text-ink">{{ account.name }}</p>
                    <p class="truncate text-xs text-ink-subtle">
                        {{ account.externalId }} · {{ account.currency }}<template v-if="account.timezone"> · {{ account.timezone }}</template>
                        <template v-if="account.businessName"> · {{ account.businessName }}</template>
                    </p>
                    <p v-if="account.metaStatus && !account.metaActive" class="mt-1 text-xs text-warn">{{ t(`metaAds.accounts.metaStatus.${account.metaStatus}`) }}</p>
                    <template v-if="account.status === 'tracked'">
                        <p v-if="account.syncError" class="mt-1 text-xs break-words" :class="account.syncState === 'error' ? 'text-danger' : 'text-ink-muted'">{{ account.syncError }}</p>
                        <p class="mt-1 text-xs text-ink-subtle">
                            <template v-if="account.lastSyncedAt">{{ t('metaAds.accounts.lastSynced', { when: format.date(account.lastSyncedAt, 'datetime') }) }}</template>
                            <template v-else>{{ t('metaAds.accounts.neverSynced') }}</template>
                            <template v-if="account.throttledUntil"> · {{ t('metaAds.accounts.pausedUntil', { when: format.date(account.throttledUntil, 'datetime') }) }}</template>
                            <template v-if="account.usagePercent !== null"> · {{ t('metaAds.accounts.quota', { percent: format.number(account.usagePercent) }) }}</template>
                        </p>
                    </template>
                </div>
                <Badge v-if="account.status === 'tracked' && account.syncState" :tone="SYNC_TONES[account.syncState]" dot>{{ t(`metaAds.accounts.sync.${account.syncState}`) }}</Badge>
                <Badge v-else :tone="account.status === 'tracked' ? 'brand' : 'neutral'">{{ t(`metaAds.accounts.status.${account.status}`) }}</Badge>

                <div v-if="canManage" class="flex gap-1.5">
                    <Button v-if="account.status === 'available'" size="sm" variant="primary" :loading="busy === `track-${account.id}`" :disabled="seatsFull" @click="act('track', account)">
                        {{ t('metaAds.accounts.track') }}
                    </Button>
                    <template v-else-if="account.status === 'tracked'">
                        <Button size="sm" variant="ghost" :loading="busy === `sync-${account.id}`" @click="act('sync', account)">
                            <template #icon><RefreshCw class="size-3.5" aria-hidden="true" /></template>
                            {{ t('metaAds.accounts.syncNow') }}
                        </Button>
                        <Button size="sm" variant="ghost" :loading="busy === `untrack-${account.id}`" @click="act('untrack', account)">{{ t('metaAds.accounts.untrack') }}</Button>
                    </template>
                </div>
            </li>
        </ul>
        <EmptyState v-else :title="t('metaAds.accounts.noAccounts')" :description="t('metaAds.accounts.noAccountsBody')" compact />
    </Card>

    <Card class="mt-3" :title="t('metaAds.accounts.pages.title')" :description="t('metaAds.accounts.pages.description')">
        <p v-if="!socialPages.available" class="text-[0.8125rem] text-ink-muted" data-testid="pages-unavailable">{{ t('metaAds.accounts.pages.unavailable') }}</p>
        <p v-else-if="socialPages.pages.length === 0" class="text-[0.8125rem] text-ink-muted" data-testid="pages-none">{{ t('metaAds.accounts.pages.none') }}</p>
        <ul v-else class="grid gap-1.5 text-sm" data-testid="pages">
            <li v-for="page in socialPages.pages" :key="page.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                <span class="min-w-0 wrap-break-word text-ink">{{ page.name }}</span>
                <Badge :tone="page.status === 'connected' ? 'ok' : 'warn'" dot>{{ t(`metaAds.accounts.pages.${page.status}`) }}</Badge>
            </li>
        </ul>
        <p class="mt-3 text-xs text-ink-subtle">{{ t('metaAds.accounts.pages.leadNote') }}</p>
    </Card>

    <Card v-if="canManage && redirectUri" class="mt-3" :title="t('metaAds.accounts.operator.title')" :description="t('metaAds.accounts.operator.description')">
        <dl class="grid gap-2 text-[0.8125rem]">
            <div class="min-w-0">
                <dt class="eyebrow">{{ t('metaAds.accounts.operator.redirect') }}</dt>
                <dd class="mt-1"><code class="font-mono text-xs break-all text-ink-muted">{{ redirectUri }}</code></dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('metaAds.accounts.operator.permissions') }}</dt>
                <dd class="mt-1 text-ink-muted">{{ requestsManagement ? 'ads_read, ads_management' : 'ads_read' }}</dd>
            </div>
        </dl>
    </Card>

    <ConfirmDialog
        v-model:open="disconnectOpen"
        :title="t('metaAds.accounts.disconnectTitle')"
        :description="t('metaAds.accounts.disconnectBody', { name: disconnecting?.userName ?? '' })"
        :confirm-label="t('metaAds.accounts.disconnect')"
        :loading="busy === 'disconnect'"
        @confirm="disconnect"
    />
</template>
