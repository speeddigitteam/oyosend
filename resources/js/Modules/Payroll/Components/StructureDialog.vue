<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import PayrollFormDialog from './PayrollFormDialog.vue';
import type { Structure } from './types';

/** Create or edit a salary structure (a named set of recurring components). */
const props = defineProps<{ open: boolean; structure: Structure | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({ name: '', code: '', description: '', is_active: true });
const unusable = computed(() => form.name.trim() === '');

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    form.defaults({
        name: props.structure?.name ?? '',
        code: props.structure?.code ?? '',
        description: props.structure?.description ?? '',
        is_active: props.structure?.isActive ?? true,
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        code: data.code.trim() === '' ? null : data.code.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
        is_active: data.is_active,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.structure === null) {
        form.post(route('payroll.structures.store'), options);
    } else {
        form.put(route('payroll.structures.update', { structure: props.structure.id }), options);
    }
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        :title="structure === null ? t('payroll.structures.create') : t('payroll.structures.edit')"
        :submit-label="structure === null ? t('payroll.structures.create') : t('payroll.common.saveChanges')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('payroll.fields.name')" :error="form.errors.name" maxlength="120" required autofocus />
        <Input v-model="form.code" :label="t('payroll.fields.code')" :error="form.errors.code" maxlength="30" mono optional />
        <Textarea v-model="form.description" :label="t('payroll.fields.description')" :error="form.errors.description" maxlength="2000" optional />
        <Checkbox v-model="form.is_active" :label="t('payroll.fields.active')" :description="t('payroll.structures.activeHint')" />
    </PayrollFormDialog>
</template>
