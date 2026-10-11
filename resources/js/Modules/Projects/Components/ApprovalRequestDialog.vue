<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { Member } from './types';

const props = defineProps<{
    open: boolean;
    projectId: string;
    entityType: 'task' | 'time_entry' | 'expense' | 'milestone';
    entityId: string;
    subject: string;
    members: Member[];
    currentMembershipId: string;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const approvers = computed(() => props.members.filter((member) => member.id !== props.currentMembershipId).map((member) => ({ value: member.id, label: member.name })));
const canSubmit = computed(() => approvers.value.length > 0);
const form = useForm({ approver_id: '', note: '' });
const entityError = computed(() => (form.errors as Record<string, string>).entity_id);

watch([() => props.open, () => props.entityId], ([open]) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ ...data, project_id: props.projectId, entity_type: props.entityType, entity_id: props.entityId }))
        .post(route('projects.approvals.store'), {
            preserveScroll: true,
            onSuccess: () => { model.value = false; form.reset(); },
        });
}
</script>

<template>
    <Modal v-model:open="model" :title="t('projects.approvals.request')" :description="subject" :persistent="form.processing">
        <form id="approval-request-form" class="grid gap-4" @submit.prevent="submit">
            <Select v-if="canSubmit" v-model="form.approver_id" :options="approvers" :label="t('projects.fields.approver')" :error="form.errors.approver_id" required />
            <p v-else class="text-sm text-ink-muted">{{ t('projects.approvals.noApprovers') }}</p>
            <Textarea v-model="form.note" :label="t('projects.approvals.requestNote')" :error="form.errors.note" optional maxlength="10000" />
            <p v-if="entityError" class="text-sm text-danger" role="alert">{{ entityError }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('projects.common.cancel') }}</Button>
            <Button type="submit" form="approval-request-form" variant="primary" :disabled="!canSubmit" :loading="form.processing">{{ t('projects.approvals.sendRequest') }}</Button>
        </template>
    </Modal>
</template>
