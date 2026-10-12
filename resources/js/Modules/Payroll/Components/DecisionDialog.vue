<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Textarea from '@/Core/Components/Textarea.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';

/**
 * Approve or reject a loan with a note. Rejecting needs a note, which the
 * person who asked will read.
 */
const props = defineProps<{
    open: boolean;
    url: string;
    decision: 'approve' | 'reject';
    title: string;
    description?: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();
const form = useForm({ note: '' });

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
    <PayrollFormDialog
        :open="open"
        size="sm"
        :title="title"
        :description="description"
        :submit-label="rejecting ? t('payroll.loans.reject') : t('payroll.loans.approve')"
        :danger="rejecting"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Textarea
            v-model="form.note"
            :label="t('payroll.fields.note')"
            :optional="!rejecting"
            :hint="rejecting ? t('payroll.loans.noteRequired') : undefined"
            maxlength="1000"
            :error="form.errors.note"
            autofocus
        />
    </PayrollFormDialog>
</template>
