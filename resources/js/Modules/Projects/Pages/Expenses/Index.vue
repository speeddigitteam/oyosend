<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Receipt, ShieldCheck } from 'lucide-vue-next';
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
import type { Expense, ExpenseSummary, Member, Option } from '../../Components/types';
defineOptions({ layout: TenantLayout });
interface ExpenseRow extends Expense { task?: Option | null; member: Option; billable: boolean; approvable: boolean; submittable: boolean }
interface ExpensePagination { currentPage: number; perPage: number; total: number; lastPage: number; hasMorePages: boolean; previousPageUrl: string | null; nextPageUrl: string | null }
const props = defineProps<{ expenses: ExpenseRow[]; expensesPagination: ExpensePagination; projects: Option[]; summary: ExpenseSummary; canCreate: boolean; approvalMembers: Member[]; currentMembershipId: string }>();
const { t } = useI18n(); const format = useFormat(); const open = ref(false); const selectedApproval = ref<ExpenseRow | null>(null);
const actionPending = ref<string | null>(null); const actionError = ref('');
const form = useForm({ project_id: '', task_id: '', description: '', date: '', amount: '', billable: false });
const pagination = computed(() => ({ prev_page_url: props.expensesPagination.previousPageUrl, next_page_url: props.expensesPagination.nextPageUrl, from: props.expenses.length ? ((props.expensesPagination.currentPage - 1) * props.expensesPagination.perPage) + 1 : null, to: props.expenses.length ? ((props.expensesPagination.currentPage - 1) * props.expensesPagination.perPage) + props.expenses.length : null, total: props.expensesPagination.total }));
const columns = computed<Column[]>(() => [{ key: 'date', label: t('projects.fields.date') }, { key: 'project', label: t('projects.fields.project') }, { key: 'description', label: t('projects.fields.description') }, { key: 'member', label: t('projects.fields.member'), hideable: true }, { key: 'amount', label: t('projects.fields.amount'), align: 'right' }, { key: 'status', label: t('projects.fields.status') }, { key: 'actions', label: t('projects.common.actions'), srOnly: true, align: 'right' }]);
watch(open, (value) => { if (value) { form.reset(); form.clearErrors(); } });
function submit(): void { form.post(route('projects.expenses.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } }); }
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
function submitExpense(id: string): void { mutate(`submit:${id}`, route('projects.expenses.submit', { expense: id }), {}); }
function decide(id: string, approve: boolean): void { mutate(`${approve ? 'approve' : 'reject'}:${id}`, route('projects.expenses.approve', { expense: id }), { approve }); }
</script>
<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="t('projects.expenses.title')" :description="t('projects.expenses.description')"><template v-if="canCreate" #actions><Button variant="primary" @click="open = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.expenses.add') }}</Button></template></PageHeader><ProjectsNav current="finance" />
    <div class="grid gap-3 sm:grid-cols-2"><Card><p class="eyebrow">{{ t('projects.expenses.total') }}</p><p class="figure mt-3 text-3xl text-ink">{{ format.money(summary.totalAmount, summary.currency) }}</p></Card><Card><p class="eyebrow">{{ t('projects.expenses.pending') }}</p><p class="figure mt-3 text-3xl text-ink">{{ format.money(summary.pendingAmount, summary.currency) }}</p></Card></div>
    <Card flush class="mt-3"><DataTable :columns="columns" :rows="expenses" row-key="id" :caption="t('projects.expenses.caption')" storage-key="project-expenses"><template #cell-date="{ row }"><span class="tabular">{{ format.date(row.date) }}</span></template><template #cell-project="{ row }">{{ row.project.name }}</template><template #cell-description="{ row }"><div><p class="max-w-80 truncate text-ink">{{ row.description }}</p><p v-if="row.task" class="text-xs text-ink-subtle">{{ row.task.name }}</p></div></template><template #cell-member="{ row }">{{ row.member.name }}</template><template #cell-amount="{ row }"><span class="font-mono">{{ format.money(row.amount, row.currency) }}</span></template><template #cell-status="{ row }"><div class="flex items-center gap-2"><ProjectStatusBadge :value="row.status" /><span v-if="row.approvalPending" class="text-xs text-warn">{{ t('projects.approvals.pending') }}</span></div></template><template #cell-actions="{ row }"><div class="flex justify-end gap-1"><Button v-if="row.approvalRequestable" size="sm" variant="ghost" :disabled="actionPending !== null" @click="selectedApproval = row"><template #icon><ShieldCheck class="size-4" aria-hidden="true" /></template>{{ t('projects.approvals.request') }}</Button><Button v-if="row.submittable" size="sm" variant="ghost" :disabled="actionPending !== null" :loading="actionPending === `submit:${row.id}`" @click="submitExpense(row.id)">{{ t('projects.expenses.submit') }}</Button><template v-if="row.approvable && row.status === 'submitted'"><Button size="sm" variant="ghost" :disabled="actionPending !== null" :loading="actionPending === `reject:${row.id}`" @click="decide(row.id, false)">{{ t('projects.expenses.reject') }}</Button><Button size="sm" variant="primary" :disabled="actionPending !== null" :loading="actionPending === `approve:${row.id}`" @click="decide(row.id, true)">{{ t('projects.expenses.approve') }}</Button></template></div></template><template #empty><EmptyState :title="t('projects.expenses.empty')" :description="t('projects.expenses.emptyBody')"><template #icon><Receipt /></template></EmptyState></template></DataTable><p v-if="actionError" class="border-t border-line px-5 py-3 text-sm text-danger" role="alert">{{ actionError }}</p><Pagination :meta="pagination" /></Card>
    <Modal v-model:open="open" :title="t('projects.expenses.add')" :persistent="form.processing"><form id="expense-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit"><p v-if="projects.length === 0" class="text-sm text-ink-muted sm:col-span-2" role="status">{{ t('projects.expenses.noProjects') }}</p><Select v-else v-model="form.project_id" :options="projects.map((item) => ({ value: item.id, label: item.name }))" :label="t('projects.fields.project')" :error="form.errors.project_id" required /><ProjectTaskSelect v-model="form.task_id" :project-id="form.project_id" :error="form.errors.task_id" /><Input v-model="form.date" type="date" :label="t('projects.fields.date')" :error="form.errors.date" required /><Input v-model="form.amount" type="number" min="0" max="1000000000000000" step="1" :label="t('projects.fields.amountMinor')" :error="form.errors.amount" required /><Textarea v-model="form.description" class="sm:col-span-2" :label="t('projects.fields.description')" :error="form.errors.description" required maxlength="500" /><Checkbox v-model="form.billable" :label="t('projects.fields.billable')" /></form><template #footer><Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('projects.common.cancel') }}</Button><Button type="submit" form="expense-form" variant="primary" :disabled="projects.length === 0" :loading="form.processing">{{ t('projects.common.save') }}</Button></template></Modal>
    <ApprovalRequestDialog v-if="selectedApproval" :open="true" :project-id="selectedApproval.project.id" entity-type="expense" :entity-id="selectedApproval.id" :subject="selectedApproval.description" :members="approvalMembers" :current-membership-id="currentMembershipId" @update:open="!$event && (selectedApproval = null)" />
</template>

