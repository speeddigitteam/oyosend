<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarCheck, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import HolidayDialog from '../../Components/HolidayDialog.vue';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { Holiday } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ year: number; holidays: Holiday[]; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const yearInput = ref(String(props.year));
const formOpen = ref(false);
const editing = ref<Holiday | null>(null);
const removing = ref<Holiday | null>(null);
const busy = ref(false);

const columns = computed<Column[]>(() => [
    { key: 'date', label: t('hrm.fields.date') },
    { key: 'weekday', label: t('hrm.fields.weekday'), hideable: true },
    { key: 'name', label: t('hrm.fields.name') },
    { key: 'type', label: t('hrm.fields.type') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);

function weekdayName(date: string): string {
    const day = new Date(`${date}T00:00:00Z`).getUTCDay();

    return hrm.weekday(day === 0 ? 7 : day, 'long');
}

function apply(): void {
    router.get(route('hrm.holidays.index'), { year: yearInput.value || undefined }, { preserveState: true, replace: true });
}

function add(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(holiday: Holiday): void {
    editing.value = holiday;
    formOpen.value = true;
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('hrm.holidays.destroy', { holiday: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; removing.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.holidays.title')" :description="t('hrm.holidays.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="add">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.holidays.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="holidays" row-key="id" :caption="t('hrm.holidays.caption')">
            <template #toolbar>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="yearInput" type="number" min="2000" max="2100" :label="t('hrm.fields.year')" class="w-full sm:w-32" />
                    <Button type="submit" size="sm">{{ t('hrm.common.apply') }}</Button>
                </form>
            </template>
            <template #cell-date="{ row }"><span class="tabular text-ink">{{ format.date(row.date) }}</span></template>
            <template #cell-weekday="{ row }"><span class="text-ink-muted">{{ weekdayName(row.date) }}</span></template>
            <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
            <template #cell-type="{ row }"><HrmStatusBadge kind="holiday" :value="row.type" /></template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-end gap-1">
                    <Button size="sm" variant="ghost" @click="edit(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="removing = row">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.common.remove') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('hrm.holidays.empty', { year: format.number(year, { useGrouping: false }) })" :description="t(canManage ? 'hrm.holidays.emptyBody' : 'hrm.holidays.emptyViewOnly')">
                    <template #icon><CalendarCheck /></template>
                    <Button v-if="canManage" variant="primary" @click="add">{{ t('hrm.holidays.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <HolidayDialog v-if="canManage" v-model:open="formOpen" :holiday="editing" :year="year" />
    <ConfirmDialog
        :open="removing !== null"
        :title="t('hrm.holidays.removeTitle')"
        :description="t('hrm.holidays.removeBody', { name: removing?.name ?? '' })"
        :confirm-label="t('hrm.common.remove')"
        :loading="busy"
        @update:open="!$event && (removing = null)"
        @confirm="remove"
    />
</template>
