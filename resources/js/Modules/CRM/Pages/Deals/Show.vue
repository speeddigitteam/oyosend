<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, GitBranch, Pencil } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CrmStatusBadge from '../../Components/CrmStatusBadge.vue';
import DealFormDialog from '../../Components/DealFormDialog.vue';
import RecordContentPanels from '../../Components/RecordContentPanels.vue';
import TimelineList from '../../Components/TimelineList.vue';
import type { Deal, DealStageHistory, Option, Pipeline, PipelineStage, RecordContent, TimelineEntry } from '../../Components/types';

defineOptions({ layout: TenantLayout });
const props = defineProps<{
    deal: Deal;
    stages: PipelineStage[];
    history: DealStageHistory[];
    timeline: TimelineEntry[];
    content: RecordContent;
    pipelines: Pipeline[];
    leads: Option[];
    contacts: Option[];
    companies: Option[];
    owners: Option[];
}>();
const { t } = useI18n(); const format = useFormat(); const editOpen = ref(false); const moveOpen = ref(false);
const move = useForm({ stage_id: props.deal.stage.id, note: '' }); const assignment = useForm({ owner_id: props.deal.owner?.id ?? '' });
const currentPipeline = computed<Pipeline>(() => ({ ...props.deal.pipeline, stages: props.stages }));
const availablePipelines = computed<Pipeline[]>(() => props.pipelines.some((item) => item.id === props.deal.pipeline.id)
    ? props.pipelines
    : [currentPipeline.value, ...props.pipelines]);
function submitMove(): void { move.post(route('crm.deals.stage', { deal: props.deal.id }), { preserveScroll: true, onSuccess: () => { moveOpen.value = false; move.note = ''; } }); }
function assign(): void { assignment.put(route('crm.deals.assign', { deal: props.deal.id }), { preserveScroll: true }); }
</script>

<template>
    <PageHeader :eyebrow="t('crm.deals.detail')" :title="deal.title" :description="deal.company?.name ?? deal.contact?.name ?? t('crm.common.notSet')"><template #actions><Button variant="ghost" :href="route('crm.deals.index')"><template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>{{ t('crm.deals.title') }}</Button><Button v-if="deal.editable" variant="primary" @click="moveOpen = true"><template #icon><GitBranch class="size-4" aria-hidden="true" /></template>{{ t('crm.deals.move') }}</Button><Button v-if="deal.editable" @click="editOpen = true"><template #icon><Pencil class="size-4" aria-hidden="true" /></template>{{ t('crm.common.edit') }}</Button></template></PageHeader>
    <div class="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]"><div class="grid gap-3">
        <Card :title="t('crm.deals.detail')"><dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3"><div><dt class="eyebrow">{{ t('crm.fields.status') }}</dt><dd class="mt-1"><CrmStatusBadge :status="deal.status" /></dd></div><div><dt class="eyebrow">{{ t('crm.fields.amount') }}</dt><dd class="figure mt-1 text-2xl text-ink">{{ format.money(deal.amount, deal.currency) }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.expectedClose') }}</dt><dd class="tabular mt-1 text-sm text-ink">{{ deal.expectedCloseDate ? format.date(deal.expectedCloseDate) : t('crm.deals.noExpectedDate') }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.pipeline') }}</dt><dd class="mt-1 text-sm text-ink">{{ deal.pipeline.name }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.stage') }}</dt><dd class="mt-1 text-sm text-ink">{{ deal.stage.name }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.owner') }}</dt><dd class="mt-1 text-sm text-ink">{{ deal.owner?.name ?? t('crm.common.unassigned') }}</dd></div><div v-if="deal.lead"><dt class="eyebrow">{{ t('crm.nav.leads') }}</dt><dd class="mt-1"><Button size="sm" variant="ghost" :href="route('crm.leads.show', { lead: deal.lead.id })">{{ deal.lead.title }}</Button></dd></div><div><dt class="eyebrow">{{ t('crm.fields.contact') }}</dt><dd class="mt-1 text-sm text-ink">{{ deal.contact?.name ?? t('crm.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.company') }}</dt><dd class="mt-1 text-sm text-ink">{{ deal.company?.name ?? t('crm.common.notSet') }}</dd></div><div class="sm:col-span-2 lg:col-span-3"><dt class="eyebrow">{{ t('crm.fields.description') }}</dt><dd class="mt-1 whitespace-pre-wrap text-sm text-ink">{{ deal.description ?? t('crm.common.notSet') }}</dd></div></dl></Card>
        <RecordContentPanels kind="deal" :record-id="deal.id" :content="content" :editable="deal.editable" />
    </div><div class="grid content-start gap-3">
        <Card v-if="deal.assignable && owners.length" :title="t('crm.fields.owner')"><form class="flex items-end gap-2" @submit.prevent="assign"><Select v-model="assignment.owner_id" :options="[{ value: '', label: t('crm.common.unassigned') }, ...owners.map((item) => ({ value: item.id, label: item.name }))]" :label="t('crm.fields.owner')" :error="assignment.errors.owner_id" class="min-w-0 flex-1" /><Button type="submit" size="sm" :loading="assignment.processing">{{ t('crm.common.save') }}</Button></form></Card>
        <Card :title="t('crm.deals.history')" flush><EmptyState v-if="history.length === 0" :title="t('crm.deals.noHistory')" compact><template #icon><GitBranch /></template></EmptyState><ol v-else class="divide-y divide-line"><li v-for="entry in history" :key="entry.id" class="px-5 py-3"><div class="flex items-center gap-2 text-sm text-ink"><span>{{ entry.from ?? t('crm.common.notSet') }}</span><ArrowRight class="size-3.5 text-ink-subtle" aria-hidden="true" /><span class="font-medium">{{ entry.to }}</span></div><p v-if="entry.note" class="mt-1 text-[0.8125rem] text-ink-muted">{{ entry.note }}</p><p class="mt-1 text-xs text-ink-subtle">{{ format.date(entry.changedAt, 'datetime') }}<template v-if="entry.actor"> · {{ entry.actor }}</template></p></li></ol></Card>
        <TimelineList :entries="timeline" />
    </div></div>
    <DealFormDialog v-if="deal.editable" v-model:open="editOpen" :deal="deal" :pipelines="availablePipelines" :leads="leads" :contacts="contacts" :companies="companies" :owners="owners" />
    <Modal v-model:open="moveOpen" :title="t('crm.deals.moveTitle', { name: deal.title })" :persistent="move.processing"><form id="crm-move-form" class="grid gap-4" @submit.prevent="submitMove"><Select v-model="move.stage_id" :options="stages.map((stage) => ({ value: stage.id, label: stage.name }))" :label="t('crm.fields.stage')" :error="move.errors.stage_id" required /><Textarea v-model="move.note" :label="t('crm.fields.note')" :error="move.errors.note" optional /></form><template #footer><Button variant="ghost" @click="moveOpen = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-move-form" type="submit" variant="primary" :loading="move.processing">{{ t('crm.deals.move') }}</Button></template></Modal>
</template>
