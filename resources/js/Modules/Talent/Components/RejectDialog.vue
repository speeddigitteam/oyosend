<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { NamedRef } from './types';

/** Rejects an application with an optional reason from the workspace list and a note. */
const props = defineProps<{ open: boolean; applicationId: string; reasons: NamedRef[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ reason_id: '', note: '' });
const options = computed(() => [{ value: '', label: t('talent.applications.noReason') }, ...props.reasons.map((reason) => ({ value: reason.id, label: reason.name }))]);

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        reason_id: data.reason_id === '' ? null : data.reason_id,
        note: data.note.trim() === '' ? null : data.note.trim(),
    })).post(route('talent.applications.reject', { application: props.applicationId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.applications.rejectTitle')"
        :description="t('talent.applications.rejectHint')"
        :submit-label="t('talent.applications.reject')"
        danger
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.reason_id" :options="options" :label="t('talent.fields.reason')" optional :error="form.errors.reason_id" />
        <Textarea v-model="form.note" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.note" />
    </TalentFormDialog>
</template>
