<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Option } from './types';
const props = defineProps<{ open: boolean; projectId: string; contacts: Option[]; companies: Option[]; canManageFinancials: boolean }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({ contact_id: '', company_id: '', can_comment: true, can_view_financials: false, expires_at: '' });
watch(() => props.open, (open) => { if (open) { form.reset(); form.clearErrors(); } }, { immediate: true });
function submit(): void {
    if (!props.canManageFinancials) form.can_view_financials = false;
    form.post(route('projects.client-access.store', { project: props.projectId }), { preserveScroll: true, onSuccess: () => { model.value = false; form.reset(); } });
}
</script>
<template><Modal v-model:open="model" :title="t('projects.portal.share')" :description="t('projects.portal.shareBody')" :persistent="form.processing"><form id="client-access-form" class="grid gap-4" @submit.prevent="submit"><p v-if="contacts.length === 0" class="text-sm text-ink-muted" role="status">{{ t('projects.portal.noContacts') }}</p><Select v-else v-model="form.contact_id" :options="contacts.map((item) => ({ value: item.id, label: item.name }))" :label="t('projects.fields.contact')" :error="form.errors.contact_id" required /><Select v-model="form.company_id" :options="[{ value: '', label: t('projects.common.notSet') }, ...companies.map((item) => ({ value: item.id, label: item.name }))]" :label="t('projects.fields.company')" :error="form.errors.company_id" /><Input v-model="form.expires_at" type="datetime-local" :label="t('projects.portal.expires')" :error="form.errors.expires_at" optional /><Checkbox v-model="form.can_comment" :label="t('projects.portal.allowComments')" /><Checkbox v-if="canManageFinancials" v-model="form.can_view_financials" :label="t('projects.portal.allowFinancials')" /></form><template #footer><Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('projects.common.cancel') }}</Button><Button type="submit" form="client-access-form" variant="primary" :disabled="contacts.length === 0" :loading="form.processing">{{ t('projects.portal.createLink') }}</Button></template></Modal></template>
