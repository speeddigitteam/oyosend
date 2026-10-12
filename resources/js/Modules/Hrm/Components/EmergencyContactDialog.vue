<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { EmergencyContact } from './types';

const props = defineProps<{ open: boolean; employeeId: string; contact: EmergencyContact | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', relationship: '', phone: '', email: '', address: '', is_primary: false });

watch(() => props.open, (open) => {
    if (open) {
        form.clearErrors();
        form.name = props.contact?.name ?? '';
        form.relationship = props.contact?.relationship ?? '';
        form.phone = props.contact?.phone ?? '';
        form.email = props.contact?.email ?? '';
        form.address = props.contact?.address ?? '';
        form.is_primary = props.contact?.isPrimary ?? false;
    }
}, { immediate: true });

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const transformed = form.transform((data) => ({
        ...data,
        email: data.email.trim() === '' ? null : data.email.trim(),
        address: data.address.trim() === '' ? null : data.address.trim(),
    }));

    if (props.contact) {
        transformed.put(route('hrm.employees.contacts.update', { employee: props.employeeId, contact: props.contact.id }), options);
    } else {
        transformed.post(route('hrm.employees.contacts.store', { employee: props.employeeId }), options);
    }
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="contact ? t('hrm.contacts.edit') : t('hrm.contacts.add')"
        :submit-label="t('hrm.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || form.relationship.trim() === '' || form.phone.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="160" :error="form.errors.name" required autofocus />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.relationship" :label="t('hrm.fields.relationship')" maxlength="80" :error="form.errors.relationship" required />
            <Input v-model="form.phone" type="tel" :label="t('hrm.fields.phone')" maxlength="40" :error="form.errors.phone" required />
        </div>
        <Input v-model="form.email" type="email" :label="t('hrm.fields.email')" optional maxlength="190" :error="form.errors.email" />
        <Input v-model="form.address" :label="t('hrm.fields.address')" optional maxlength="300" :error="form.errors.address" />
        <Checkbox v-model="form.is_primary" :label="t('hrm.contacts.primary')" />
    </HrmFormDialog>
</template>
