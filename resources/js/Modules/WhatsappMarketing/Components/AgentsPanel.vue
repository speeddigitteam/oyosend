<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { UserPlus, UsersRound } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

export interface AgentRow {
    id: string;
    membershipId: string;
    name: string;
    isActive: boolean;
    isAvailable: boolean;
    maxOpenConversations: number | null;
}

/**
 * The roster of people who work WhatsApp conversations. An agent is a workspace member who is enrolled here;
 * `limits.agents` caps the active ones.
 */
const props = defineProps<{
    agents: AgentRow[];
    members: { id: string; name: string }[];
    limit: { used: number; limit: number | null };
}>();

const { t } = useI18n();
const format = useFormat();

const drafts = reactive<Record<string, { is_active: boolean; is_available: boolean; max: string }>>({});
const saving = reactive<Record<string, boolean>>({});
const rowErrors = reactive<Record<string, string>>({});

watch(
    () => props.agents,
    (agents) => {
        for (const agent of agents) {
            drafts[agent.id] = { is_active: agent.isActive, is_available: agent.isAvailable, max: agent.maxOpenConversations === null ? '' : String(agent.maxOpenConversations) };
        }
    },
    { immediate: true },
);

function dirty(agent: AgentRow): boolean {
    const draft = drafts[agent.id];

    return (
        draft !== undefined &&
        (draft.is_active !== agent.isActive || draft.is_available !== agent.isAvailable || draft.max !== (agent.maxOpenConversations === null ? '' : String(agent.maxOpenConversations)))
    );
}

function save(agent: AgentRow): void {
    const draft = drafts[agent.id];

    if (!draft) {
        return;
    }

    saving[agent.id] = true;
    rowErrors[agent.id] = '';
    router.put(
        route('whatsapp_marketing.agents.update', { agent: agent.id }),
        { is_active: draft.is_active, is_available: draft.is_available, max_open_conversations: draft.max.trim() === '' ? null : Number(draft.max) },
        {
            preserveScroll: true,
            onError: (errors) => {
                rowErrors[agent.id] = Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                saving[agent.id] = false;
            },
        },
    );
}

const enrol = useForm({ membership_id: '', max_open_conversations: '', is_available: true });
const memberOptions = computed(() => props.members.map((member) => ({ value: member.id, label: member.name })));
const atLimit = computed(() => props.limit.limit !== null && props.limit.used >= props.limit.limit);

function submitEnrol(): void {
    enrol
        .transform((data) => ({
            membership_id: data.membership_id,
            is_available: data.is_available,
            max_open_conversations: data.max_open_conversations.trim() === '' ? null : Number(data.max_open_conversations),
        }))
        .post(route('whatsapp_marketing.agents.store'), { preserveScroll: true, onSuccess: () => enrol.reset() });
}
</script>

<template>
    <Card :title="t('whatsapp.settings.agentsTitle')" :description="t('whatsapp.settings.agentsDescription')">
        <template #actions>
            <span class="tabular text-sm text-ink-muted" data-testid="agent-limit">
                {{
                    limit.limit === null
                        ? t('whatsapp.settings.agentsUnlimited', { used: format.number(limit.used) })
                        : t('whatsapp.settings.agentsOf', { used: format.number(limit.used), limit: format.number(limit.limit) })
                }}
            </span>
        </template>

        <EmptyState v-if="agents.length === 0" :title="t('whatsapp.settings.noAgents')" :description="t('whatsapp.settings.noAgentsBody')" compact>
            <template #icon><UsersRound /></template>
        </EmptyState>
        <ul v-else class="grid divide-y divide-line" :aria-label="t('whatsapp.settings.agentsTitle')">
            <li v-for="agent in agents" :key="agent.id" class="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <div class="grid gap-2">
                    <p class="font-medium text-ink wrap-anywhere">{{ agent.name }}</p>
                    <div v-if="drafts[agent.id]" class="flex flex-wrap items-end gap-x-5 gap-y-2">
                        <Checkbox v-model="drafts[agent.id]!.is_active" :label="t('whatsapp.settings.agentActive')" />
                        <Checkbox v-model="drafts[agent.id]!.is_available" :label="t('whatsapp.settings.agentAvailable')" :disabled="!drafts[agent.id]!.is_active" />
                        <Input
                            v-model="drafts[agent.id]!.max"
                            class="w-40"
                            type="number"
                            min="1"
                            max="1000"
                            :label="t('whatsapp.settings.maxOpen')"
                            :placeholder="t('whatsapp.settings.noMax')"
                        />
                    </div>
                    <p v-if="rowErrors[agent.id]" role="alert" class="text-sm text-danger">{{ rowErrors[agent.id] }}</p>
                </div>
                <div>
                    <Button size="sm" variant="secondary" :loading="saving[agent.id]" :disabled="!dirty(agent)" @click="save(agent)">{{ t('whatsapp.common.save') }}</Button>
                </div>
            </li>
        </ul>

        <form class="mt-4 grid gap-3 border-t border-line pt-4" @submit.prevent="submitEnrol">
            <h3 class="eyebrow">{{ t('whatsapp.settings.enrolTitle') }}</h3>
            <p v-if="members.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.settings.noMembers') }}</p>
            <template v-else>
                <p v-if="atLimit" class="text-sm text-warn" data-testid="agent-at-limit">{{ t('whatsapp.settings.agentsAtLimit') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Select
                        v-model="enrol.membership_id"
                        :options="memberOptions"
                        :label="t('whatsapp.settings.member')"
                        :placeholder="t('whatsapp.settings.chooseMember')"
                        :error="enrol.errors.membership_id"
                    />
                    <Input v-model="enrol.max_open_conversations" type="number" min="1" max="1000" :label="t('whatsapp.settings.maxOpen')" :placeholder="t('whatsapp.settings.noMax')" :error="enrol.errors.max_open_conversations" />
                </div>
                <Checkbox v-model="enrol.is_available" :label="t('whatsapp.settings.agentAvailable')" :description="t('whatsapp.settings.availableHint')" />
                <div>
                    <Button type="submit" size="sm" variant="primary" :loading="enrol.processing" :disabled="enrol.membership_id === '' || atLimit">
                        <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.settings.enrol') }}
                    </Button>
                </div>
            </template>
        </form>
    </Card>
</template>
