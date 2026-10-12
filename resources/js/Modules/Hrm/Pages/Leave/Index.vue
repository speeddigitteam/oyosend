<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import HrmSubNav from '../../Components/HrmSubNav.vue';
import LeaveCancelDialog from '../../Components/LeaveCancelDialog.vue';
import LeaveRequestDialog from '../../Components/LeaveRequestDialog.vue';
import LeaveRequestsTable from '../../Components/LeaveRequestsTable.vue';
import type { LeaveRequest, LeaveType, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    requests: Paginated<LeaveRequest>;
    filters: { status: string; employee_id: string; leave_type_id: string; year: number | null };
    types: LeaveType[];
    abilities: { request: boolean; manage: boolean; approve: boolean };
    sessions: string[];
    today: string;
}>();

const { t } = useI18n();

const status = ref(props.filters.status);
const type = ref(props.filters.leave_type_id);
const year = ref(props.filters.year === null ? '' : String(props.filters.year));

const statusOptions = computed(() => [
    { value: 'all', label: t('hrm.common.all') },
    { value: 'pending', label: t('hrm.leave.pendingAny') },
    ...(['pending_manager', 'pending_hr', 'approved', 'rejected', 'cancelled'] as const).map((value) => ({ value, label: t(`hrm.status.leave.${value}`) })),
]);
const typeOptions = computed(() => [{ value: '', label: t('hrm.common.all') }, ...props.types.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => status.value !== 'all' || type.value !== '' || year.value !== '');

const requestOpen = ref(false);
const deciding = ref<{ request: LeaveRequest; decision: 'approve' | 'reject' } | null>(null);
const decisionOpen = ref(false);
const cancelling = ref<LeaveRequest | null>(null);

function apply(): void {
    router.get(route('hrm.leave.index'), {
        status: status.value === 'all' ? undefined : status.value,
        leave_type_id: type.value || undefined,
        year: year.value || undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'all';
    type.value = '';
    year.value = '';
    apply();
}

function decide(request: LeaveRequest, decision: 'approve' | 'reject'): void {
    deciding.value = { request, decision };
    decisionOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.leave.title')" :description="t('hrm.leave.description')">
        <template v-if="abilities.request" #actions>
            <Button variant="primary" @click="requestOpen = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.leave.request.action') }}
            </Button>
        </template>
    </PageHeader>
    <HrmSubNav area="leave" current="requests" />

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="status" :options="statusOptions" :label="t('hrm.fields.status')" class="w-full sm:w-48" />
            <Select v-model="type" :options="typeOptions" :label="t('hrm.fields.leaveType')" class="w-full sm:w-48" />
            <Input v-model="year" type="number" min="2000" max="2100" :label="t('hrm.fields.leaveYear')" class="w-full sm:w-32" />
            <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
            <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('hrm.common.clearFilters') }}</Button>
        </form>
        <LeaveRequestsTable
            :requests="requests"
            :caption="t('hrm.leave.caption')"
            :empty-title="filtered ? t('hrm.common.noMatches') : t('hrm.leave.empty')"
            :empty-body="filtered ? t('hrm.common.noMatchesBody') : t('hrm.leave.emptyBody')"
            storage-key="hrm-leave"
            @approve="decide($event, 'approve')"
            @reject="decide($event, 'reject')"
            @cancel="cancelling = $event"
        />
    </Card>

    <LeaveRequestDialog v-if="abilities.request" v-model:open="requestOpen" :types="types" :today="today" />
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
