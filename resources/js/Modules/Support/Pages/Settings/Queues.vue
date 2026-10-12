<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import AgentsPanel from '../../Components/AgentsPanel.vue';
import CategoriesPanel from '../../Components/CategoriesPanel.vue';
import QueuesPanel from '../../Components/QueuesPanel.vue';
import RulesPanel from '../../Components/RulesPanel.vue';
import SupportNav from '../../Components/SupportNav.vue';
import type { AgentRow, AssignmentMode, CandidateRow, CategoryRow, CustomerOption, Option, QueueRow, RuleRow, TicketChannel, TicketPriority } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    queues: QueueRow[];
    agents: AgentRow[];
    candidates: CandidateRow[];
    categories: CategoryRow[];
    companies: CustomerOption[];
    rules: RuleRow[];
    departments: Option[];
    policies: { id: string; name: string; isActive: boolean }[];
    assignmentModes: AssignmentMode[];
    priorities: TicketPriority[];
    channels: TicketChannel[];
    agentLimit: { limit: number | null; used: number };
}>();

type Section = 'queues' | 'agents' | 'categories' | 'rules';

const { t } = useI18n();
const section = ref<Section>('queues');
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.queues.title')" :description="t('support.queues.description')" />
    <SupportNav current="queues" />

    <div class="mb-3">
        <SegmentedControl
            v-model="section"
            :label="t('support.queues.sections')"
            :options="[
                { value: 'queues', label: t('support.queues.queues') },
                { value: 'agents', label: t('support.queues.agents') },
                { value: 'categories', label: t('support.queues.categories') },
                { value: 'rules', label: t('support.rules.title') },
            ]"
        />
    </div>

    <QueuesPanel v-if="section === 'queues'" :queues="queues" :agents="agents" :departments="departments" :policies="policies" :modes="assignmentModes" />
    <AgentsPanel v-else-if="section === 'agents'" :agents="agents" :candidates="candidates" :limit="agentLimit" />
    <CategoriesPanel v-else-if="section === 'categories'" :categories="categories" :queues="queues" />
    <RulesPanel v-else :rules="rules" :queues="queues" :agents="agents" :categories="categories" :companies="companies" :priorities="priorities" :channels="channels" />
</template>
