<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Target } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CrmStatusBadge from '../../Components/CrmStatusBadge.vue';
import LeadFormDialog from '../../Components/LeadFormDialog.vue';
import RecordContentPanels from '../../Components/RecordContentPanels.vue';
import TimelineList from '../../Components/TimelineList.vue';
import type { Lead, LeadDealSummary, Option, RecordContent, TimelineEntry } from '../../Components/types';

defineOptions({ layout: TenantLayout });
const props = defineProps<{
    lead: Lead;
    deals: LeadDealSummary[];
    timeline: TimelineEntry[];
    content: RecordContent;
    sources: Option[];
    contacts: Option[];
    companies: Option[];
    owners: Option[];
}>();
const { t } = useI18n(); const format = useFormat(); const editOpen = ref(false); const qualifyOpen = ref(false);
const qualify = useForm({ score: props.lead.score, notes: props.lead.qualificationNotes ?? '' });
const assignment = useForm({ owner_id: props.lead.owner?.id ?? '' });
function submitQualify(): void { qualify.post(route('crm.leads.qualify', { lead: props.lead.id }), { preserveScroll: true, onSuccess: () => { qualifyOpen.value = false; } }); }
function assign(): void { assignment.put(route('crm.leads.assign', { lead: props.lead.id }), { preserveScroll: true }); }
</script>

<template>
    <PageHeader :eyebrow="t('crm.leads.detail')" :title="lead.title" :description="lead.company?.name ?? lead.contact?.name ?? t('crm.common.notSet')">
        <template #actions><Button variant="ghost" :href="route('crm.leads.index')"><template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>{{ t('crm.leads.title') }}</Button><Button v-if="lead.qualifiable && lead.status !== 'converted'" variant="primary" @click="qualifyOpen = true"><template #icon><Target class="size-4" aria-hidden="true" /></template>{{ t('crm.leads.qualify') }}</Button><Button v-if="lead.editable" @click="editOpen = true"><template #icon><Pencil class="size-4" aria-hidden="true" /></template>{{ t('crm.common.edit') }}</Button></template>
    </PageHeader>
    <div class="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <div class="grid gap-3">
            <Card :title="t('crm.leads.detail')"><dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3"><div><dt class="eyebrow">{{ t('crm.fields.status') }}</dt><dd class="mt-1"><CrmStatusBadge :status="lead.status" /></dd></div><div><dt class="eyebrow">{{ t('crm.fields.score') }}</dt><dd class="figure mt-1 text-2xl text-ink">{{ format.number(lead.score) }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.source') }}</dt><dd class="mt-1 text-sm text-ink">{{ lead.source?.name ?? t('crm.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.contact') }}</dt><dd class="mt-1 text-sm text-ink">{{ lead.contact?.name ?? t('crm.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.company') }}</dt><dd class="mt-1 text-sm text-ink">{{ lead.company?.name ?? t('crm.common.notSet') }}</dd></div><div><dt class="eyebrow">{{ t('crm.fields.owner') }}</dt><dd class="mt-1 text-sm text-ink">{{ lead.owner?.name ?? t('crm.common.unassigned') }}</dd></div><div class="sm:col-span-2 lg:col-span-3"><dt class="eyebrow">{{ t('crm.fields.qualificationNotes') }}</dt><dd class="mt-1 whitespace-pre-wrap text-sm text-ink">{{ lead.qualificationNotes ?? t('crm.common.notSet') }}</dd></div></dl></Card>
            <RecordContentPanels kind="lead" :record-id="lead.id" :content="content" :editable="lead.editable" />
        </div>
        <div class="grid content-start gap-3">
            <Card v-if="lead.assignable && owners.length" :title="t('crm.fields.owner')"><form class="flex items-end gap-2" @submit.prevent="assign"><Select v-model="assignment.owner_id" :options="[{ value: '', label: t('crm.common.unassigned') }, ...owners.map((item) => ({ value: item.id, label: item.name }))]" :label="t('crm.fields.owner')" :error="assignment.errors.owner_id" class="min-w-0 flex-1" /><Button type="submit" size="sm" :loading="assignment.processing">{{ t('crm.common.save') }}</Button></form></Card>
            <Card :title="t('crm.leads.relatedDeals')" flush><EmptyState v-if="deals.length === 0" :title="t('crm.leads.noDeals')" compact><template #icon><Target /></template></EmptyState><ul v-else class="divide-y divide-line"><li v-for="deal in deals" :key="deal.id" class="flex items-center justify-between gap-3 px-5 py-3"><div class="min-w-0"><Button variant="ghost" size="sm" :href="route('crm.deals.show', { deal: deal.id })" class="max-w-full truncate">{{ deal.title }}</Button><p class="px-3 text-xs text-ink-subtle">{{ deal.stage ?? t(`crm.status.${deal.status}`) }}</p></div><span class="tabular font-mono text-sm text-ink">{{ format.money(deal.amount, deal.currency) }}</span></li></ul></Card>
            <TimelineList :entries="timeline" />
        </div>
    </div>
    <LeadFormDialog v-if="lead.editable" v-model:open="editOpen" :lead="lead" :sources="sources" :contacts="contacts" :companies="companies" :owners="owners" />
    <Modal v-model:open="qualifyOpen" :title="t('crm.leads.qualifyTitle', { name: lead.title })" :description="t('crm.leads.qualifyBody')" :persistent="qualify.processing"><form id="crm-qualify-form" class="grid gap-4" @submit.prevent="submitQualify"><Input v-model="qualify.score" type="number" min="0" max="100" :label="t('crm.fields.score')" :error="qualify.errors.score" required /><Textarea v-model="qualify.notes" :label="t('crm.fields.qualificationNotes')" :error="qualify.errors.notes" optional /></form><template #footer><Button variant="ghost" @click="qualifyOpen = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-qualify-form" type="submit" variant="primary" :loading="qualify.processing">{{ t('crm.leads.qualify') }}</Button></template></Modal>
</template>
