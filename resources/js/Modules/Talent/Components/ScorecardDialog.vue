<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { Recommendation } from './types';

/**
 * A panelist's scorecard: a rating from 1 to 5, a recommendation, strengths
 * and concerns. Feedback is blind until the panelist has submitted their own.
 */
const props = defineProps<{ open: boolean; interviewId: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();

const RECOMMENDATIONS: Recommendation[] = ['strong_no', 'no', 'yes', 'strong_yes'];

const form = useForm({ rating: '3', recommendation: 'yes' as Recommendation, strengths: '', concerns: '' });

const ratings = computed(() => ['1', '2', '3', '4', '5'].map((value) => ({ value, label: format.number(Number(value)) })));
const recommendations = computed(() => RECOMMENDATIONS.map((value) => ({ value, label: t(`talent.status.recommendation.${value}`) })));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        rating: Number(data.rating),
        recommendation: data.recommendation,
        strengths: data.strengths.trim() === '' ? null : data.strengths.trim(),
        concerns: data.concerns.trim() === '' ? null : data.concerns.trim(),
    })).post(route('talent.interviews.scorecard', { interview: props.interviewId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="t('talent.interviews.scorecardTitle')"
        :description="t('talent.interviews.blindNote')"
        :submit-label="t('talent.interviews.submitScorecard')"
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div>
            <p class="eyebrow mb-2">{{ t('talent.interviews.rating') }}</p>
            <SegmentedControl v-model="form.rating" :options="ratings" :label="t('talent.interviews.rating')" />
            <p class="mt-1 text-xs text-ink-subtle">{{ t('talent.interviews.ratingHint') }}</p>
            <p v-if="form.errors.rating" class="mt-1 text-xs text-danger" role="alert">{{ form.errors.rating }}</p>
        </div>
        <Select v-model="form.recommendation" :options="recommendations" :label="t('talent.interviews.recommendation')" :error="form.errors.recommendation" />
        <Textarea v-model="form.strengths" :label="t('talent.interviews.strengths')" maxlength="3000" optional :error="form.errors.strengths" />
        <Textarea v-model="form.concerns" :label="t('talent.interviews.concerns')" maxlength="3000" optional :error="form.errors.concerns" />
    </TalentFormDialog>
</template>
