<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Clock3, Plus, ShieldCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ApprovalRequestDialog from '../../Components/ApprovalRequestDialog.vue';
import ProjectTaskSelect from '../../Components/ProjectTaskSelect.vue';
import ProjectsNav from '../../Components/ProjectsNav.vue';
import ProjectStatusBadge from '../../Components/ProjectStatusBadge.vue';
import type { Member, Option, TimeEntry } from '../../Components/types';
defineOptions({ layout: TenantLayout });
interface TimePagination { currentPage: number; perPage: number; total: number; lastPage: number; hasMorePages: boolean; previousPageUrl: string | null; nextPageUrl: string | null }
const props = defineProps<{ entries: TimeEntry[]; entriesPagination: TimePagination; projects: Option[]; summary: { totalMinutes: number; billableMinutes: number; pendingMinutes: number }; canLog: boolean; approvalMembers: Member[]; currentMembershipId: string }>();
const { t } = useI18n(); const format = useFormat(); const open = ref(false); const selectedApproval = ref<TimeEntry | null>(null);
const actionPending = ref<string | null>(null); const actionError = ref('');
const form = useForm({ project_id: '', task_id: '', date: '', minutes: '', billable: true, note: '' });
const pagination = computed(() => ({ prev_page_url: props.entriesPagination.previousPageUrl, next_page_url: props.entriesPagination.nextPageUrl, from: props.entries.length ? ((props.entriesPagination.currentPage - 1) * props.entriesPagination.perPage) + 1 : null, to: props.entries.length ? ((props.entriesPagination.currentPage - 1) * props.entriesPagination.perPage) + props.entries.length : null, total: props.entriesPagination.total }));
const columns = computed<Column[]>(() => [{ key: 'date', label: t('projects.fields.date') }, { key: 'project', label: t('projects.fields.project') }, { key: 'task', label: t('projects.fields.task'), hideable: true }, { key: 'member', label: t('projects.fields.member') }, { key: 'duration', label: t('projects.fields.duration'), align: 'right' }, { key: 'billable', label: t('projects.fields.billable'), hideable: true }, { key: 'status', label: t('projects.fields.status') }, { key: 'actions', label: t('projects.common.actions'), srOnly: true, align: 'right' }]);
watch(open, (value) => { if (value) { form.reset(); form.clearErrors(); } });
const hours = (minutes: number) => `${format.number(minutes / 60, { maximumFractionDigits: 1 })} ${t('projects.common.hoursShort')}`;
function submit(): void { form.post(route('projects.time.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } }); }
function mutate(key: string, url: string, data: { approve?: boolean }): void {
    if (actionPending.value) return;
    actionPending.value = key;
    actionError.value = '';
    router.patch(url, data, {
        preserveScroll: true,
        onError: (errors) => { actionError.value = errors.status ?? errors.default ?? Object.values(errors)[0] ?? ''; },
        onSuccess: () => { actionError.value = ''; },
        onFinish: () => { actionPending.value = null; },
    });
}
function submitTime(id: string): void { mutate(`submit:${id}`, route('projects.time.submit', { timeEntry: id }), {}); }
function decide(id: string, approve: boolean): void { mutate(`${approve ? 'approve' : 'reject'}:${id}`, route('projects.time.approve', { timeEntry: id }), { approve }); }
</script>
<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="t('projects.time.title')" :description="t('projects.time.description')"><template v-if="canLog" #actions><Button variant="primary" @click="open = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.time.log') }}</Button></template></PageHeader><ProjectsNav current="time" />
    <div class="grid gap-3 sm:grid-cols-3"><Card><p class="eyebrow">{{ t('projects.time.total') }}</p><p class="figure mt-3 text-3xl text-ink">{{ hours(summary.totalMinutes) }}</p></Card><Card><p class="eyebrow">{{ t('projects.time.billable') }}</p><p class="figure mt-3 text-3xl text-ink">{{ hours(summary.billableMinutes) }}</p></Card><Card><p class="eyebrow">{{ t('projects.time.pending') }}</p><p class="figure mt-3 text-3xl text-ink">{{ hours(summary.pendingMinutes) }}</p></Card></div>
    <Card flush class="mt-3"><DataTable :columns="columns" :rows="entries" row-key="id" :caption="t('projects.time.caption')" storage-key="project-time"><template #cell-date="{ row }"><span class="tabular">{{ format.date(row.date) }}</span></template><template #cell-project="{ row }">{{ row.project.name }}</template><template #cell-task="{ row }"><span class="text-ink-muted">{{ row.task?.name ?? t('projects.common.notSet') }}</span></template><template #cell-member="{ row }">{{ row.member.name }}</template><template #cell-duration="{ row }"><span class="font-mono">{{ hours(row.minutes) }}</span></template><template #cell-billable="{ row }">{{ t(row.billable ? 'projects.common.yes' : 'projects.common.no') }}</template><template #cell-status="{ row }"><div class="flex items-center gap-2"><ProjectStatusBadge v-if="row.status" :value="row.status" /><span v-if="row.approvalPending" class="text-xs text-warn">{{ t('projects.approvals.pending') }}</span></div></template><template #cell-actions="{ row }"><div class="flex justify-end gap-1"><Button v-if="row.approvalRequestable" size="sm" variant="ghost" :disabled="actionPending !== null" @click="selectedApproval = row"><ShieldCheck class="size-4" aria-hidden="true" />{{ t('projects.approvals.request') }}</Button><Button v-if="row.submittable" size="sm" variant="ghost" :disabled="actionPending !== null" :loading="actionPending === `submit:${row.id}`" @click="submitTime(row.id)">{{ t('projects.time.submit') }}</Button><template v-if="row.approvable && row.status === 'submitted'"><Button size="sm" variant="ghost" :disabled="actionPending !== null" :loading="actionPending === `reject:${row.id}`" @click="decide(row.id, false)">{{ t('projects.time.reject') }}</Button><Button size="sm" variant="primary" :disabled="actionPending !== null" :loading="actionPending === `approve:${row.id}`" @click="decide(row.id, true)">{{ t('projects.time.approve') }}</Button></template></div></template><template #empty><EmptyState :title="t('projects.time.empty')" :description="t('projects.time.emptyBody')"><template #icon><Clock3 /></template></EmptyState></template></DataTable><p v-if="actionError" class="border-t border-line px-5 py-3 text-sm text-danger" role="alert">{{ actionError }}</p><Pagination :meta="pagination" /></Card>
    <Modal v-model:open="open" :title="t('projects.time.log')" :persistent="form.processing"><form id="time-entry-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit"><p v-if="projects.length === 0" class="text-sm text-ink-muted sm:col-span-2" role="status">{{ t('projects.time.noProjects') }}</p><Select v-else v-model="form.project_id" :options="projects.map((item) => ({ value: item.id, label: item.name }))" :label="t('projects.fields.project')" :error="form.errors.project_id" required /><ProjectTaskSelect v-model="form.task_id" :project-id="form.project_id" :error="form.errors.task_id" /><Input v-model="form.date" type="date" :label="t('projects.fields.date')" :error="form.errors.date" required /><Input v-model="form.minutes" type="number" min="1" max="10080" :label="t('projects.fields.durationMinutes')" :error="form.errors.minutes" required /><Textarea v-model="form.note" class="sm:col-span-2" :label="t('projects.fields.note')" :error="form.errors.note" optional maxlength="5000" /><Checkbox v-model="form.billable" :label="t('projects.fields.billable')" /></form><template #footer><Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('projects.common.cancel') }}</Button><Button type="submit" form="time-entry-form" variant="primary" :disabled="projects.length === 0" :loading="form.processing">{{ t('projects.common.save') }}</Button></template></Modal>
    <ApprovalRequestDialog v-if="selectedApproval" :open="true" :project-id="selectedApproval.project.id" entity-type="time_entry" :entity-id="selectedApproval.id" :subject="`${selectedApproval.project.name} · ${format.date(selectedApproval.date)}`" :members="approvalMembers" :current-membership-id="currentMembershipId" @update:open="!$event && (selectedApproval = null)" />
</template>
