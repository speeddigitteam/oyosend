<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarCheck2, Check, Pencil, Plus, Search, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ActivityFormDialog from '../../Components/ActivityFormDialog.vue';
import CrmStatusBadge from '../../Components/CrmStatusBadge.vue';
import type { Activity, ActivityListProps, ActivityStatus, ActivityType } from '../../Components/types';
import { usePrefillCreate } from '../../Components/usePrefillCreate';

defineOptions({ layout: TenantLayout });
const props = defineProps<ActivityListProps>(); const { t } = useI18n(); const format = useFormat();
const loading = ref(false); const { open: formOpen, initialContactId } = usePrefillCreate(props.prefillContact, props.canCreate); const target = ref<Activity | null>(null); const cancelTarget = ref<Activity | null>(null); const cancelBusy = ref(false);
const search = ref(props.filters.search); const status = ref(props.filters.status ?? ''); const type = ref(props.filters.type ?? ''); const overdue = ref(props.filters.overdue); const segment = ref(props.filters.segment ?? '');
const statusOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...(['planned', 'completed', 'canceled'] as ActivityStatus[]).map((value) => ({ value, label: t(`crm.status.${value}`) }))]);
const typeOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...(['task', 'meeting', 'call', 'follow_up'] as ActivityType[]).map((value) => ({ value, label: t(`crm.activityTypes.${value}`) }))]);
const segmentOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...props.segments.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => Boolean(props.filters.search || props.filters.status || props.filters.type || props.filters.overdue || props.filters.segment));
const count = computed(() => t(`crm.activities.count.${props.activities.total === 1 ? 'one' : 'other'}`, { count: format.number(props.activities.total) }));
const columns = computed<Column[]>(() => [{ key: 'subject', label: t('crm.fields.title') }, { key: 'type', label: t('crm.fields.type') }, { key: 'status', label: t('crm.fields.status') }, { key: 'priority', label: t('crm.fields.priority'), hideable: true }, { key: 'due', label: t('crm.fields.dueAt') }, { key: 'owner', label: t('crm.fields.owner'), hideable: true, hiddenByDefault: true }, { key: 'actions', label: t('crm.common.actions'), srOnly: true, align: 'right' }]);
function apply(): void { router.get(route('crm.activities.index'), { search: search.value || undefined, status: status.value || undefined, type: type.value || undefined, overdue: overdue.value || undefined, segment: segment.value || undefined }, { preserveState: true, replace: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } }); }
function clear(): void { search.value = ''; status.value = ''; type.value = ''; overdue.value = false; segment.value = ''; apply(); }
function create(): void { target.value = null; formOpen.value = true; }
function edit(activity: Activity): void { target.value = activity; formOpen.value = true; }
function complete(activity: Activity): void { router.post(route('crm.activities.complete', { activity: activity.id }), {}, { preserveScroll: true }); }
function cancel(): void { if (!cancelTarget.value) return; cancelBusy.value = true; router.post(route('crm.activities.cancel', { activity: cancelTarget.value.id }), {}, { preserveScroll: true, onFinish: () => { cancelBusy.value = false; cancelTarget.value = null; } }); }
function relation(activity: Activity): string | null { return activity.deal?.title ?? activity.lead?.title ?? activity.contact?.name ?? activity.company?.name ?? null; }
</script>

<template>
    <PageHeader :eyebrow="t('crm.eyebrow')" :title="t('crm.activities.title')" :description="t('crm.activities.description')"><template v-if="canCreate" #actions><Button variant="primary" @click="create"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('crm.activities.add') }}</Button></template></PageHeader>
    <Card v-if="activities.total === 0 && !filtered"><EmptyState :title="t('crm.activities.empty.title')" :description="canCreate ? t('crm.activities.empty.body') : t('crm.activities.empty.viewOnly')"><template #icon><CalendarCheck2 /></template><Button v-if="canCreate" size="sm" variant="primary" @click="create">{{ t('crm.activities.add') }}</Button></EmptyState></Card>
    <Card v-else flush :aria-busy="loading || undefined"><DataTable :columns="columns" :rows="activities.data" row-key="id" :caption="t('crm.activities.caption')" :loading="loading" storage-key="crm-activities">
        <template #toolbar><form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply"><Input v-model="search" :label="t('crm.activities.search')" class="min-w-48 flex-1" type="search"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input><Select v-model="status" :options="statusOptions" :label="t('crm.fields.status')" class="w-full sm:w-36" /><Select v-model="type" :options="typeOptions" :label="t('crm.fields.type')" class="w-full sm:w-36" /><Select v-if="segments.length" v-model="segment" :options="segmentOptions" :label="t('crm.settings.segments')" class="w-full sm:w-44" /><Checkbox v-model="overdue" :label="t('crm.activities.overdueOnly')" class="pb-2" /><Button type="submit" size="sm" :loading="loading">{{ t('crm.common.search') }}</Button><Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('crm.common.clearFilters') }}</Button></form><p class="eyebrow tabular" role="status">{{ count }}</p></template>
        <template #cell-subject="{ row }"><div class="max-w-72"><p class="truncate font-medium text-ink">{{ row.subject }}</p><p v-if="relation(row)" class="truncate text-xs text-ink-subtle">{{ t('crm.activities.relatedTo', { name: relation(row) }) }}</p></div></template>
        <template #cell-type="{ row }"><span class="text-ink-muted">{{ t(`crm.activityTypes.${row.type}`) }}</span></template><template #cell-status="{ row }"><CrmStatusBadge :status="row.status" /></template><template #cell-priority="{ row }"><CrmStatusBadge :status="row.priority" /></template>
        <template #cell-due="{ row }"><span :class="['tabular', row.status === 'planned' && row.dueAt && new Date(row.dueAt) < new Date() ? 'text-danger' : 'text-ink-muted']">{{ row.dueAt ? format.date(row.dueAt, 'datetime') : t('crm.activities.noDueDate') }}</span></template>
        <template #cell-owner="{ row }"><span class="text-ink-muted">{{ row.owner?.name ?? t('crm.common.unassigned') }}</span></template>
        <template #cell-actions="{ row }"><div v-if="row.editable" class="flex justify-end gap-1"><Button v-if="row.status === 'planned'" icon size="sm" variant="ghost" :aria-label="t('crm.activities.complete')" @click="complete(row)"><template #icon><Check class="size-4" aria-hidden="true" /></template></Button><Button v-if="row.status === 'planned'" icon size="sm" variant="ghost" :aria-label="t('crm.activities.cancel')" @click="cancelTarget = row"><template #icon><X class="size-4" aria-hidden="true" /></template></Button><Button icon size="sm" variant="ghost" :aria-label="t('crm.common.edit')" @click="edit(row)"><template #icon><Pencil class="size-4" aria-hidden="true" /></template></Button></div></template>
        <template #empty><EmptyState :title="t('crm.common.noMatches')" :description="t('crm.common.noMatchesBody')"><template #icon><Search /></template><Button size="sm" @click="clear">{{ t('crm.common.clearFilters') }}</Button></EmptyState></template>
    </DataTable><Pagination :meta="activities" /><template v-if="!canCreate" #footer><p class="text-[0.8125rem] text-ink-subtle">{{ t('crm.common.viewOnly') }}</p></template></Card>
    <ActivityFormDialog v-if="canCreate" v-model:open="formOpen" :activity="target" :leads="leads" :deals="deals" :contacts="contacts" :companies="companies" :owners="owners" :initial-contact-id="initialContactId" />
    <ConfirmDialog :open="cancelTarget !== null" :title="t('crm.activities.cancelTitle')" :description="cancelTarget ? t('crm.activities.cancelBody', { name: cancelTarget.subject }) : undefined" :confirm-label="t('crm.activities.cancel')" :loading="cancelBusy" tone="default" @update:open="!$event && (cancelTarget = null)" @confirm="cancel" />
</template>
