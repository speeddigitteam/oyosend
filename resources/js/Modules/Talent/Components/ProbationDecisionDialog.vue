<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { ProbationDecision } from './types';

/**
 * Records a probation decision. It is a recommendation: HR changes the
 * employee's status and probation dates in HRM; Talent does not.
 */
const props = defineProps<{ open: boolean; probationId: string; endsOn: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const DECISIONS: ProbationDecision[] = ['confirm', 'extend', 'end'];

const form = useForm({ decision: 'confirm' as ProbationDecision, extend_until: '', note: '' });
const options = computed(() => DECISIONS.map((value) => ({ value, label: t(`talent.probations.decision.${value}`) })));
const extending = computed(() => form.decision === 'extend');
const unusable = computed(() => extending.value && (form.extend_until === '' || form.extend_until <= props.endsOn));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        decision: data.decision,
        extend_until: data.decision === 'extend' ? data.extend_until : null,
        note: data.note.trim() === '' ? null : data.note.trim(),
    })).post(route('talent.probations.decisions.store', { probation: props.probationId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.probations.decideTitle')"
        :description="t('talent.probations.decideHint')"
        :submit-label="t('talent.probations.record')"
        :danger="form.decision === 'end'"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.decision" :options="options" :label="t('talent.probations.decisionLabel')" :error="form.errors.decision" />
        <Input
            v-if="extending"
            v-model="form.extend_until"
            type="date"
            :min="endsOn"
            :label="t('talent.probations.extendUntil')"
            :hint="t('talent.probations.extendHint')"
            :error="form.errors.extend_until"
            required
        />
        <Textarea v-model="form.note" :label="t('talent.fields.note')" maxlength="3000" optional :error="form.errors.note" />
    </TalentFormDialog>
</template>
