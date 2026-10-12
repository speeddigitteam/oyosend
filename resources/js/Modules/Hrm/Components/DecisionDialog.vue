<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Textarea from '@/Core/Components/Textarea.vue';
import HrmFormDialog from './HrmFormDialog.vue';

/**
 * Approve or reject with an optional note. Rejecting needs a note, which the
 * person who made the request will read.
 */
const props = defineProps<{
    open: boolean;
    /** Where the decision is posted. */
    url: string;
    decision: 'approve' | 'reject';
    title: string;
    description?: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ decision: 'approve' as 'approve' | 'reject', note: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
});

const rejecting = computed(() => props.decision === 'reject');
const unusable = computed(() => rejecting.value && form.note.trim() === '');

function submit(): void {
    form.transform((data) => ({ decision: props.decision, note: data.note.trim() === '' ? null : data.note.trim() }))
        .post(props.url, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="sm"
        :title="title"
        :description="description"
        :submit-label="rejecting ? t('hrm.decision.reject') : t('hrm.decision.approve')"
        :danger="rejecting"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Textarea
            v-model="form.note"
            :label="t('hrm.fields.note')"
            :optional="!rejecting"
            :hint="rejecting ? t('hrm.decision.noteRequired') : undefined"
            maxlength="1000"
            :error="form.errors.note"
            autofocus
        />
    </HrmFormDialog>
</template>
