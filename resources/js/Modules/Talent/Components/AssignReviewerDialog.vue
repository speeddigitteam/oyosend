<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { PersonOption } from './types';

/** Changes who writes the manager step of a review. The subject of the review cannot be their own reviewer. */
const props = defineProps<{ open: boolean; reviewId: string; reviewerId: string | null; subjectId: string | null; employees: PersonOption[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ reviewer_employee_id: '' });
const options = computed(() => [
    { value: '', label: t('talent.reviews.noReviewer') },
    ...props.employees.filter((person) => person.id !== props.subjectId).map((person) => ({ value: person.id, label: `${person.name} (${person.number})` })),
]);

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ reviewer_employee_id: props.reviewerId ?? '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ reviewer_employee_id: data.reviewer_employee_id === '' ? null : data.reviewer_employee_id }))
        .post(route('talent.reviews.reviewer', { review: props.reviewId }), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.reviews.assignTitle')"
        :description="t('talent.reviews.assignHint')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.reviewer_employee_id" :options="options" :label="t('talent.fields.reviewer')" :error="form.errors.reviewer_employee_id" />
    </TalentFormDialog>
</template>
