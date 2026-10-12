<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { Designation } from './types';

const props = defineProps<{ open: boolean; designation: Designation | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', code: '', description: '', is_active: true });

watch(() => props.open, (open) => {
    if (open) {
        form.clearErrors();
        form.name = props.designation?.name ?? '';
        form.code = props.designation?.code ?? '';
        form.description = props.designation?.description ?? '';
        form.is_active = props.designation?.isActive ?? true;
    }
}, { immediate: true });

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const transformed = form.transform((data) => ({
        ...data,
        code: data.code.trim() === '' ? null : data.code.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
    }));

    if (props.designation) {
        transformed.put(route('hrm.settings.designations.update', { designation: props.designation.id }), options);
    } else {
        transformed.post(route('hrm.settings.designations.store'), options);
    }
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="designation ? t('hrm.settings.designations.edit') : t('hrm.settings.designations.add')"
        :submit-label="t('hrm.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
        <Input v-model="form.code" :label="t('hrm.fields.code')" optional maxlength="30" mono :error="form.errors.code" />
        <Textarea v-model="form.description" :label="t('hrm.fields.description')" optional maxlength="1000" :error="form.errors.description" />
        <Checkbox v-model="form.is_active" :label="t('hrm.fields.active')" />
    </HrmFormDialog>
</template>
