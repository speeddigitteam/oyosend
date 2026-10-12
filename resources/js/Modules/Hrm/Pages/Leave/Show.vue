<script setup lang="ts">
import { ArrowLeft } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import LeaveCancelDialog from '../../Components/LeaveCancelDialog.vue';
import LeavePeriod from '../../Components/LeavePeriod.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { LeaveRequest } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    request: LeaveRequest;
    balanceUnits: number | null;
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();
const { can } = useAccess();

const decision = ref<'approve' | 'reject' | null>(null);
const decisionOpen = ref(false);
const cancelOpen = ref(false);

const perDay = computed(() => Object.entries(props.request.days).sort(([a], [b]) => a.localeCompare(b)));

function decide(value: 'approve' | 'reject'): void {
    decision.value = value;
    decisionOpen.value = true;
}
</script>

<template>
    <PageHeader
        :eyebrow="request.employee.number"
        :title="t('hrm.leave.showTitle', { name: request.employee.name })"
        :description="request.leaveType.name"
    >
        <template #actions>
            <Button v-if="can('hrm.leave.view')" variant="secondary" :href="route('hrm.leave.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.leave.backToList') }}
            </Button>
            <template v-if="request.abilities?.decide">
                <Button variant="secondary" @click="decide('reject')">{{ t('hrm.decision.reject') }}</Button>
                <Button variant="primary" @click="decide('approve')">{{ t('hrm.decision.approve') }}</Button>
            </template>
            <Button v-if="request.abilities?.cancel" variant="secondary" @click="cancelOpen = true">{{ t('hrm.leave.cancel.action') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <Card class="min-w-0 lg:col-span-7" :title="t('hrm.leave.details')">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
                <div>
                    <dt class="eyebrow">{{ t('hrm.fields.status') }}</dt>
                    <dd class="mt-1"><HrmStatusBadge kind="leave" :value="request.status" /></dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('hrm.fields.leaveType') }}</dt>
                    <dd class="mt-1 text-ink">
                        {{ request.leaveType.name }}
                        <span v-if="!request.leaveType.isPaid" class="block text-xs text-ink-subtle">{{ t('hrm.leave.unpaid') }}</span>
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="eyebrow">{{ t('hrm.fields.period') }}</dt>
                    <dd class="mt-1 text-ink">
                        <LeavePeriod :starts-on="request.startsOn" :ends-on="request.endsOn" :start-session="request.startSession" :end-session="request.endSession" />
                    </dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('hrm.fields.days') }}</dt>
                    <dd class="figure tabular mt-1 text-xl text-ink" data-testid="request-days">{{ hrm.days(request.units) }}</dd>
                </div>
                <div v-if="balanceUnits !== null">
                    <dt class="eyebrow">{{ t('hrm.leave.balanceLeft', { year: format.number(request.leaveYear, { useGrouping: false }) }) }}</dt>
                    <dd class="figure tabular mt-1 text-xl text-ink" data-testid="request-balance">{{ hrm.days(balanceUnits) }}</dd>
                </div>
                <div v-if="request.reason" class="col-span-2">
                    <dt class="eyebrow">{{ t('hrm.fields.reason') }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-ink">{{ request.reason }}</dd>
                </div>
                <div v-if="request.requestedAt">
                    <dt class="eyebrow">{{ t('hrm.leave.requestedOn') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ format.date(request.requestedAt, 'datetime') }}</dd>
                </div>
                <div v-if="request.cancelledAt">
                    <dt class="eyebrow">{{ t('hrm.leave.cancelledOn') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ format.date(request.cancelledAt, 'datetime') }}</dd>
                </div>
                <div v-if="request.cancellationReason" class="col-span-2">
                    <dt class="eyebrow">{{ t('hrm.leave.cancellationReason') }}</dt>
                    <dd class="mt-1 text-ink">{{ request.cancellationReason }}</dd>
                </div>
            </dl>
        </Card>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-5">
            <Card :title="t('hrm.leave.approvalsTitle')">
                <p v-if="request.approvals.length === 0" class="text-sm text-ink-muted">{{ t('hrm.leave.noApprovals') }}</p>
                <ol v-else class="relative grid gap-4 border-l border-line pl-5" data-testid="approvals">
                    <li v-for="(approval, index) in request.approvals" :key="index" class="relative">
                        <span class="absolute top-1.5 -left-[1.6875rem] size-2.5 rounded-full bg-brand" aria-hidden="true" />
                        <p class="text-sm text-ink">
                            {{ t(`hrm.leave.steps.${approval.step}`) }}:
                            {{ approval.decision === 'approved' ? t('hrm.decision.approved') : t('hrm.decision.rejected') }}
                        </p>
                        <p class="tabular text-xs text-ink-subtle">{{ format.date(approval.decidedAt, 'datetime') }}</p>
                        <p v-if="approval.note" class="mt-1 text-sm text-ink-muted">{{ approval.note }}</p>
                    </li>
                </ol>
            </Card>

            <Card :title="t('hrm.leave.perDay')">
                <ul class="divide-y divide-line">
                    <li v-for="[date, units] in perDay" :key="date" class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0 text-sm">
                        <span class="tabular text-ink">{{ format.date(date) }}</span>
                        <span class="tabular text-ink-muted">{{ hrm.days(units) }}</span>
                    </li>
                </ul>
            </Card>
        </div>
    </div>

    <DecisionDialog
        v-if="decision"
        v-model:open="decisionOpen"
        :url="route('hrm.leave.requests.decision', { leaveRequest: request.id })"
        :decision="decision"
        :title="decision === 'approve' ? t('hrm.leave.approveTitle') : t('hrm.leave.rejectTitle')"
        :description="request.employee.name"
    />
    <LeaveCancelDialog v-model:open="cancelOpen" :request="request" />
</template>
