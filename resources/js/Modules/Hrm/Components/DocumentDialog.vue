<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { DocumentType } from './types';

/** Uploads a private document to an employee record (10 MB; PDF, Word, images, text). */
const props = defineProps<{ open: boolean; employeeId: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const TYPES: DocumentType[] = ['contract', 'identity', 'certificate', 'offer', 'other'];

const form = useForm<{ file: File | null; type: DocumentType; title: string; expires_on: string }>({
    file: null,
    type: 'contract',
    title: '',
    expires_on: '',
});

const typeOptions = computed(() => TYPES.map((value) => ({ value, label: t(`hrm.documents.types.${value}`) })));

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
    form.transform((data) => ({ ...data, expires_on: data.expires_on === '' ? null : data.expires_on }))
        .post(route('hrm.employees.documents.store', { employee: props.employeeId }), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="t('hrm.documents.upload')"
        :description="t('hrm.documents.uploadHint')"
        :submit-label="t('hrm.documents.uploadSubmit')"
        :processing="form.processing"
        :disabled="form.file === null || form.title.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input type="file" :label="t('hrm.documents.file')" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.txt" :error="form.errors.file" @change="choose" />
        <Select v-model="form.type" :label="t('hrm.fields.type')" :options="typeOptions" :error="form.errors.type" />
        <Input v-model="form.title" :label="t('hrm.fields.title')" maxlength="160" :error="form.errors.title" required />
        <Input v-model="form.expires_on" type="date" :label="t('hrm.documents.expires')" optional :error="form.errors.expires_on" />
    </HrmFormDialog>
</template>
