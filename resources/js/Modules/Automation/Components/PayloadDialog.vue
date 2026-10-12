<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import KeyValueEditor from './KeyValueEditor.vue';

/**
 * Sample or starting data for a test run or a manual run: a few name and
 * value rows. The values are data; they are never evaluated.
 */
const props = defineProps<{
    title: string;
    description: string;
    submitLabel: string;
    processing?: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>();
const { t } = useI18n();

const payload = ref<Record<string, unknown>>({});

watch(open, (value) => {
    if (value) {
        payload.value = {};
    }
});

function submit(): void {
    emit('submit', payload.value);
}
</script>

<template>
    <Modal v-model:open="open" :title="title" :description="description" :persistent="processing" size="md">
        <p class="eyebrow mb-1">{{ t('automation.workflows.payload.title') }}</p>
        <p class="mb-3 text-[0.8125rem] text-ink-subtle">{{ t('automation.workflows.payload.description') }}</p>
        <KeyValueEditor
            v-model="payload"
            :add-label="t('automation.workflows.payload.addRow')"
            :key-label="t('automation.workflows.payload.key')"
            :value-label="t('automation.workflows.payload.value')"
        />
        <template #footer>
            <Button variant="ghost" type="button" :disabled="props.processing" @click="open = false">{{ t('automation.common.cancel') }}</Button>
            <Button variant="primary" type="button" :loading="props.processing" @click="submit">{{ submitLabel }}</Button>
        </template>
    </Modal>
</template>
