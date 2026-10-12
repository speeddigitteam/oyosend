<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Textarea from '@/Core/Components/Textarea.vue';
import TalentFormDialog from './TalentFormDialog.vue';

/**
 * Posts one optional (or required) free-text field: withdraw an application,
 * cancel an interview, skip a task. `field` is the name the server expects.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        url: string;
        title: string;
        description?: string;
        submitLabel: string;
        label?: string;
        field?: 'note' | 'notes' | 'comment' | 'body';
        required?: boolean;
        danger?: boolean;
        maxlength?: number;
    }>(),
    { description: undefined, label: undefined, field: 'note', required: false, danger: false, maxlength: 2000 },
);

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ value: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

const errorText = computed(() => (form.errors as Record<string, string | undefined>)[props.field] ?? form.errors.value);
const unusable = computed(() => props.required && form.value.trim() === '');

function submit(): void {
    form.transform((data) => ({ [props.field]: data.value.trim() === '' ? null : data.value.trim() }))
        .post(props.url, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <TalentFormDialog
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
        <Textarea
            v-model="form.value"
            :label="label ?? t('talent.fields.note')"
            :optional="!required"
            :maxlength="maxlength"
            :error="errorText"
            autofocus
        />
    </TalentFormDialog>
</template>
