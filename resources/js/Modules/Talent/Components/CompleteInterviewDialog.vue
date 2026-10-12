<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { InterviewOutcome } from './types';

/** Completes an interview with its outcome. Scorecards are locked once it is completed. */
const props = defineProps<{ open: boolean; interviewId: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const OUTCOMES: InterviewOutcome[] = ['advance', 'hold', 'reject'];

const form = useForm({ outcome: 'advance' as InterviewOutcome, notes: '' });
const options = computed(() => OUTCOMES.map((value) => ({ value, label: t(`talent.status.outcome.${value}`) })));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ outcome: data.outcome, notes: data.notes.trim() === '' ? null : data.notes.trim() }))
        .post(route('talent.interviews.complete', { interview: props.interviewId }), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.interviews.completeTitle')"
        :description="t('talent.interviews.completeHint')"
        :submit-label="t('talent.interviews.complete')"
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.outcome" :options="options" :label="t('talent.interviews.outcome')" :error="form.errors.outcome" />
        <Textarea v-model="form.notes" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.notes" />
    </TalentFormDialog>
</template>
