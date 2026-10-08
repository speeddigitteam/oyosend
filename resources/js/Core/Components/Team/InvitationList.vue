<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Mail, MailX } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { useFocusReturn } from './focus';
import type { OpenInvitation } from './types';

/**
 * Invitations that are still waiting for an answer, with the means to take
 * one back. The server only sends this list to people who may invite, so
 * everyone who sees a row may also withdraw it.
 */
defineProps<{
    invitations: OpenInvitation[];
    /** Offer "Send again" on expired invitations. */
    canResend: boolean;
}>();

defineEmits<{ resend: [invitation: OpenInvitation] }>();

const { t } = useI18n();
const format = useFormat();

const target = ref<OpenInvitation | null>(null);
const confirmOpen = ref(false);
const withdrawing = ref(false);

useFocusReturn(confirmOpen);

function ask(invitation: OpenInvitation): void {
    target.value = invitation;
    confirmOpen.value = true;
}

function withdraw(): void {
    if (!target.value) {
        return;
    }

    withdrawing.value = true;

    router.delete(route('core.invitations.destroy', { invitation: target.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
        onFinish: () => {
            withdrawing.value = false;
        },
    });
}
</script>

<template>
    <Card :title="t('team.invitations.title')" :description="t('team.invitations.description')" flush>
        <EmptyState
            v-if="invitations.length === 0"
            :title="t('team.invitations.empty')"
            :description="t('team.invitations.emptyBody')"
            compact
        >
            <template #icon><Mail /></template>
        </EmptyState>

        <ul v-else>
            <li
                v-for="invitation in invitations"
                :key="invitation.id"
                class="flex flex-col gap-x-6 gap-y-2.5 border-t border-line px-5 py-3 sm:flex-row sm:items-center"
            >
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span
                        class="glass-well flex size-9 shrink-0 items-center justify-center rounded-full text-ink-subtle"
                        aria-hidden="true"
                    >
                        <MailX v-if="invitation.expired" class="size-4" />
                        <Mail v-else class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink" :title="invitation.email">{{ invitation.email }}</p>
                        <p class="text-[0.8125rem] wrap-break-word text-ink-subtle">
                            {{
                                invitation.inviter
                                    ? t('team.invitations.invitedAsBy', { role: invitation.role, inviter: invitation.inviter })
                                    : t('team.invitations.invitedAs', { role: invitation.role })
                            }}
                            <span aria-hidden="true"> · </span>
                            <time :datetime="invitation.createdAt" class="whitespace-nowrap">{{ format.relative(invitation.createdAt) }}</time>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 pl-12 sm:shrink-0 sm:justify-end sm:pl-0">
                    <span v-if="invitation.expired" class="flex items-center gap-2">
                        <Badge tone="warn" dot>{{ t('common.status.expired') }}</Badge>
                        <span class="sr-only">{{ t('team.invitations.expiredOn', { date: format.date(invitation.expiresAt) }) }}</span>
                    </span>
                    <time v-else :datetime="invitation.expiresAt" class="text-[0.8125rem] whitespace-nowrap text-ink-muted" :title="format.date(invitation.expiresAt, 'datetime')">
                        {{ t('team.invitations.expires', { when: format.relative(invitation.expiresAt) }) }}
                    </time>

                    <span class="flex items-center gap-1">
                        <Button
                            v-if="invitation.expired && canResend"
                            size="sm"
                            :aria-label="t('team.invitations.resendFor', { email: invitation.email })"
                            @click="$emit('resend', invitation)"
                        >
                            {{ t('team.invitations.resend') }}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :aria-label="t('team.invitations.withdrawFor', { email: invitation.email })"
                            @click="ask(invitation)"
                        >
                            {{ t('team.invitations.withdraw') }}
                        </Button>
                    </span>
                </div>
            </li>
        </ul>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('team.invitations.confirm.title')"
            :description="t('team.invitations.confirm.body', { email: target?.email })"
            :confirm-label="t('team.invitations.confirm.submit')"
            :loading="withdrawing"
            @confirm="withdraw"
        />
    </Card>
</template>
