<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import ProviderDialog from '@/Platform/Components/Ai/ProviderDialog.vue';
import type { AiDriverDefinition, AiProviderAccount } from '@/Platform/Components/Ai/providers';

defineOptions({ layout: PlatformLayout });

defineProps<{
    accounts: AiProviderAccount[];
    drivers: AiDriverDefinition[];
    /** True while no account exists: the environment key (if any) answers. */
    usesEnvironment: boolean;
    environmentConfigured: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

// Add / edit. `null` = closed, `{ account: null }` = a new account.
const dialog = ref<{ account: AiProviderAccount | null } | null>(null);
const dialogOpen = computed({
    get: () => dialog.value !== null,
    set: (value: boolean) => {
        if (!value) {
            dialog.value = null;
        }
    },
});

const busy = ref<string | null>(null);

function post(name: 'platform.ai.test' | 'platform.ai.default', account: AiProviderAccount): void {
    router.post(
        route(name, { account: account.id }),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = `${name}:${account.id}`;
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function isBusy(name: string, account: AiProviderAccount): boolean {
    return busy.value === `${name}:${account.id}`;
}

// Delete
const removing = ref<AiProviderAccount | null>(null);
const removingBusy = ref(false);
const removeOpen = computed({
    get: () => removing.value !== null,
    set: (value: boolean) => {
        if (!value) {
            removing.value = null;
        }
    },
});

function remove(): void {
    const account = removing.value;

    if (!account) {
        return;
    }

    router.delete(route('platform.ai.destroy', { account: account.id }), {
        preserveScroll: true,
        onStart: () => {
            removingBusy.value = true;
        },
        onFinish: () => {
            removingBusy.value = false;
            removing.value = null;
        },
    });
}

function testSummary(account: AiProviderAccount): string | null {
    if (!account.lastTest) {
        return null;
    }

    const when = format.date(account.lastTest.at, 'datetime');

    return account.lastTest.status === 'ok'
        ? t('platformAi.test.ok', { when })
        : t('platformAi.test.failed', { when, code: account.lastTest.reason ?? 'failed' });
}
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader
            :eyebrow="t('shell.nav.sections.platform')"
            :title="t('shell.nav.platformAi')"
            :description="t('platformAi.description')"
        />

        <p class="mb-3 text-[0.8125rem] text-ink-subtle">{{ t('platformAi.secretsNote') }}</p>

        <div class="flex flex-col gap-3">
            <Card flush :title="t('platformAi.list.title')" :description="t('platformAi.list.description')">
                <template #actions>
                    <Button size="sm" variant="secondary" @click="dialog = { account: null }">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('platformAi.add') }}
                    </Button>
                </template>

                <EmptyState
                    v-if="accounts.length === 0"
                    :title="t('platformAi.empty.title')"
                    :description="environmentConfigured ? t('platformAi.empty.envConfigured') : t('platformAi.empty.envMissing')"
                    compact
                >
                    <template #icon><Sparkles /></template>
                </EmptyState>

                <ul v-else>
                    <li
                        v-for="account in accounts"
                        :key="account.id"
                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-line px-5 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium wrap-break-word text-ink">{{ account.name }}</p>
                            <p class="text-[0.8125rem] wrap-break-word text-ink-subtle">
                                {{ t(`platformAi.drivers.${account.driver}`) }} · {{ account.model }}
                            </p>
                            <p v-if="testSummary(account)" class="text-[0.8125rem] text-ink-subtle">{{ testSummary(account) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge v-if="account.isDefault" tone="brand" dot>{{ t('platformAi.default') }}</Badge>
                            <Badge :tone="account.enabled ? 'ok' : 'neutral'" dot>
                                {{ account.enabled ? t('platformAi.enabled') : t('platformAi.disabled') }}
                            </Badge>
                            <Badge :tone="account.hasKey ? 'ok' : 'neutral'">
                                {{ account.hasKey ? t('platformAi.keySet') : t('platformAi.keyMissing') }}
                            </Badge>
                            <Button
                                v-if="account.enabled && !account.isDefault"
                                size="sm"
                                variant="ghost"
                                :loading="isBusy('platform.ai.default', account)"
                                @click="post('platform.ai.default', account)"
                            >
                                {{ t('platformAi.makeDefault') }}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                :loading="isBusy('platform.ai.test', account)"
                                @click="post('platform.ai.test', account)"
                            >
                                {{ t('platformAi.test.action') }}
                            </Button>
                            <Button size="sm" variant="secondary" @click="dialog = { account }">
                                {{ t('common.actions.edit') }}
                            </Button>
                            <Button size="sm" variant="ghost" @click="removing = account">
                                {{ t('common.actions.delete') }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </Card>

            <p v-if="usesEnvironment" class="text-[0.8125rem] text-ink-subtle">{{ t('platformAi.envNote') }}</p>
        </div>

        <ProviderDialog v-if="dialog" v-model:open="dialogOpen" :drivers="drivers" :account="dialog.account" />

        <ConfirmDialog
            v-model:open="removeOpen"
            :title="t('platformAi.remove.title')"
            :description="t('platformAi.remove.description', { name: removing?.name ?? '' })"
            :confirm-label="t('common.actions.delete')"
            :loading="removingBusy"
            @confirm="remove"
        />
    </div>
</template>
