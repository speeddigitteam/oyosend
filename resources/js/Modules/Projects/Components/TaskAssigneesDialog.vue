<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, UsersRound } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Member, Task } from './types';

interface AssignmentRow {
    membership_id: string;
    allocation_percent: string;
}

const props = defineProps<{ open: boolean; projectId: string; task: Task; members: Member[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm<{ assignees: AssignmentRow[] }>({ assignees: [] });
const availableMembers = computed(() => {
    const members = new Map(props.members.map((member) => [member.id, member]));
    props.task.assignees.forEach((member) => members.set(member.id, member));
    return [...members.values()];
});
const canAdd = computed(() => form.assignees.length < Math.min(50, availableMembers.value.length));

watch(() => props.open, (open) => {
    if (!open) return;
    form.defaults({ assignees: props.task.assignees.map((member) => ({ membership_id: member.id, allocation_percent: String(member.allocationPercent ?? 100) })) });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function optionsFor(index: number): Array<{ value: string; label: string }> {
    const selectedElsewhere = new Set(form.assignees.filter((_, rowIndex) => rowIndex !== index).map((row) => row.membership_id));
    return availableMembers.value.filter((member) => !selectedElsewhere.has(member.id)).map((member) => ({ value: member.id, label: member.name }));
}

function add(): void {
    const selected = new Set(form.assignees.map((row) => row.membership_id));
    const member = availableMembers.value.find((candidate) => !selected.has(candidate.id));
    if (member) form.assignees.push({ membership_id: member.id, allocation_percent: '100' });
}

function remove(index: number): void {
    form.assignees.splice(index, 1);
    form.clearErrors();
}

function error(index: number, field: keyof AssignmentRow): string | undefined {
    return (form.errors as Record<string, string>)[`assignees.${index}.${field}`];
}

function submit(): void {
    form.put(route('projects.tasks.assign', { project: props.projectId, task: props.task.id }), {
        preserveScroll: true,
        onSuccess: () => { model.value = false; },
    });
}
</script>

<template>
    <Modal v-model:open="model" :title="t('projects.assignments.manage')" :description="task.title" :persistent="form.processing">
        <form id="task-assignees-form" class="grid gap-3" @submit.prevent="submit">
            <EmptyState v-if="form.assignees.length === 0" :title="t('projects.assignments.empty')" :description="t('projects.assignments.emptyBody')" compact><template #icon><UsersRound /></template></EmptyState>
            <div v-for="(assignment, index) in form.assignees" :key="`${assignment.membership_id}-${index}`" class="grid items-end gap-2 rounded-control border border-line p-3 sm:grid-cols-[minmax(0,1fr)_9rem_auto]">
                <Select v-model="assignment.membership_id" :options="optionsFor(index)" :label="t('projects.assignments.assigneeNumber', { number: index + 1 })" :error="error(index, 'membership_id')" required />
                <Input v-model="assignment.allocation_percent" type="number" min="0" max="100" step="1" :label="t('projects.assignments.allocationNumber', { number: index + 1 })" :error="error(index, 'allocation_percent')" required />
                <Button icon variant="ghost" :aria-label="t('projects.assignments.removeFor', { name: availableMembers.find((member) => member.id === assignment.membership_id)?.name ?? index + 1 })" @click="remove(index)"><Trash2 class="size-4" aria-hidden="true" /></Button>
            </div>
            <p v-if="form.errors.assignees" class="text-sm text-danger" role="alert">{{ form.errors.assignees }}</p>
            <Button v-if="canAdd" class="justify-self-start" size="sm" variant="ghost" @click="add"><Plus class="size-4" aria-hidden="true" />{{ t('projects.assignments.add') }}</Button>
        </form>
        <template #footer><Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('projects.common.cancel') }}</Button><Button type="submit" form="task-assignees-form" variant="primary" :loading="form.processing">{{ t('projects.common.save') }}</Button></template>
    </Modal>
</template>
