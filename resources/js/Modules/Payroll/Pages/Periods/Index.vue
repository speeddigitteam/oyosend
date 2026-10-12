<script setup lang="ts">
import { CalendarRange, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import PeriodDialog from '../../Components/PeriodDialog.vue';
import type { Period } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    periods: Paginated<Period>;
    kinds: string[];
    today: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const creating = ref(false);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'range', label: t('payroll.fields.range') },
    { key: 'kind', label: t('payroll.fields.kind'), hideable: true },
    { key: 'payDate', label: t('payroll.fields.payDate'), hideable: true },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.periods.title')" :description="t('payroll.periods.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="creating = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.periods.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="periods.data" row-key="id" :caption="t('payroll.periods.caption')" storage-key="payroll-periods">
            <template #cell-name="{ row }">
                <Button variant="ghost" size="sm" :href="route('payroll.periods.show', { period: row.id })">{{ row.name }}</Button>
            </template>
            <template #cell-range="{ row }">
                <span class="tabular text-ink-muted">{{ format.date(row.startsOn) }} - {{ format.date(row.endsOn) }}</span>
            </template>
            <template #cell-kind="{ row }"><span class="text-ink-muted">{{ t(`payroll.periodKinds.${row.kind}`) }}</span></template>
            <template #cell-payDate="{ row }">
                <span class="tabular text-ink-muted">{{ row.payDate ? format.date(row.payDate) : t('payroll.common.dash') }}</span>
            </template>
            <template #cell-status="{ row }"><PayrollBadge kind="period" :value="row.status" /></template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('payroll.periods.show', { period: row.id })">{{ t('payroll.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="t('payroll.periods.empty')" :description="t(canManage ? 'payroll.periods.emptyBody' : 'payroll.periods.emptyViewOnly')">
                    <template #icon><CalendarRange /></template>
                    <Button v-if="canManage" variant="primary" @click="creating = true">{{ t('payroll.periods.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="periods" />
    </Card>

    <PeriodDialog v-if="canManage" v-model:open="creating" :period="null" :today="today" />
</template>
