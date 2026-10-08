<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import Button from './Button.vue';
import Modal from './Modal.vue';

/**
 * Asks before a destructive or hard-to-undo action. The caller performs the
 * action on `confirm` and closes the dialog when it has finished.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel?: string;
        tone?: 'danger' | 'default';
        loading?: boolean;
    }>(),
    { tone: 'danger', description: undefined, confirmLabel: undefined },
);

defineEmits<{ confirm: [] }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
</script>

<template>
    <Modal v-model:open="open" :title="title" :description="description" size="sm" :persistent="loading">
        <slot />
        <template #footer>
            <Button variant="ghost" :disabled="loading" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button :variant="tone === 'danger' ? 'danger' : 'primary'" :loading="loading" @click="$emit('confirm')">
                {{ confirmLabel ?? t('common.actions.confirm') }}
            </Button>
        </template>
    </Modal>
</template>
