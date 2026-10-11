<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';

const props = defineProps<{ open: boolean; invoiceId: string; defaultEmail: string | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({ email: '', message: '' });

watch(() => props.open, (open) => {
    if (!open) return;

    form.defaults({ email: props.defaultEmail ?? '', message: '' });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        email: data.email.trim() === '' ? null : data.email.trim(),
        message: data.message.trim() === '' ? null : data.message.trim(),
    }));
    form.post(route('invoicing.invoices.send', { invoice: props.invoiceId }), {
        preserveScroll: true,
        onSuccess: () => { model.value = false; },
    });
}
</script>

<template>
    <Modal v-model:open="model" :title="t('invoicing.send.title')" :description="t('invoicing.send.description')" :persistent="form.processing">
        <form id="send-form" class="grid gap-4" @submit.prevent="submit">
            <Input v-model="form.email" type="email" :label="t('invoicing.send.to')" :error="form.errors.email" maxlength="190" :hint="t('invoicing.send.toHint')" />
            <Textarea v-model="form.message" :label="t('invoicing.send.message')" :error="form.errors.message" maxlength="2000" optional />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('invoicing.common.cancel') }}</Button>
            <Button type="submit" form="send-form" variant="primary" :loading="form.processing">{{ t('invoicing.send.submit') }}</Button>
        </template>
    </Modal>
</template>
