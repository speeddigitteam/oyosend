<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { Stage } from './types';

/** Reopens a rejected or withdrawn application, optionally into a chosen active stage. */
const props = defineProps<{ open: boolean; applicationId: string; stages: Stage[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ stage_id: '', note: '' });
const options = computed(() => [
    { value: '', label: t('talent.applications.firstStage') },
    ...props.stages.filter((stage) => stage.type === 'active').map((stage) => ({ value: stage.id, label: stage.name })),
]);

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        stage_id: data.stage_id === '' ? null : data.stage_id,
        note: data.note.trim() === '' ? null : data.note.trim(),
    })).post(route('talent.applications.reopen', { application: props.applicationId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.applications.reopenTitle')"
        :description="t('talent.applications.reopenHint')"
        :submit-label="t('talent.applications.reopen')"
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.stage_id" :options="options" :label="t('talent.fields.stage')" :error="form.errors.stage_id" />
        <Textarea v-model="form.note" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.note" />
    </TalentFormDialog>
</template>
