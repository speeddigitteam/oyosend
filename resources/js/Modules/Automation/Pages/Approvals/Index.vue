<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Check, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { permissionLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import WorkflowBadge from '../../Components/WorkflowBadge.vue';
import type { ApprovalRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    pending: ApprovalRow[];
    history: ApprovalRow[];
}>();

const { t } = useI18n();
const format = useFormat();

const target = ref<ApprovalRow | null>(null);
const decision = ref<'approve' | 'reject'>('approve');
const form = useForm({ decision: 'approve', comment: '' });
const open = computed({ get: () => target.value !== null, set: (value: boolean) => { if (!value) target.value = null; } });

function decide(approval: ApprovalRow, value: 'approve' | 'reject'): void {
    target.value = approval;
    decision.value = value;
    form.reset();
    form.clearErrors();
}

function submit(): void {
    const approval = target.value;

    if (!approval) {
        return;
    }

    form.transform((data) => ({
        decision: decision.value,
        comment: data.comment.trim() === '' ? null : data.comment.trim(),
    })).post(route('automation.approvals.decide', { approval: approval.id }), {
        preserveScroll: true,
        onSuccess: () => {
            target.value = null;
        },
    });
}

function approverText(approval: ApprovalRow): string {
    if (approval.approverType === 'membership') {
        return approval.approverName ?? t('automation.common.unknownPerson');
    }

    return approval.approverPermission
        ? t('automation.approvals.permissionApprover', { permission: permissionLabel(approval.approverPermission) })
        : t('automation.common.dash');
}

function timeoutText(approval: ApprovalRow): string {
    const key = approval.onTimeout === 'approve' ? 'timeoutApprove' : approval.onTimeout === 'fail' ? 'timeoutFail' : 'timeoutReject';

    return t('automation.approvals.onTimeout', { action: t(`automation.approvals.${key}`) });
}

const columns = computed<Column[]>(() => [
    { key: 'title', label: t('automation.approvals.request') },
    { key: 'status', label: t('automation.common.status') },
    { key: 'decidedBy', label: t('automation.approvals.decidedByColumn'), hideable: true },
    { key: 'decidedAt', label: t('automation.runs.finished'), hideable: true },
]);
</script>

<template>
    <PageHeader :eyebrow="t('automation.eyebrow')" :title="t('automation.approvals.title')" :description="t('automation.approvals.description')" />

    <h2 class="mb-2 text-lg">{{ t('automation.approvals.pending') }}</h2>
    <EmptyState v-if="pending.length === 0" :title="t('automation.approvals.empty')" :description="t('automation.approvals.emptyBody')" class="mb-6">
        <template #icon><BadgeCheck /></template>
    </EmptyState>
    <ul v-else class="mb-6 grid gap-3 md:grid-cols-2" :aria-label="t('automation.approvals.pendingCaption')">
        <li v-for="approval in pending" :key="approval.id">
            <Card :title="approval.title" class="h-full">
                <p v-if="approval.message" class="mb-3 text-[0.8125rem] text-ink-muted">{{ approval.message }}</p>
                <dl class="space-y-1.5 text-xs text-ink-muted">
                    <div v-if="approval.workflowName">
                        <dt class="inline eyebrow">{{ t('automation.approvals.workflow') }}</dt>
                        <dd class="ml-1 inline text-ink">{{ approval.workflowName }}</dd>
                    </div>
                    <div v-if="approval.requestedBy">{{ t('automation.approvals.requestedBy', { name: approval.requestedBy }) }}</div>
                    <div v-if="approval.expiresAt">{{ t('automation.approvals.expires', { when: format.date(approval.expiresAt, 'datetime') }) }}</div>
                    <div>{{ approverText(approval) }}. {{ timeoutText(approval) }}</div>
                </dl>
                <template #footer>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button size="sm" variant="primary" type="button" @click="decide(approval, 'approve')">
                            <template #icon><Check class="size-4" aria-hidden="true" /></template>
                            {{ t('automation.approvals.approve') }}
                        </Button>
                        <Button size="sm" variant="secondary" type="button" @click="decide(approval, 'reject')">
                            <template #icon><X class="size-4" aria-hidden="true" /></template>
                            {{ t('automation.approvals.reject') }}
                        </Button>
                        <Link :href="route('automation.runs.show', { run: approval.runId })" class="ml-auto text-xs text-brand-text hover:underline">
                            {{ t('automation.approvals.viewRun') }}
                        </Link>
                    </div>
                </template>
            </Card>
        </li>
    </ul>

    <h2 class="mb-2 text-lg">{{ t('automation.approvals.history') }}</h2>
    <Card flush>
        <DataTable :columns="columns" :rows="history" row-key="id" :caption="t('automation.approvals.historyCaption')">
            <template #cell-title="{ row }">
                <span class="font-medium text-ink">{{ row.title }}</span>
                <span v-if="row.workflowName" class="block text-xs text-ink-subtle">{{ row.workflowName }}</span>
                <span v-if="row.decisionComment" class="block max-w-md truncate text-xs text-ink-muted">{{ row.decisionComment }}</span>
            </template>
            <template #cell-status="{ row }"><WorkflowBadge kind="approval" :value="row.status" /></template>
            <template #cell-decidedBy="{ row }"><span class="text-ink-muted">{{ row.decidedBy ?? t('automation.common.dash') }}</span></template>
            <template #cell-decidedAt="{ row }"><span class="text-ink-muted">{{ row.decidedAt ? format.date(row.decidedAt, 'datetime') : t('automation.common.dash') }}</span></template>
            <template #empty>
                <EmptyState :title="t('automation.approvals.emptyHistory')" compact />
            </template>
        </DataTable>
    </Card>

    <Modal
        v-model:open="open"
        :title="target ? t(decision === 'approve' ? 'automation.approvals.approveTitle' : 'automation.approvals.rejectTitle', { title: target.title }) : ''"
        :persistent="form.processing"
    >
        <form id="decide-approval" @submit.prevent="submit">
            <Textarea
                v-model="form.comment"
                :label="t('automation.approvals.comment')"
                :hint="t('automation.approvals.commentHint')"
                :error="form.errors.comment || form.errors.decision"
                optional
                maxlength="2000"
            />
        </form>
        <template #footer>
            <Button variant="ghost" type="button" :disabled="form.processing" @click="target = null">{{ t('automation.common.cancel') }}</Button>
            <Button :variant="decision === 'approve' ? 'primary' : 'danger'" type="submit" form="decide-approval" :loading="form.processing">
                {{ decision === 'approve' ? t('automation.approvals.approve') : t('automation.approvals.reject') }}
            </Button>
        </template>
    </Modal>
</template>
