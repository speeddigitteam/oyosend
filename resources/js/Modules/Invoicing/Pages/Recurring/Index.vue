<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarSync, Pause, Pencil, Play, Plus, Square } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import InvoiceStatusBadge from '../../Components/InvoiceStatusBadge.vue';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import RecurringFormDialog from '../../Components/RecurringFormDialog.vue';
import type { InvoiceFormOptions, RecurringFrequency, RecurringStatus, RecurringTemplate } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    templates: Paginated<RecurringTemplate>;
    frequencies: RecurringFrequency[];
    canManage: boolean;
    form: InvoiceFormOptions;
}>();

const { t } = useI18n();
const format = useFormat();

const formOpen = ref(false);
const editing = ref<RecurringTemplate | null>(null);
const ending = ref<RecurringTemplate | null>(null);
const pending = ref<string | null>(null);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('invoicing.fields.templateName') },
    { key: 'customer', label: t('invoicing.fields.customer'), hideable: true },
    { key: 'schedule', label: t('invoicing.fields.schedule') },
    { key: 'nextRunOn', label: t('invoicing.fields.nextRun'), hideable: true },
    { key: 'status', label: t('invoicing.fields.status') },
    { key: 'total', label: t('invoicing.fields.total'), align: 'right' },
    { key: 'actions', label: t('invoicing.common.actions'), srOnly: true, align: 'right' },
]);

function schedule(template: RecurringTemplate): string {
    return t(`invoicing.schedule.${template.frequency}${template.every === 1 ? 'One' : 'Many'}`, { count: format.number(template.every) });
}

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(template: RecurringTemplate): void {
    editing.value = template;
    formOpen.value = true;
}

function setStatus(template: RecurringTemplate, status: RecurringStatus): void {
    pending.value = `${status}:${template.id}`;
    router.patch(route('invoicing.recurring.status', { template: template.id }), { status }, {
        preserveScroll: true,
        onFinish: () => { pending.value = null; ending.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="t('invoicing.recurring.title')" :description="t('invoicing.recurring.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="openCreate">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.recurring.create') }}
            </Button>
        </template>
    </PageHeader>
    <InvoicingNav current="recurring" />

    <Card flush>
        <DataTable :columns="columns" :rows="templates.data" row-key="id" :caption="t('invoicing.recurring.caption')" storage-key="invoicing-recurring">
            <template #cell-name="{ row }">
                <span class="block text-ink">{{ row.name }}</span>
                <span v-if="row.lastError" class="block max-w-64 text-xs text-danger">{{ row.lastError }}</span>
            </template>
            <template #cell-customer="{ row }"><span class="text-ink-muted">{{ row.customer.name ?? t('invoicing.common.notSet') }}</span></template>
            <template #cell-schedule="{ row }"><span class="text-ink-muted">{{ schedule(row) }}</span></template>
            <template #cell-nextRunOn="{ row }"><span class="tabular text-ink-muted">{{ row.nextRunOn ? format.date(row.nextRunOn) : t('invoicing.common.dash') }}</span></template>
            <template #cell-status="{ row }"><InvoiceStatusBadge :value="row.status" /></template>
            <template #cell-total="{ row }"><span class="tabular font-mono">{{ format.money(row.totalAmount, row.currency) }}</span></template>
            <template #cell-actions="{ row }">
                <div v-if="canManage && row.editable" class="flex justify-end gap-1">
                    <Button size="sm" variant="ghost" @click="openEdit(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('invoicing.common.edit') }}
                    </Button>
                    <Button v-if="row.status === 'active'" size="sm" variant="ghost" :loading="pending === `paused:${row.id}`" @click="setStatus(row, 'paused')">
                        <template #icon><Pause class="size-4" aria-hidden="true" /></template>
                        {{ t('invoicing.recurring.pause') }}
                    </Button>
                    <Button v-if="row.status === 'paused'" size="sm" variant="ghost" :loading="pending === `active:${row.id}`" @click="setStatus(row, 'active')">
                        <template #icon><Play class="size-4" aria-hidden="true" /></template>
                        {{ t('invoicing.recurring.resume') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="ending = row">
                        <template #icon><Square class="size-4" aria-hidden="true" /></template>
                        {{ t('invoicing.recurring.end') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('invoicing.recurring.empty')" :description="t(canManage ? 'invoicing.recurring.emptyBody' : 'invoicing.recurring.emptyViewOnly')">
                    <template #icon><CalendarSync /></template>
                    <Button v-if="canManage" variant="primary" @click="openCreate">{{ t('invoicing.recurring.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="templates" />
    </Card>

    <RecurringFormDialog v-if="canManage" v-model:open="formOpen" :options="form" :frequencies="frequencies" :template="editing" />
    <ConfirmDialog
        :open="ending !== null"
        :title="t('invoicing.recurring.endTitle')"
        :description="t('invoicing.recurring.endBody', { name: ending?.name ?? '' })"
        :confirm-label="t('invoicing.recurring.end')"
        :loading="pending !== null"
        @update:open="!$event && (ending = null)"
        @confirm="ending && setStatus(ending, 'ended')"
    />
</template>
