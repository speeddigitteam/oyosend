<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, ScrollText } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CorrectionDialog from '../../Components/CorrectionDialog.vue';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import HrmSubNav from '../../Components/HrmSubNav.vue';
import type { Correction, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

type Filter = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'all';

const props = defineProps<{
    corrections: Paginated<Correction>;
    status: Filter;
    canRequest: boolean;
    windowDays: number;
    today: string;
}>();

const { t } = useI18n();
const format = useFormat();

const filter = computed<Filter>({
    get: () => props.status,
    set: (value) => router.get(route('hrm.attendance.corrections.index'), { status: value }, { preserveState: true, replace: true }),
});

const options = computed(() => (['pending', 'approved', 'rejected', 'cancelled', 'all'] as const).map((value) => ({
    value,
    label: value === 'all' ? t('hrm.common.all') : t(`hrm.status.correction.${value}`),
})));

const columns = computed<Column[]>(() => [
    { key: 'employee', label: t('hrm.fields.employee') },
    { key: 'date', label: t('hrm.fields.date') },
    { key: 'times', label: t('hrm.fields.times') },
    { key: 'reason', label: t('hrm.fields.reason'), hideable: true },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);

const requestOpen = ref(false);
const deciding = ref<{ correction: Correction; decision: 'approve' | 'reject' } | null>(null);
const decisionOpen = ref(false);
const cancelling = ref<Correction | null>(null);
const busy = ref(false);

function decide(correction: Correction, decision: 'approve' | 'reject'): void {
    deciding.value = { correction, decision };
    decisionOpen.value = true;
}

function cancel(): void {
    if (cancelling.value === null) {
        return;
    }

    busy.value = true;
    router.post(route('hrm.attendance.corrections.cancel', { correction: cancelling.value.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; cancelling.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.corrections.title')" :description="t('hrm.corrections.description')">
        <template v-if="canRequest" #actions>
            <Button variant="primary" @click="requestOpen = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.correction.request.action') }}
            </Button>
        </template>
    </PageHeader>
    <HrmSubNav area="attendance" current="corrections" />

    <Card flush>
        <DataTable :columns="columns" :rows="corrections.data" row-key="id" :caption="t('hrm.corrections.caption')" storage-key="hrm-corrections">
            <template #toolbar>
                <SegmentedControl v-model="filter" :options="options" :label="t('hrm.fields.status')" />
            </template>
            <template #cell-employee="{ row }">
                <span class="block text-ink">{{ row.employee.name }}</span>
                <span class="tabular block text-xs text-ink-subtle">{{ row.employee.number }}</span>
            </template>
            <template #cell-date="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.date) }}</span></template>
            <template #cell-times="{ row }">
                <span class="tabular text-ink-muted">{{ format.date(row.clockInAt, 'time') }} - {{ format.date(row.clockOutAt, 'time') }}</span>
            </template>
            <template #cell-reason="{ row }">
                <span class="block max-w-64 text-ink-muted">{{ row.reason }}</span>
                <span v-if="row.decisionNote" class="block max-w-64 text-xs text-ink-subtle">{{ t('hrm.fields.decisionNote') }}: {{ row.decisionNote }}</span>
            </template>
            <template #cell-status="{ row }"><HrmStatusBadge kind="correction" :value="row.status" /></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <template v-if="row.abilities?.decide">
                        <Button size="sm" variant="ghost" @click="decide(row, 'approve')">{{ t('hrm.decision.approve') }}</Button>
                        <Button size="sm" variant="ghost" @click="decide(row, 'reject')">{{ t('hrm.decision.reject') }}</Button>
                    </template>
                    <Button v-if="row.abilities?.cancel" size="sm" variant="ghost" @click="cancelling = row">{{ t('hrm.corrections.cancelAction') }}</Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('hrm.corrections.empty')" :description="t('hrm.corrections.emptyBody')">
                    <template #icon><ScrollText /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="corrections" />
    </Card>

    <CorrectionDialog v-if="canRequest" v-model:open="requestOpen" :today="today" :window-days="windowDays" />
    <DecisionDialog
        v-if="deciding"
        v-model:open="decisionOpen"
        :url="route('hrm.attendance.corrections.decision', { correction: deciding.correction.id })"
        :decision="deciding.decision"
        :title="deciding.decision === 'approve' ? t('hrm.corrections.approveTitle') : t('hrm.corrections.rejectTitle')"
        :description="t('hrm.corrections.decideFor', { name: deciding.correction.employee.name, date: format.date(deciding.correction.date) })"
    />
    <ConfirmDialog
        :open="cancelling !== null"
        :title="t('hrm.corrections.cancelTitle')"
        :description="t('hrm.corrections.cancelBody')"
        :confirm-label="t('hrm.corrections.cancelAction')"
        :loading="busy"
        @update:open="!$event && (cancelling = null)"
        @confirm="cancel"
    />
</template>
