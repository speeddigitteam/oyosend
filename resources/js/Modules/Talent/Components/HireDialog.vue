<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';

/**
 * Confirms the hire. The employee record is created in HR by a person with HR
 * rights from the data the hire announces; Talent does not create it.
 */
const props = defineProps<{ open: boolean; applicationId: string; proposedStartOn: string | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ start_on: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ start_on: props.proposedStartOn ?? '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ start_on: data.start_on === '' ? null : data.start_on }))
        .post(route('talent.applications.hire', { application: props.applicationId }), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.applications.hireTitle')"
        :description="t('talent.applications.hireHint')"
        :submit-label="t('talent.applications.hire')"
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.start_on" type="date" :label="t('talent.fields.startOn')" optional :error="form.errors.start_on" />
    </TalentFormDialog>
</template>
