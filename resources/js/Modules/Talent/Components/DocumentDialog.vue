<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import type { DocumentType } from './types';

/** Uploads a private candidate document (10 MB; PDF, Word, images, text). */
const props = defineProps<{ open: boolean; candidateId: string; documentTypes: DocumentType[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm<{ file: File | null; type: DocumentType; title: string }>({ file: null, type: 'resume', title: '' });
const typeOptions = computed(() => props.documentTypes.map((value) => ({ value, label: t(`talent.status.document.${value}`) })));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function choose(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.file = file;

    if (file && form.title.trim() === '') {
        form.title = file.name.replace(/\.[^.]+$/, '');
    }
}

function submit(): void {
    form.post(route('talent.candidates.documents.store', { candidate: props.candidateId }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="t('talent.documents.upload')"
        :description="t('talent.documents.uploadHint')"
        :submit-label="t('talent.documents.uploadSubmit')"
        :processing="form.processing"
        :disabled="form.file === null || form.title.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input type="file" :label="t('talent.documents.file')" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.txt" :error="form.errors.file" @change="choose" />
        <Select v-model="form.type" :label="t('talent.fields.type')" :options="typeOptions" :error="form.errors.type" />
        <Input v-model="form.title" :label="t('talent.fields.title')" maxlength="160" :error="form.errors.title" required />
    </TalentFormDialog>
</template>
