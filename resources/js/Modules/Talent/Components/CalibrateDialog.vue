<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { scoreToHundredths, useTalentFormat } from './talentFormat';

/**
 * Locks a review with its final score. By default the final score is the
 * manager's; overriding it needs a score inside the scale and a note, and the
 * review cannot be changed afterwards.
 */
const props = defineProps<{ open: boolean; reviewId: string; managerScore: number | null; scaleMax: number }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const talent = useTalentFormat();

const form = useForm({ override: false, final_score: '', note: '' });

const hundredths = computed(() => (form.final_score.trim() === '' ? null : scoreToHundredths(form.final_score)));
const scoreInvalid = computed(() => form.override && (hundredths.value === null || hundredths.value < 100 || hundredths.value > props.scaleMax * 100));
const unusable = computed(() => form.override && (scoreInvalid.value || form.note.trim() === ''));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        final_score: data.override ? hundredths.value : null,
        note: data.note.trim() === '' ? null : data.note.trim(),
    })).post(route('talent.reviews.calibrate', { review: props.reviewId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.reviews.calibrateTitle')"
        :description="t('talent.reviews.calibrateHint')"
        :submit-label="t('talent.reviews.calibrateSubmit')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <p class="text-sm text-ink">{{ t('talent.reviews.managerScoreIs', { score: talent.score(managerScore) }) }}</p>
        <Checkbox v-model="form.override" :label="t('talent.reviews.override')" :description="t('talent.reviews.overrideHint')" />
        <Input
            v-if="form.override"
            v-model="form.final_score"
            inputmode="decimal"
            :label="t('talent.reviews.finalScore')"
            :hint="t('talent.reviews.finalScoreHint', { max: scaleMax })"
            :error="form.errors.final_score ?? (form.final_score !== '' && scoreInvalid ? t('talent.reviews.finalScoreInvalid') : undefined)"
            required
        />
        <Textarea
            v-model="form.note"
            :label="t('talent.reviews.calibrationNote')"
            :optional="!form.override"
            :hint="form.override ? t('talent.reviews.noteRequired') : undefined"
            maxlength="3000"
            :error="form.errors.note"
        />
    </TalentFormDialog>
</template>
