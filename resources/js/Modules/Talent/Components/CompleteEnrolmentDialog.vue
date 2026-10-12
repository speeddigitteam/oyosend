<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';

/** Completes an enrolment: the date (not in the future), an optional score from 0 to 100 and notes. */
const props = defineProps<{ open: boolean; enrolmentId: string; today: string; validityMonths: number | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ completed_on: '', score: '', notes: '' });

const score = computed(() => (form.score.trim() === '' ? null : parseInteger(form.score)));
const scoreInvalid = computed(() => form.score.trim() !== '' && (score.value === null || score.value > 100));

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ completed_on: props.today, score: '', notes: '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        completed_on: data.completed_on === '' ? null : data.completed_on,
        score: score.value,
        notes: data.notes.trim() === '' ? null : data.notes.trim(),
    })).post(route('talent.enrolments.complete', { enrolment: props.enrolmentId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.enrolments.completeTitle')"
        :description="validityMonths === null ? undefined : t('talent.enrolments.completeCertHint', { months: validityMonths })"
        :submit-label="t('talent.enrolments.complete')"
        :processing="form.processing"
        :disabled="scoreInvalid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.completed_on" type="date" :max="today" :label="t('talent.enrolments.completedOn')" :error="form.errors.completed_on" />
        <Input
            v-model="form.score"
            inputmode="numeric"
            :label="t('talent.enrolments.score')"
            :hint="t('talent.enrolments.scoreHint')"
            optional
            :error="form.errors.score ?? (scoreInvalid ? t('talent.common.invalidNumber') : undefined)"
        />
        <Textarea v-model="form.notes" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.notes" />
    </TalentFormDialog>
</template>
