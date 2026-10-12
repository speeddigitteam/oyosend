<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, RotateCcw, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import MessageStatusBadge from '../../Components/MessageStatusBadge.vue';
import type { MessageDetail } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ message: MessageDetail }>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
const { label, failure } = useCommunicationLabels();

const cancelOpen = ref(false);
const pending = ref(false);

const title = computed(() => props.message.subject ?? t('communication.message.title'));

const times = computed(() =>
    [
        { key: 'createdAt', value: props.message.createdAt },
        { key: 'queuedAt', value: props.message.queuedAt },
        { key: 'nextAttemptAt', value: props.message.status === 'queued' ? props.message.nextAttemptAt : null },
        { key: 'sentAt', value: props.message.sentAt },
        { key: 'deliveredAt', value: props.message.deliveredAt },
        { key: 'failedAt', value: props.message.failedAt },
    ].filter((entry): entry is { key: string; value: string } => entry.value !== null),
);

function retry(): void {
    pending.value = true;
    router.post(route('communication.messages.retry', { message: props.message.id }), {}, { preserveScroll: true, onFinish: () => { pending.value = false; } });
}

function cancel(): void {
    pending.value = true;
    router.post(route('communication.messages.cancel', { message: props.message.id }), {}, {
        preserveScroll: true,
        onSuccess: () => { cancelOpen.value = false; },
        onFinish: () => { pending.value = false; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="title" :description="message.recipient">
        <template #actions>
            <Button variant="secondary" :href="route('communication.messages.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('communication.message.back') }}
            </Button>
            <Button v-if="message.retryable" variant="primary" :loading="pending" @click="retry">
                <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                {{ t('communication.message.retry') }}
            </Button>
            <Button v-if="message.cancellable" variant="danger" @click="cancelOpen = true">
                <template #icon><XCircle class="size-4" aria-hidden="true" /></template>
                {{ t('communication.message.cancel') }}
            </Button>
        </template>
    </PageHeader>
    <CommunicationNav current="messages" />

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card :title="t('communication.message.content')">
                <p v-if="message.body" class="text-sm whitespace-pre-wrap wrap-anywhere text-ink">{{ message.body }}</p>
                <p v-else class="text-sm text-ink-subtle">{{ t('communication.message.noBody') }}</p>
            </Card>

            <Card v-if="message.failureCode" :title="t('communication.message.failure')">
                <p class="text-sm text-ink">{{ failure(message.failureCode) }}</p>
                <p v-if="message.failureMessage" class="mt-2 text-sm wrap-anywhere text-ink-muted">{{ message.failureMessage }}</p>
            </Card>
        </div>

        <div class="grid content-start gap-3">
            <Card :title="t('communication.message.details')">
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="eyebrow">{{ t('communication.common.status') }}</dt>
                        <dd class="mt-1"><MessageStatusBadge :status="message.status" /></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('communication.common.channel') }}</dt>
                        <dd class="mt-1 text-ink">{{ label('channel', message.channel) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('communication.common.purpose') }}</dt>
                        <dd class="mt-1"><Badge tone="neutral">{{ label('purpose', message.purpose) }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('communication.message.recipient') }}</dt>
                        <dd class="mt-1 wrap-anywhere text-ink">{{ message.recipient }}</dd>
                    </div>
                    <div v-if="message.contact">
                        <dt class="eyebrow">{{ t('communication.common.contact') }}</dt>
                        <dd class="mt-1 text-ink">
                            {{ message.contact.name }}
                            <Link
                                v-if="can('communication.timeline.view')"
                                :href="route('communication.timeline.contact.show', { contact: message.contact.id })"
                                class="mt-1 block text-brand-text hover:underline"
                            >
                                {{ t('communication.message.viewTimeline') }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="message.company">
                        <dt class="eyebrow">{{ t('communication.common.company') }}</dt>
                        <dd class="mt-1 text-ink">{{ message.company.name }}</dd>
                    </div>
                    <div v-if="message.provider">
                        <dt class="eyebrow">{{ t('communication.message.provider') }}</dt>
                        <dd class="mt-1 text-ink">{{ message.provider }}</dd>
                    </div>
                    <div v-if="message.locale">
                        <dt class="eyebrow">{{ t('communication.message.language') }}</dt>
                        <dd class="mt-1 text-ink">{{ label('locale', message.locale) }}</dd>
                    </div>
                    <div v-if="message.source">
                        <dt class="eyebrow">{{ t('communication.message.source') }}</dt>
                        <dd class="mt-1 text-ink">{{ label('source', message.source) }}</dd>
                    </div>
                    <div v-if="message.owner">
                        <dt class="eyebrow">{{ t('communication.message.sender') }}</dt>
                        <dd class="mt-1 text-ink">{{ message.owner.name }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('communication.message.attempts') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.number(message.attempts) }}</dd>
                    </div>
                </dl>
            </Card>

            <Card :title="t('communication.message.timeline')">
                <ol class="grid gap-3 text-sm">
                    <li v-for="entry in times" :key="entry.key">
                        <span class="eyebrow block">{{ t(`communication.message.${entry.key}`) }}</span>
                        <span class="tabular text-ink">{{ format.date(entry.value, 'datetime') }}</span>
                    </li>
                </ol>
            </Card>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="cancelOpen"
        :title="t('communication.message.cancelTitle')"
        :description="t('communication.message.cancelBody')"
        :confirm-label="t('communication.message.cancelConfirm')"
        :loading="pending"
        @confirm="cancel"
    />
</template>
