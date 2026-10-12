<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Textarea from '@/Core/Components/Textarea.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';

/**
 * A dialog that posts one required note: sending a run back to its preparer,
 * or reversing an approved run. The note is read by the next person.
 */
const props = defineProps<{
    open: boolean;
    /** Where the note is posted. */
    url: string;
    title: string;
    description?: string;
    submitLabel: string;
    label: string;
    hint?: string;
    danger?: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ note: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
});

const unusable = computed(() => form.note.trim() === '');

function submit(): void {
    form.transform((data) => ({ note: data.note.trim() }))
        .post(props.url, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        size="sm"
        :title="title"
        :description="description"
        :submit-label="submitLabel"
        :danger="danger"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Textarea v-model="form.note" :label="label" :hint="hint" maxlength="1000" :error="form.errors.note" autofocus />
    </PayrollFormDialog>
</template>
