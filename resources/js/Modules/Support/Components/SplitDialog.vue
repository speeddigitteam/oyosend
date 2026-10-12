<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** The messages to move are chosen in the thread; this asks for the new ticket's subject. */
const props = defineProps<{ ticketId: string; number: string; messageIds: string[]; defaultSubject: string }>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const format = useFormat();
const form = useForm({ subject: '', message_ids: [] as string[] });

const messageError = computed(() => form.errors.message_ids ?? Object.entries(form.errors).find(([key]) => key.startsWith('message_ids.'))?.[1]);

watch(open, (value) => {
    if (value) {
        form.subject = props.defaultSubject;
        form.clearErrors();
    }
});

function submit(): void {
    form.message_ids = [...props.messageIds];
    form.post(route('support.tickets.split', { ticket: props.ticketId }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('support.split.title', { number })" :description="t('support.split.description', { count: format.number(messageIds.length) })" :persistent="form.processing">
        <form id="support-split-form" class="grid gap-4" @submit.prevent="submit">
            <Input v-model="form.subject" :label="t('support.split.subject')" :error="form.errors.subject" required maxlength="200" />
            <p v-if="messageError" class="text-sm text-danger" role="alert">{{ messageError }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-split-form" type="submit" variant="primary" :loading="form.processing" :disabled="messageIds.length === 0">
                {{ t('support.split.submit') }}
            </Button>
        </template>
    </Modal>
</template>
