<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';

/**
 * The frame of the app's small form dialogs: a title, the fields in a form, and a cancel / submit footer.
 * The caller owns the form state and what submitting does.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        submitLabel: string;
        processing?: boolean;
        disabled?: boolean;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { description: undefined, size: 'md' },
);

const emit = defineEmits<{ 'update:open': [value: boolean]; submit: [] }>();

const { t } = useI18n();
const formId = useId();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
</script>

<template>
    <Modal v-model:open="model" :size="size" :title="title" :description="description" :persistent="processing">
        <form :id="formId" class="grid gap-4" novalidate @submit.prevent="emit('submit')">
            <slot />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="processing" @click="model = false">{{ t('whatsapp.common.cancel') }}</Button>
            <Button type="submit" :form="formId" variant="primary" :loading="processing" :disabled="disabled">{{ submitLabel }}</Button>
        </template>
    </Modal>
</template>
