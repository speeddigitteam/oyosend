<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Inbox, Pencil, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { AgentRow, AssignmentMode, Option, QueueRow } from './types';

const props = defineProps<{
    queues: QueueRow[];
    agents: AgentRow[];
    departments: Option[];
    policies: { id: string; name: string; isActive: boolean }[];
    modes: AssignmentMode[];
}>();

const { t } = useI18n();
const format = useFormat();

const open = ref(false);
const editing = ref<QueueRow | null>(null);
const form = useForm({
    name: '',
    description: '',
    department_id: '',
    sla_policy_id: '',
    assignment_mode: 'manual' as string,
    is_active: true,
    agent_ids: [] as string[],
});

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('support.fields.name') },
    { key: 'mode', label: t('support.queues.mode') },
    { key: 'agents', label: t('support.queues.agents'), align: 'right' },
    { key: 'actions', label: t('support.common.actions'), srOnly: true, align: 'right' },
]);
const modeOptions = computed(() => props.modes.map((value) => ({ value, label: t(`support.assignmentMode.${value}`) })));
const departmentOptions = computed(() => [{ value: '', label: t('support.common.notSet') }, ...props.departments.map((item) => ({ value: item.id, label: item.name }))]);
const policyOptions = computed(() => [
    { value: '', label: t('support.queues.workspaceDefault') },
    ...props.policies.filter((policy) => policy.isActive).map((policy) => ({ value: policy.id, label: policy.name })),
]);

function openForm(queue: QueueRow | null): void {
    editing.value = queue;
    form.defaults({
        name: queue?.name ?? '',
        description: queue?.description ?? '',
        department_id: queue?.departmentId ?? '',
        sla_policy_id: queue?.slaPolicyId ?? '',
        assignment_mode: queue?.assignmentMode ?? 'manual',
        is_active: queue?.isActive ?? true,
        agent_ids: [...(queue?.agentIds ?? [])],
    }).reset();
    form.clearErrors();
    open.value = true;
}

function toggleAgent(id: string, on: boolean): void {
    form.agent_ids = on ? [...form.agent_ids, id] : form.agent_ids.filter((value) => value !== id);
}

function save(): void {
    form.transform((data) => ({
        ...data,
        description: data.description === '' ? null : data.description,
        department_id: data.department_id === '' ? null : data.department_id,
        sla_policy_id: data.sla_policy_id === '' ? null : data.sla_policy_id,
    }));
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        form.put(route('support.queues.update', { queue: editing.value.id }), options);
    } else {
        form.post(route('support.queues.store'), options);
    }
}
</script>

<template>
    <Card :title="t('support.queues.queues')" :description="t('support.queues.queuesHint')" flush>
        <template #actions>
            <Button size="sm" variant="primary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.queues.addQueue') }}
            </Button>
        </template>
        <DataTable :columns="columns" :rows="queues" row-key="id" :caption="t('support.queues.queues')">
            <template #cell-name="{ row }">
                <div>
                    <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                        {{ row.name }}
                        <Badge v-if="!row.isActive" tone="neutral">{{ t('support.common.inactive') }}</Badge>
                    </p>
                    <p v-if="row.description" class="text-xs text-ink-subtle wrap-anywhere">{{ row.description }}</p>
                </div>
            </template>
            <template #cell-mode="{ row }">{{ t(`support.assignmentMode.${row.assignmentMode}`) }}</template>
            <template #cell-agents="{ row }"><span class="tabular">{{ format.number(row.agentIds.length) }}</span></template>
            <template #cell-actions="{ row }">
                <Button icon size="sm" variant="ghost" :aria-label="t('support.queues.editFor', { name: row.name })" @click="openForm(row)">
                    <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                </Button>
            </template>
            <template #empty>
                <EmptyState :title="t('support.queues.noQueues')" :description="t('support.queues.noQueuesBody')" compact>
                    <template #icon><Inbox /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Modal v-model:open="open" size="lg" :title="t(editing ? 'support.queues.editQueue' : 'support.queues.addQueue')" :persistent="form.processing">
        <form id="support-queue-form" class="grid gap-4" @submit.prevent="save">
            <Input v-model="form.name" :label="t('support.fields.name')" :error="form.errors.name" required maxlength="120" />
            <Input v-model="form.description" :label="t('support.fields.description')" :error="form.errors.description" optional maxlength="300" />
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.assignment_mode" :options="modeOptions" :label="t('support.queues.mode')" :error="form.errors.assignment_mode" />
                <Select v-model="form.department_id" :options="departmentOptions" :label="t('support.queues.department')" :error="form.errors.department_id" />
                <Select v-model="form.sla_policy_id" :options="policyOptions" :label="t('support.queues.policy')" :error="form.errors.sla_policy_id" />
            </div>
            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium text-ink">{{ t('support.queues.members') }}</legend>
                <p v-if="agents.length === 0" class="text-sm text-ink-muted">{{ t('support.queues.noAgentsBody') }}</p>
                <Checkbox
                    v-for="agent in agents"
                    :key="agent.id"
                    :model-value="form.agent_ids.includes(agent.id)"
                    :label="agent.name"
                    :description="agent.isActive ? undefined : t('support.common.inactive')"
                    @update:model-value="toggleAgent(agent.id, $event)"
                />
                <p v-if="form.errors.agent_ids" class="text-sm text-danger" role="alert">{{ form.errors.agent_ids }}</p>
            </fieldset>
            <Checkbox v-model="form.is_active" :label="t('support.queues.queueActive')" :description="t('support.queues.queueActiveHint')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-queue-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>
</template>
