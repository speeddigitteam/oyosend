<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, Search, Target } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CrmStatusBadge from '../../Components/CrmStatusBadge.vue';
import LeadFormDialog from '../../Components/LeadFormDialog.vue';
import type { LeadListProps, LeadStatus } from '../../Components/types';

defineOptions({ layout: TenantLayout });
const props = defineProps<LeadListProps>();
const { t } = useI18n();
const format = useFormat();
const loading = ref(false);
const formOpen = ref(false);
const search = ref(props.filters.search);
const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');
const segment = ref(props.filters.segment ?? '');

const statusOptions = computed(() => [
    { value: '', label: t('crm.common.all') },
    ...(['new', 'contacted', 'qualified', 'disqualified', 'converted'] as LeadStatus[]).map((value) => ({ value, label: t(`crm.status.${value}`) })),
]);
const sourceOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...props.sources.map((item) => ({ value: item.id, label: item.name }))]);
const segmentOptions = computed(() => [{ value: '', label: t('crm.common.all') }, ...props.segments.map((item) => ({ value: item.id, label: item.name }))]);
const filtered = computed(() => Boolean(props.filters.search || props.filters.status || props.filters.source || props.filters.segment));
const count = computed(() => t(`crm.leads.count.${props.leads.total === 1 ? 'one' : 'other'}`, { count: format.number(props.leads.total) }));
const columns = computed<Column[]>(() => [
    { key: 'title', label: t('crm.fields.title') },
    { key: 'status', label: t('crm.fields.status') },
    { key: 'score', label: t('crm.fields.score'), align: 'right', hideable: true },
    { key: 'contact', label: t('crm.fields.contact'), hideable: true, hiddenByDefault: true },
    { key: 'company', label: t('crm.fields.company'), hideable: true, hiddenByDefault: true },
    { key: 'owner', label: t('crm.fields.owner'), hideable: true },
    { key: 'actions', label: t('crm.common.actions'), srOnly: true, align: 'right' },
]);

function apply(): void {
    router.get(route('crm.leads.index'), { search: search.value || undefined, status: status.value || undefined, source: source.value || undefined, segment: segment.value || undefined }, {
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function clear(): void {
    search.value = '';
    status.value = '';
    source.value = '';
    segment.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('crm.eyebrow')" :title="t('crm.leads.title')" :description="t('crm.leads.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" @click="formOpen = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('crm.leads.add') }}</Button>
        </template>
    </PageHeader>

    <Card v-if="leads.total === 0 && !filtered">
        <EmptyState :title="t('crm.leads.empty.title')" :description="canCreate ? t('crm.leads.empty.body') : t('crm.leads.empty.viewOnly')">
            <template #icon><Target /></template>
            <Button v-if="canCreate" size="sm" variant="primary" @click="formOpen = true">{{ t('crm.leads.add') }}</Button>
        </EmptyState>
    </Card>

    <Card v-else flush :aria-busy="loading || undefined">
        <DataTable :columns="columns" :rows="leads.data" row-key="id" :caption="t('crm.leads.caption')" :loading="loading" storage-key="crm-leads">
            <template #toolbar>
                <form class="flex min-w-0 flex-1 flex-wrap items-end gap-2" @submit.prevent="apply">
                    <Input v-model="search" :label="t('crm.leads.search')" class="min-w-48 flex-1" type="search"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input>
                    <Select v-model="status" :options="statusOptions" :label="t('crm.fields.status')" class="w-full sm:w-40" />
                    <Select v-model="source" :options="sourceOptions" :label="t('crm.fields.source')" class="w-full sm:w-44" />
                    <Select v-if="segments.length" v-model="segment" :options="segmentOptions" :label="t('crm.settings.segments')" class="w-full sm:w-44" />
                    <Button type="submit" size="sm" :loading="loading">{{ t('crm.common.search') }}</Button>
                    <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('crm.common.clearFilters') }}</Button>
                </form>
                <p class="eyebrow tabular" role="status">{{ count }}</p>
            </template>
            <template #cell-title="{ row }"><Button variant="ghost" size="sm" :href="route('crm.leads.show', { lead: row.id })" class="max-w-64 justify-start truncate">{{ row.title }}</Button></template>
            <template #cell-status="{ row }"><CrmStatusBadge :status="row.status" /></template>
            <template #cell-score="{ row }"><span class="tabular font-mono text-ink-muted">{{ format.number(row.score) }}</span></template>
            <template #cell-contact="{ row }"><span class="text-ink-muted">{{ row.contact?.name ?? '—' }}</span></template>
            <template #cell-company="{ row }"><span class="text-ink-muted">{{ row.company?.name ?? '—' }}</span></template>
            <template #cell-owner="{ row }"><span class="text-ink-muted">{{ row.owner?.name ?? t('crm.common.unassigned') }}</span></template>
            <template #cell-actions="{ row }"><Button size="sm" variant="ghost" :href="route('crm.leads.show', { lead: row.id })">{{ t('crm.common.view') }}</Button></template>
            <template #empty><EmptyState :title="t('crm.common.noMatches')" :description="t('crm.common.noMatchesBody')"><template #icon><Search /></template><Button size="sm" @click="clear">{{ t('crm.common.clearFilters') }}</Button></EmptyState></template>
        </DataTable>
        <Pagination :meta="leads" />
        <template v-if="!canCreate" #footer><p class="text-[0.8125rem] text-ink-subtle">{{ t('crm.common.viewOnly') }}</p></template>
    </Card>

    <LeadFormDialog v-if="canCreate" v-model:open="formOpen" :sources="sources" :contacts="contacts" :companies="companies" :owners="owners" />
</template>
