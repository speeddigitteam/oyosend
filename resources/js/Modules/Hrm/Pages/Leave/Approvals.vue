<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import HrmSubNav from '../../Components/HrmSubNav.vue';
import LeaveCancelDialog from '../../Components/LeaveCancelDialog.vue';
import LeaveRequestsTable from '../../Components/LeaveRequestsTable.vue';
import type { LeaveRequest, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ requests: Paginated<LeaveRequest> }>();

const { t } = useI18n();

const deciding = ref<{ request: LeaveRequest; decision: 'approve' | 'reject' } | null>(null);
const decisionOpen = ref(false);
const cancelling = ref<LeaveRequest | null>(null);

function decide(request: LeaveRequest, decision: 'approve' | 'reject'): void {
    deciding.value = { request, decision };
    decisionOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.approvals.title')" :description="t('hrm.approvals.description')" />
    <HrmSubNav area="leave" current="approvals" />

    <Card flush>
        <LeaveRequestsTable
            :requests="requests"
            :caption="t('hrm.approvals.caption')"
            :empty-title="t('hrm.approvals.empty')"
            :empty-body="t('hrm.approvals.emptyBody')"
            storage-key="hrm-approvals"
            @approve="decide($event, 'approve')"
            @reject="decide($event, 'reject')"
            @cancel="cancelling = $event"
        />
    </Card>

    <DecisionDialog
        v-if="deciding"
        v-model:open="decisionOpen"
        :url="route('hrm.leave.requests.decision', { leaveRequest: deciding.request.id })"
        :decision="deciding.decision"
        :title="deciding.decision === 'approve' ? t('hrm.leave.approveTitle') : t('hrm.leave.rejectTitle')"
        :description="deciding.request.employee.name"
    />
    <LeaveCancelDialog :open="cancelling !== null" :request="cancelling" @update:open="!$event && (cancelling = null)" />
</template>
