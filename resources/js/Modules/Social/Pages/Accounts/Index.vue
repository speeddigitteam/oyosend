<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Link2, RefreshCw, Share2, Unplug } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AccountAccessSection from '../../Components/insights/AccountAccessSection.vue';
import type { AccessPanelProps } from '../../Components/insights/types';
import PlatformIcon from '../../Components/PlatformIcon.vue';
import { platformRequirements } from '../../Components/requirements';
import SocialBadge from '../../Components/SocialBadge.vue';
import { safeUrl } from '../../Components/socialFormat';
import type { AccountRow, Capabilities, PlatformInfo } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    accounts: AccountRow[];
    platforms: PlatformInfo[];
    limit: { limit: number | null; used: number };
    canManage: boolean;
    redirectUris: { platform: string; uri: string }[];
    accessPanel?: AccessPanelProps;
}>();

const { t } = useI18n();
const format = useFormat();

const connecting = ref<string | null>(null);
const disconnecting = ref<AccountRow | null>(null);
const disconnectOpen = ref(false);
const busy = ref(false);

const seatsFull = computed(() => props.limit.limit !== null && props.limit.used >= props.limit.limit);
const uris = computed(() => props.redirectUris.filter((item) => props.platforms.some((platform) => platform.key === item.platform && platform.available)));
const needAttention = computed(() => props.accounts.filter((account) => account.status === 'needs_reauthorization'));

/** What a platform can do, from what its adapter reports as implemented. */
function abilities(capabilities: Capabilities): string[] {
    const list: string[] = [];

    if (capabilities.publish) {
        list.push(t('social.accounts.can.publish'));
    }

    if (capabilities.schedule) {
        list.push(t('social.accounts.can.schedule'));
    }

    if (capabilities.images && (capabilities.maxMedia ?? 0) > 0) {
        list.push(t('social.accounts.can.images'));
    }

    if (capabilities.video) {
        list.push(t('social.accounts.can.video'));
    }

    if (capabilities.analytics) {
        list.push(t('social.accounts.can.analytics'));
    }

    if (capabilities.comments) {
        list.push(t('social.accounts.can.comments'));
    }

    return list;
}

/** What every post for this platform must carry, in words (empty when nothing). */
function requirementsText(capabilities: Capabilities): string {
    return platformRequirements(capabilities)
        .map((item) => t(`social.requirements.${item.key}`, { value: format.number(item.value ?? 0) }))
        .join(' · ');
}

function availabilityLabel(platform: PlatformInfo): string {
    if (platform.available) {
        return t('social.accounts.availability.available');
    }

    return platform.unavailableCode === 'not_configured'
        ? t('social.accounts.availability.not_configured')
        : t('social.accounts.availability.not_implemented');
}

function connect(platform: string, accountId?: string): void {
    connecting.value = accountId ?? platform;
    router.post(route('social.accounts.connect', { platform }), accountId ? { account_id: accountId } : {}, {
        onFinish: () => {
            connecting.value = null;
        },
    });
}

function askDisconnect(account: AccountRow): void {
    disconnecting.value = account;
    disconnectOpen.value = true;
}

function disconnect(): void {
    if (!disconnecting.value) {
        return;
    }

    busy.value = true;
    router.delete(route('social.accounts.destroy', { account: disconnecting.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            disconnectOpen.value = false;
        },
    });
}

function canReconnect(account: AccountRow): boolean {
    return props.canManage && account.platformAvailable && account.status === 'needs_reauthorization';
}

function avatar(account: AccountRow): string | null {
    const url = safeUrl(account.avatarUrl);

    return url !== null && url.startsWith('https://') ? url : null;
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.accounts.title')" :description="t('social.accounts.description')" />

    <p
        v-if="needAttention.length > 0"
        class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn"
        role="status"
        data-testid="attention"
    >
        {{ t('social.accounts.attention', { count: format.number(needAttention.length) }) }}
    </p>

    <Card :title="t('social.accounts.connected')" :description="limit.limit === null
        ? t('social.accounts.seatsUnlimited', { used: format.number(limit.used) })
        : t('social.accounts.seats', { used: format.number(limit.used), limit: format.number(limit.limit) })" flush>
        <ul v-if="accounts.length > 0" class="divide-y divide-line" data-testid="accounts">
            <li v-for="account in accounts" :key="account.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <img
                    v-if="avatar(account)"
                    :src="avatar(account)!"
                    alt=""
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    class="size-9 shrink-0 rounded-full bg-hover object-cover"
                />
                <span v-else class="flex size-9 shrink-0 items-center justify-center rounded-full bg-hover text-ink-subtle" aria-hidden="true">
                    <PlatformIcon :platform="account.platform" class="size-4" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink">{{ account.name }}</p>
                    <p class="truncate text-xs text-ink-subtle">
                        {{ account.platformLabel }}<template v-if="account.handle"> · {{ account.handle }}</template><template v-if="account.type"> · {{ account.type }}</template>
                    </p>
                    <p v-if="account.statusReason" class="mt-1 text-xs break-words text-warn">{{ account.statusReason }}</p>
                    <p v-if="!account.platformAvailable" class="mt-1 text-xs text-ink-muted">{{ t('social.accounts.platformUnavailable') }}</p>
                    <p v-if="account.lastUsedAt" class="mt-1 text-xs text-ink-subtle">{{ t('social.accounts.lastUsed', { when: format.date(account.lastUsedAt, 'datetime') }) }}</p>
                </div>

                <SocialBadge kind="account" :value="account.status" />

                <div v-if="canManage" class="flex gap-1.5">
                    <Button v-if="canReconnect(account)" size="sm" variant="primary" :loading="connecting === account.id" @click="connect(account.platform, account.id)">
                        <template #icon><RefreshCw class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.accounts.reconnect') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="askDisconnect(account)">
                        <template #icon><Unplug class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.accounts.disconnect') }}
                    </Button>
                </div>
            </li>
        </ul>
        <EmptyState v-else :title="t('social.accounts.empty')" :description="t('social.accounts.emptyBody')" compact>
            <template #icon><Share2 /></template>
        </EmptyState>
    </Card>

    <AccountAccessSection v-if="accessPanel" :accounts="accounts" :panel="accessPanel" />

    <h2 class="mt-6 mb-3 text-base">{{ t('social.accounts.platforms') }}</h2>
    <p v-if="seatsFull && canManage" class="mb-3 text-[0.8125rem] text-warn" role="status" data-testid="seats-full">{{ t('social.accounts.seatsFull') }}</p>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <Card v-for="platform in platforms" :key="platform.key" :title="platform.label" class="h-full" :data-testid="`platform-${platform.key}`">
            <template #header>
                <h2 class="flex min-w-0 items-center gap-2 text-[0.9375rem] font-semibold tracking-tight text-ink">
                    <PlatformIcon :platform="platform.key" class="size-4 shrink-0 text-ink-subtle" />
                    <span class="min-w-0 wrap-break-word">{{ platform.label }}</span>
                </h2>
            </template>
            <template #actions>
                <Badge :tone="platform.available ? 'ok' : 'neutral'" dot>{{ availabilityLabel(platform) }}</Badge>
            </template>

            <p v-if="!platform.available && platform.reason" class="text-[0.8125rem] break-words text-ink-muted" data-testid="reason">{{ platform.reason }}</p>

            <template v-if="platform.available">
                <p class="eyebrow">{{ t('social.accounts.supports') }}</p>
                <p class="mt-1 text-[0.8125rem] text-ink-muted">{{ abilities(platform.capabilities).join(', ') || t('social.common.none') }}</p>
                <p v-if="!platform.capabilities.video" class="mt-2 text-xs text-ink-subtle">{{ t('social.accounts.noVideo') }}</p>
                <p v-if="(platform.capabilities.maxMedia ?? 0) === 0" class="mt-1 text-xs text-ink-subtle">{{ t('social.accounts.textOnly') }}</p>
                <p v-if="requirementsText(platform.capabilities) !== ''" class="mt-1 text-xs text-ink-subtle" data-testid="requirements">
                    {{ requirementsText(platform.capabilities) }}
                </p>
                <p v-if="(platform.capabilities.maxText ?? 0) > 0" class="mt-1 text-xs text-ink-subtle">
                    {{ t('social.accounts.maxText', { count: format.number(platform.capabilities.maxText ?? 0) }) }}
                </p>
                <div v-if="canManage" class="mt-4">
                    <Button
                        size="sm"
                        variant="primary"
                        :loading="connecting === platform.key"
                        :disabled="seatsFull"
                        @click="connect(platform.key)"
                    >
                        <template #icon><Link2 class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.accounts.connect', { platform: platform.label }) }}
                    </Button>
                </div>
            </template>
        </Card>
    </div>

    <Card v-if="canManage && uris.length > 0" class="mt-6" :title="t('social.accounts.redirectTitle')" :description="t('social.accounts.redirectHint')">
        <ul class="grid gap-2 text-[0.8125rem]">
            <li v-for="item in uris" :key="item.platform" class="min-w-0">
                <span class="eyebrow mr-2">{{ platforms.find((platform) => platform.key === item.platform)?.label ?? item.platform }}</span>
                <code class="font-mono text-xs break-all text-ink-muted">{{ item.uri }}</code>
            </li>
        </ul>
    </Card>

    <ConfirmDialog
        v-model:open="disconnectOpen"
        :title="t('social.accounts.disconnectTitle')"
        :description="t('social.accounts.disconnectBody', { name: disconnecting?.name ?? '' })"
        :confirm-label="t('social.accounts.disconnect')"
        :loading="busy"
        @confirm="disconnect"
    />
</template>
