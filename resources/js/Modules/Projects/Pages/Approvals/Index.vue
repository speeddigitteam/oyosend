<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { BadgeCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ProjectsNav from '../../Components/ProjectsNav.vue';
import ProjectStatusBadge from '../../Components/ProjectStatusBadge.vue';
import type { ApprovalStatus, Member, Option, Paginated } from '../../Components/types';
defineOptions({ layout: TenantLayout });
interface ApprovalRow { id: string; type: 'task' | 'time_entry' | 'expense' | 'milestone'; title: string; project: Option; requester: Member; approver: Member; requestNote?: string | null; decisionNote?: string | null; requestedAt: string; status: ApprovalStatus; actionable: boolean; cancelable: boolean }
const props = defineProps<{ approvals: Paginated<ApprovalRow> }>();
const { t } = useI18n(); const format = useFormat(); const selected = ref<ApprovalRow | null>(null); const cancelTarget = ref<ApprovalRow | null>(null); const decision = useForm({ approve: true, note: '' }); const cancellation = useForm({});
const decisionStatusError = computed(() => (decision.errors as Record<string, string | undefined>).status ?? '');
const cancellationStatusError = computed(() => (cancellation.errors as Record<string, string | undefined>).status ?? '');
const columns = computed<Column[]>(() => [{ key: 'title', label: t('projects.fields.project') }, { key: 'type', label: t('projects.fields.type') }, { key: 'requester', label: t('projects.fields.requester') }, { key: 'requested', label: t('projects.fields.requestedAt'), hideable: true }, { key: 'status', label: t('projects.fields.status') }, { key: 'actions', label: t('projects.common.actions'), srOnly: true, align: 'right' }]);
function openDecision(row: ApprovalRow, approve: boolean): void { selected.value = row; decision.approve = approve; decision.note = ''; decision.clearErrors(); }
function submit(): void { if (!selected.value) return; decision.patch(route('projects.approvals.decide', { approval: selected.value.id }), { preserveScroll: true, onSuccess: () => { selected.value = null; } }); }
function cancelRequest(): void { if (!cancelTarget.value) return; cancellation.patch(route('projects.approvals.cancel', { approval: cancelTarget.value.id }), { preserveScroll: true, onSuccess: () => { cancelTarget.value = null; } }); }
</script>
<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="t('projects.approvals.title')" :description="t('projects.approvals.description')" /><ProjectsNav current="approvals" />
    <Card flush><DataTable :columns="columns" :rows="approvals.data" row-key="id" :caption="t('projects.approvals.caption')" storage-key="project-approvals"><template #cell-title="{ row }"><div><p class="font-medium text-ink">{{ row.title }}</p><p v-if="row.requestNote" class="max-w-72 truncate text-xs text-ink-subtle">{{ row.requestNote }}</p></div></template><template #cell-type="{ row }">{{ t(`projects.approvalTypes.${row.type}`) }}</template><template #cell-requester="{ row }">{{ row.requester.name }}</template><template #cell-requested="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.requestedAt, 'datetime') }}</span></template><template #cell-status="{ row }"><ProjectStatusBadge :value="row.status" /></template><template #cell-actions="{ row }"><div v-if="row.actionable || row.cancelable" class="flex justify-end gap-1"><Button v-if="row.cancelable" size="sm" variant="ghost" :disabled="decision.processing || cancellation.processing" @click="cancellation.clearErrors(); cancelTarget = row">{{ t('projects.approvals.cancelRequest') }}</Button><Button v-if="row.actionable" size="sm" variant="ghost" :disabled="decision.processing || cancellation.processing" @click="openDecision(row, false)">{{ t('projects.approvals.reject') }}</Button><Button v-if="row.actionable" size="sm" variant="primary" :disabled="decision.processing || cancellation.processing" @click="openDecision(row, true)">{{ t('projects.approvals.approve') }}</Button></div></template><template #empty><EmptyState :title="t('projects.approvals.empty')"><template #icon><BadgeCheck /></template></EmptyState></template></DataTable><Pagination :meta="approvals" /></Card>
    <Modal :open="selected !== null" :title="t('projects.approvals.decisionTitle')" :persistent="decision.processing" @update:open="!$event && !decision.processing && (selected = null)"><form id="approval-decision-form" @submit.prevent="submit"><Textarea v-model="decision.note" :label="t('projects.approvals.decisionNote')" :error="decision.errors.note" optional maxlength="10000" /><p v-if="decisionStatusError" class="mt-2 text-sm text-danger" role="alert">{{ decisionStatusError }}</p></form><template #footer><Button variant="ghost" :disabled="decision.processing" @click="selected = null">{{ t('projects.common.cancel') }}</Button><Button form="approval-decision-form" type="submit" :variant="decision.approve ? 'primary' : 'danger'" :loading="decision.processing">{{ t(decision.approve ? 'projects.approvals.approve' : 'projects.approvals.reject') }}</Button></template></Modal>
    <ConfirmDialog :open="cancelTarget !== null" :title="t('projects.approvals.cancelRequest')" :description="t('projects.approvals.cancelConfirm')" :confirm-label="t('projects.approvals.cancelRequest')" tone="danger" :loading="cancellation.processing" @update:open="!$event && !cancellation.processing && (cancelTarget = null)" @confirm="cancelRequest"><p v-if="cancellationStatusError" class="text-sm text-danger" role="alert">{{ cancellationStatusError }}</p></ConfirmDialog>
</template>

