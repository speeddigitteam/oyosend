<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';
import type { Stage, StageType } from './types';

/**
 * Adds or edits a pipeline stage. A pipeline has at most one hired and one
 * rejected stage, and the type of a stage that holds applications cannot change.
 */
const props = defineProps<{ open: boolean; pipelineId: string; stage: Stage | null; types: StageType[]; nextPosition: number }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', type: 'active' as StageType, position: '' });

const typeOptions = computed(() => props.types.map((value) => ({ value, label: t(`talent.status.stageType.${value}`) })));
const position = computed(() => (form.position.trim() === '' ? null : parseInteger(form.position)));
const positionInvalid = computed(() => form.position.trim() !== '' && position.value === null);

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({
            name: props.stage?.name ?? '',
            type: props.stage?.type ?? 'active',
            position: String(props.stage?.position ?? props.nextPosition),
        });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ name: data.name.trim(), type: data.type, position: position.value }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.stage) {
        form.put(route('talent.settings.stages.update', { pipeline: props.pipelineId, stage: props.stage.id }), options);
    } else {
        form.post(route('talent.settings.stages.store', { pipeline: props.pipelineId }), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="stage ? t('talent.settings.editStage') : t('talent.settings.addStage')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || positionInvalid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('talent.fields.name')" maxlength="100" :error="form.errors.name" required autofocus />
        <Select v-model="form.type" :options="typeOptions" :label="t('talent.fields.type')" :hint="t('talent.settings.stageTypeHint')" :error="form.errors.type" />
        <Input v-model="form.position" inputmode="numeric" :label="t('talent.settings.position')" :hint="t('talent.settings.positionHint')" :error="form.errors.position" />
    </TalentFormDialog>
</template>
