<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger, useTalentFormat } from './talentFormat';
import type { Goal } from './types';

/** Records the goal's current value. Reaching the target achieves the goal. */
const props = defineProps<{ open: boolean; goal: Goal }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const talent = useTalentFormat();

const form = useForm({ value: '', note: '' });
const value = computed(() => parseInteger(form.value, true));

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ value: String(props.goal.currentValue), note: '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ value: value.value, note: data.note.trim() === '' ? null : data.note.trim() }))
        .post(route('talent.goals.progress', { goal: props.goal.id }), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.goals.progressTitle')"
        :description="t('talent.goals.progressHint', { target: talent.value(goal.targetValue, goal.unit) })"
        :submit-label="t('talent.goals.recordProgress')"
        :processing="form.processing"
        :disabled="value === null"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.value" inputmode="numeric" :label="t('talent.goals.currentValue')" :error="form.errors.value" required autofocus />
        <Textarea v-model="form.note" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.note" />
    </TalentFormDialog>
</template>
