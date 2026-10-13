<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Mail, MessageCircle, MessageSquareText, Plus } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import AccountDialog from '@/Platform/Components/Messaging/AccountDialog.vue';
import type { MessagingAccount, MessagingChannel, ProviderDefinition } from '@/Platform/Components/Messaging/accounts';

defineOptions({ layout: PlatformLayout });

const props = defineProps<{
    accounts: MessagingAccount[];
    providers: ProviderDefinition[];
    workspaces: { id: string; name: string }[];
}>();

const { t, te } = useI18n();
const format = useFormat();

const CHANNELS: { key: MessagingChannel; icon: Component }[] = [
    { key: 'email', icon: Mail },
    { key: 'sms', icon: MessageSquareText },
    { key: 'whatsapp', icon: MessageCircle },
];

function accountsOf(channel: MessagingChannel): MessagingAccount[] {
    return props.accounts.filter((a) => a.channel === channel);
}

function providersOf(channel: MessagingChannel): ProviderDefinition[] {
    return props.providers.filter((p) => p.channel === channel);
}

function providerName(account: MessagingAccount): string {
    const key = `platformMessaging.providers.${account.provider}`;

    return te(key) ? t(key) : account.providerLabel;
}

function availability(account: MessagingAccount): string {
    if (account.tenantIds === null) {
        return t('platformMessaging.availability.all');
    }

    return t('platformMessaging.availability.count', { count: format.number(account.tenantIds.length) });
}

// Add / edit
const dialog = ref<{ channel: MessagingChannel; account: MessagingAccount | null } | null>(null);
const dialogOpen = computed({
    get: () => dialog.value !== null,
    set: (value: boolean) => {
        if (!value) {
            dialog.value = null;
        }
    },
});

// Test message
const testing = ref<MessagingAccount | null>(null);
const testOpen = computed({
    get: () => testing.value !== null,
    set: (value: boolean) => {
        if (!value) {
            testing.value = null;
        }
    },
});
const testForm = useForm({ to: '' });

function startTest(account: MessagingAccount): void {
    testForm.reset();
    testForm.clearErrors();
    testing.value = account;
}

function sendTest(): void {
    const account = testing.value;

    if (!account) {
        return;
    }

    testForm.transform((data) => ({ to: data.to.trim() })).post(route('platform.messaging.test', { account: account.id }), {
        preserveScroll: true,
        onSuccess: () => {
            testing.value = null;
        },
    });
}

// Delete
const removing = ref<MessagingAccount | null>(null);
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

    router.delete(route('platform.messaging.destroy', { account: account.id }), {
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
</script>

<template>
    <div>
        <PageHeader
            :eyebrow="t('shell.nav.sections.platform')"
            :title="t('shell.nav.platformMessaging')"
            :description="t('platformMessaging.description')"
        />

        <p class="mb-3 text-[0.8125rem] text-ink-subtle">{{ t('platformMessaging.secretsNote') }}</p>

        <!-- One box per channel, side by side from the lg breakpoint; each account is a box of its own inside. -->
        <div class="grid gap-3 lg:grid-cols-3">
            <Card v-for="channel in CHANNELS" :key="channel.key" :data-testid="`channel-${channel.key}`">
                <template #header>
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text">
                            <component :is="channel.icon" class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-[0.9375rem] font-semibold tracking-tight text-ink">{{ t(`platformMessaging.channels.${channel.key}.title`) }}</h2>
                            <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ t(`platformMessaging.channels.${channel.key}.description`) }}</p>
                        </div>
                    </div>
                </template>

                <EmptyState
                    v-if="accountsOf(channel.key).length === 0"
                    :title="t(`platformMessaging.channels.${channel.key}.empty`)"
                    :description="t(`platformMessaging.channels.${channel.key}.emptyBody`)"
                    compact
                >
                    <template #icon><component :is="channel.icon" /></template>
                </EmptyState>

                <ul v-else class="grid gap-2">
                    <li v-for="account in accountsOf(channel.key)" :key="account.id" class="glass-well grid gap-3 rounded-control p-3" data-testid="account">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium wrap-break-word text-ink">{{ account.name }}</p>
                                <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ providerName(account) }} · {{ availability(account) }}</p>
                            </div>
                            <Badge class="shrink-0" :tone="account.enabled ? 'ok' : 'neutral'" dot>
                                {{ account.enabled ? t('platformMessaging.enabled') : t('platformMessaging.disabled') }}
                            </Badge>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 border-t border-line pt-2.5">
                            <Button size="sm" variant="secondary" @click="dialog = { channel: channel.key, account }">
                                {{ t('common.actions.edit') }}
                            </Button>
                            <Button v-if="account.supportsTest" size="sm" variant="ghost" @click="startTest(account)">
                                {{ t('platformMessaging.test.action') }}
                            </Button>
                            <Button class="ml-auto" size="sm" variant="ghost" @click="removing = account">
                                {{ t('common.actions.delete') }}
                            </Button>
                        </div>
                    </li>
                </ul>

                <template #footer>
                    <Button size="sm" variant="secondary" block @click="dialog = { channel: channel.key, account: null }">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('platformMessaging.add') }}
                    </Button>
                </template>
            </Card>
        </div>

        <AccountDialog
            v-if="dialog"
            v-model:open="dialogOpen"
            :channel="dialog.channel"
            :providers="providersOf(dialog.channel)"
            :workspaces="workspaces"
            :account="dialog.account"
        />

        <Modal
            v-if="testing"
            v-model:open="testOpen"
            size="sm"
            :persistent="testForm.processing"
            :title="t('platformMessaging.test.title')"
            :description="t(`platformMessaging.test.description.${testing.channel}`)"
        >
            <form id="messaging-test-form" novalidate @submit.prevent="sendTest">
                <Input
                    v-model="testForm.to"
                    name="to"
                    autocomplete="off"
                    :type="testing.channel === 'email' ? 'email' : 'tel'"
                    :label="t(`platformMessaging.test.to.${testing.channel}`)"
                    :error="testForm.errors.to"
                    required
                />
            </form>
            <template #footer>
                <Button variant="ghost" :disabled="testForm.processing" @click="testOpen = false">{{ t('common.actions.cancel') }}</Button>
                <Button type="submit" form="messaging-test-form" variant="primary" :loading="testForm.processing">
                    {{ t('platformMessaging.test.send') }}
                </Button>
            </template>
        </Modal>

        <ConfirmDialog
            v-model:open="removeOpen"
            :title="t('platformMessaging.remove.title')"
            :description="t('platformMessaging.remove.description', { name: removing?.name ?? '' })"
            :confirm-label="t('common.actions.delete')"
            :loading="removingBusy"
            @confirm="remove"
        />
    </div>
</template>
