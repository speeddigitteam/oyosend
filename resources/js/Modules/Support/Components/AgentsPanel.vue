<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Users } from 'lucide-vue-next';
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
import type { AgentRow, CandidateRow } from './types';

const props = defineProps<{
    agents: AgentRow[];
    candidates: CandidateRow[];
    limit: { limit: number | null; used: number };
}>();

const { t } = useI18n();
const format = useFormat();

const open = ref(false);
const editing = ref<AgentRow | null>(null);
const form = useForm({ membership_id: '', is_active: true, is_available: true, max_open_tickets: '' });

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('support.queues.agent') },
    { key: 'state', label: t('support.fields.status') },
    { key: 'capacity', label: t('support.queues.capacity'), align: 'right' },
    { key: 'actions', label: t('support.common.actions'), srOnly: true, align: 'right' },
]);
const candidateOptions = computed(() => [
    { value: '', label: t('support.queues.chooseMember') },
    ...props.candidates.map((candidate) => ({ value: candidate.id, label: `${candidate.name} (${candidate.email})` })),
]);
const atLimit = computed(() => props.limit.limit !== null && props.limit.used >= props.limit.limit);

function openForm(agent: AgentRow | null): void {
    editing.value = agent;
    form.defaults({
        membership_id: '',
        is_active: agent?.isActive ?? true,
        is_available: agent?.isAvailable ?? true,
        max_open_tickets: agent?.maxOpenTickets === null || agent === null ? '' : String(agent.maxOpenTickets),
    }).reset();
    form.clearErrors();
    open.value = true;
}

function save(): void {
    form.transform((data) => ({
        ...data,
        max_open_tickets: data.max_open_tickets === '' ? null : Number(data.max_open_tickets),
        ...(editing.value ? { membership_id: undefined } : {}),
    }));
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        form.put(route('support.agents.update', { agent: editing.value.id }), options);
    } else {
        form.post(route('support.agents.store'), options);
    }
}

const limitError = computed(() => Object.entries(form.errors).find(([key]) => !['membership_id', 'is_active', 'is_available', 'max_open_tickets'].includes(key))?.[1]);
</script>

<template>
    <Card :title="t('support.queues.agents')" :description="limit.limit === null ? undefined : t('support.queues.agentLimit', { used: format.number(limit.used), limit: format.number(limit.limit) })" flush>
        <template #actions>
            <Button size="sm" variant="primary" :disabled="candidates.length === 0 || atLimit" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.queues.enrol') }}
            </Button>
        </template>
        <DataTable :columns="columns" :rows="agents" row-key="id" :caption="t('support.queues.agents')">
            <template #cell-name="{ row }">
                <div>
                    <p class="font-medium text-ink">{{ row.name }}</p>
                    <p class="text-xs text-ink-subtle wrap-anywhere">{{ row.email }}</p>
                </div>
            </template>
            <template #cell-state="{ row }">
                <span class="flex flex-wrap gap-1">
                    <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ t(row.isActive ? 'support.common.active' : 'support.common.inactive') }}</Badge>
                    <Badge v-if="row.isActive" :tone="row.isAvailable ? 'info' : 'warn'">{{ t(row.isAvailable ? 'support.queues.available' : 'support.queues.away') }}</Badge>
                </span>
            </template>
            <template #cell-capacity="{ row }">
                <span class="tabular text-ink-muted">
                    {{ row.maxOpenTickets === null ? t('support.queues.openCount', { count: format.number(row.openTickets) }) : t('support.queues.openOfMax', { count: format.number(row.openTickets), max: format.number(row.maxOpenTickets) }) }}
                </span>
            </template>
            <template #cell-actions="{ row }">
                <Button icon size="sm" variant="ghost" :aria-label="t('support.queues.editFor', { name: row.name })" @click="openForm(row)">
                    <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                </Button>
            </template>
            <template #empty>
                <EmptyState :title="t('support.queues.noAgents')" :description="t('support.queues.noAgentsBody')" compact>
                    <template #icon><Users /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Modal v-model:open="open" size="sm" :title="t(editing ? 'support.queues.editAgent' : 'support.queues.enrol')" :persistent="form.processing">
        <form id="support-agent-form" class="grid gap-4" @submit.prevent="save">
            <Select v-if="!editing" v-model="form.membership_id" :options="candidateOptions" :label="t('support.queues.member')" :error="form.errors.membership_id" />
            <p v-else class="text-sm font-medium text-ink">{{ editing.name }}</p>
            <Checkbox v-model="form.is_active" :label="t('support.queues.agentActive')" :description="t('support.queues.agentActiveHint')" />
            <Checkbox v-model="form.is_available" :label="t('support.queues.agentAvailable')" :description="t('support.queues.agentAvailableHint')" />
            <Input v-model="form.max_open_tickets" type="number" min="1" max="500" :label="t('support.queues.maxOpen')" :hint="t('support.queues.maxOpenHint')" :error="form.errors.max_open_tickets" optional />
            <p v-if="limitError" class="text-sm text-danger" role="alert">{{ limitError }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-agent-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>
</template>
