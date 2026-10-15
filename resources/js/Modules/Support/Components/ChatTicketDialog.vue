<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';

/**
 * Turn a website chat into a support ticket (ADR 0032 amendment). The server keeps it to one ticket per chat and
 * writes the transcript as an internal note; this only collects the subject, priority, queue and whether to close
 * the chat as well.
 */
const props = defineProps<{
    conversationId: string;
    subject: string;
    priorities: string[];
    queues: { id: string; name: string }[];
    canClose: boolean;
}>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const form = useForm({ subject: '', priority: '', queue_id: '', close: false });

const priorityOptions = computed(() => [
    { value: '', label: t('support.liveChat.ticket.defaultPriority') },
    ...props.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) })),
]);
const queueOptions = computed(() => [
    { value: '', label: t('support.liveChat.ticket.routed') },
    ...props.queues.map((queue) => ({ value: queue.id, label: queue.name })),
]);
const ticketError = computed(() => (form.errors as Record<string, string | undefined>).ticket);

watch(open, (value) => {
    if (value) {
        form.defaults({ subject: props.subject, priority: '', queue_id: '', close: props.canClose });
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.transform((data) => ({ ...data, close: props.canClose && data.close })).post(route('support.live_chat.ticket', { conversation: props.conversationId }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('support.liveChat.ticket.title')" :description="t('support.liveChat.ticket.description')" :persistent="form.processing">
        <form id="support-chat-ticket-form" class="grid gap-4" @submit.prevent="submit">
            <Input v-model="form.subject" :label="t('support.liveChat.ticket.subject')" :error="form.errors.subject" required maxlength="200" />
            <Select v-model="form.priority" :options="priorityOptions" :label="t('support.liveChat.ticket.priority')" :error="form.errors.priority" />
            <Select v-if="queues.length" v-model="form.queue_id" :options="queueOptions" :label="t('support.liveChat.ticket.queue')" :error="form.errors.queue_id" />
            <Checkbox v-if="canClose" v-model="form.close" :label="t('support.liveChat.ticket.close')" />
            <p class="text-sm text-ink-muted">{{ t('support.liveChat.ticket.transcriptNote') }}</p>
            <p v-if="ticketError" class="text-sm text-danger" role="alert">{{ ticketError }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-chat-ticket-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.liveChat.ticket.submit') }}</Button>
        </template>
    </Modal>
</template>
