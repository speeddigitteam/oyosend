<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';

/** Asks for a reason and posts it: voiding an invoice, reversing a payment. */
const props = defineProps<{
    open: boolean;
    title: string;
    description?: string;
    confirmLabel: string;
    url: string;
    danger?: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({ reason: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.post(props.url, { preserveScroll: true, onSuccess: () => { model.value = false; form.reset(); } });
}
</script>

<template>
    <Modal v-model:open="model" size="sm" :title="title" :description="description" :persistent="form.processing">
        <form id="reason-form" @submit.prevent="submit">
            <Textarea v-model="form.reason" :label="t('invoicing.fields.reason')" :error="form.errors.reason" maxlength="500" required autofocus />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="reason-form" :variant="danger ? 'danger' : 'primary'" :loading="form.processing" :disabled="form.reason.trim() === ''">{{ confirmLabel }}</Button>
        </template>
    </Modal>
</template>
