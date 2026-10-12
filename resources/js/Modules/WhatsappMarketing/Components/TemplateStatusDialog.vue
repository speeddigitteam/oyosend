<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { TemplateRow } from './types';

/**
 * Record the status Meta shows for a template, by hand. The source is kept as "entered by an administrator",
 * so it is never confused with a status the provider reported through its webhook.
 */
const props = defineProps<{ template: TemplateRow | null; statuses: string[] }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const form = useForm({ status: 'approved' as string, status_reason: '' });

watch(
    () => [open.value, props.template?.id] as const,
    ([isOpen]) => {
        if (isOpen && props.template) {
            form.defaults({ status: props.template.status, status_reason: props.template.statusReason ?? '' });
            form.reset();
            form.clearErrors();
        }
    },
);

const options = computed(() => props.statuses.map((value) => ({ value, label: t(`whatsapp.templateStatus.${value}`) })));
const wantsReason = computed(() => ['rejected', 'paused', 'disabled'].includes(form.status));

function submit(): void {
    if (!props.template) {
        return;
    }

    form
        .transform((data) => ({ status: data.status, status_reason: wantsReason.value ? data.status_reason.trim() || null : null }))
        .post(route('whatsapp_marketing.templates.status', { template: props.template.id }), {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
}
</script>

<template>
    <Modal
        v-model:open="open"
        size="sm"
        :title="t('whatsapp.templates.recordTitle', { name: template?.name ?? '' })"
        :description="t('whatsapp.templates.recordDescription')"
        :persistent="form.processing"
    >
        <form id="whatsapp-template-status" class="grid gap-4" novalidate @submit.prevent="submit">
            <Select v-model="form.status" :options="options" :label="t('whatsapp.templates.status')" :error="form.errors.status" />
            <Input v-if="wantsReason" v-model="form.status_reason" :label="t('whatsapp.templates.statusReason')" :error="form.errors.status_reason" optional maxlength="300" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('whatsapp.common.cancel') }}</Button>
            <Button type="submit" form="whatsapp-template-status" variant="primary" :loading="form.processing">{{ t('whatsapp.templates.record') }}</Button>
        </template>
    </Modal>
</template>
