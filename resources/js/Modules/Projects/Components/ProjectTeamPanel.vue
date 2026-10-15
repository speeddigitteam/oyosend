<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Member } from './types';

export interface ProjectMember extends Member {
    membershipId: string;
    role: 'manager' | 'member' | 'viewer';
    allocationPercent: number;
    removable: boolean;
}

const props = defineProps<{ projectId: string; members: ProjectMember[]; options: Member[]; canManage: boolean }>();
const { t } = useI18n();
const open = ref(false);
const editing = ref<ProjectMember | null>(null);
const removing = ref<ProjectMember | null>(null);
const deleting = ref(false);
const removeError = ref('');
const form = useForm({ membership_id: '', role: 'member', allocation_percent: '100' });
const memberOptions = computed(() => props.options.map((member) => ({ value: member.id, label: member.name })));
const roleOptions = computed(() => ['manager', 'member', 'viewer'].map((role) => ({ value: role, label: t(`projects.members.roles.${role}`) })));

function submit(): void {
    form.post(route('projects.members.store', { project: props.projectId }), {
        preserveScroll: true,
        onSuccess: () => { open.value = false; editing.value = null; form.defaults({ membership_id: '', role: 'member', allocation_percent: '100' }); form.reset(); },
    });
}

function create(): void {
    editing.value = null;
    form.defaults({ membership_id: '', role: 'member', allocation_percent: '100' });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(member: ProjectMember): void {
    editing.value = member;
    form.defaults({ membership_id: member.membershipId, role: member.role, allocation_percent: String(member.allocationPercent) });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function remove(): void {
    if (!removing.value || deleting.value) return;
    deleting.value = true;
    removeError.value = '';
    router.delete(route('projects.members.destroy', { project: props.projectId, membership: removing.value.membershipId }), {
        preserveScroll: true,
        onSuccess: () => { removing.value = null; },
        onError: (errors) => { removeError.value = errors.membership_id ?? errors.status ?? Object.values(errors)[0] ?? ''; },
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <Card :title="t('projects.members.title')" flush>
        <template v-if="canManage" #actions><Button size="sm" variant="ghost" @click="create"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.members.add') }}</Button></template>
        <EmptyState v-if="members.length === 0" :title="t('projects.members.empty')" compact><template #icon><UsersRound /></template></EmptyState>
        <ul v-else class="divide-y divide-line">
            <li v-for="member in members" :key="member.membershipId" class="flex items-center justify-between gap-3 px-5 py-3">
                <div class="min-w-0"><p class="truncate text-sm font-medium text-ink">{{ member.name }}</p><p class="text-xs text-ink-subtle">{{ t(`projects.members.roles.${member.role}`) }} · {{ t('projects.members.allocation', { percent: member.allocationPercent }) }}</p></div>
                <div v-if="canManage" class="flex gap-1"><Button icon size="sm" variant="ghost" :aria-label="t('projects.members.editFor', { name: member.name })" @click="edit(member)"><template #icon><Pencil class="size-4" aria-hidden="true" /></template></Button><Button v-if="member.removable" icon size="sm" variant="ghost" :aria-label="t('projects.members.removeFor', { name: member.name })" @click="removeError = ''; removing = member"><template #icon><Trash2 class="size-4" aria-hidden="true" /></template></Button></div>
            </li>
        </ul>
    </Card>
    <Modal v-model:open="open" :title="t(editing ? 'projects.members.edit' : 'projects.members.add')" :persistent="form.processing">
        <form id="project-member-form" class="grid gap-4" @submit.prevent="submit">
            <Select v-model="form.membership_id" :options="memberOptions" :label="t('projects.fields.member')" :error="form.errors.membership_id" :disabled="editing !== null" required />
            <Select v-model="form.role" :options="roleOptions" :label="t('projects.fields.role')" :error="form.errors.role" required />
            <Input v-model="form.allocation_percent" type="number" min="0" max="100" step="1" :label="t('projects.fields.allocationPercent')" :error="form.errors.allocation_percent" required />
        </form>
        <template #footer><Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('projects.common.cancel') }}</Button><Button type="submit" form="project-member-form" variant="primary" :loading="form.processing">{{ t('projects.common.save') }}</Button></template>
    </Modal>
    <ConfirmDialog :open="removing !== null" :title="t('projects.members.remove')" :description="removing ? t('projects.members.removeBody', { name: removing.name }) : undefined" :confirm-label="t('projects.members.remove')" :loading="deleting" @update:open="!$event && !deleting && (removing = null)" @confirm="remove"><p v-if="removeError" class="text-sm text-danger" role="alert">{{ removeError }}</p></ConfirmDialog>
</template>
