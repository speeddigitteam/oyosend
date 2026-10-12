<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';
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
import EmailBadge from '../../Components/EmailBadge.vue';
import type { Paginated, SequenceRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ sequences: Paginated<SequenceRow>; canManage: boolean; canActivate: boolean }>();

const { t } = useI18n();
const format = useFormat();

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('emailMarketing.fields.name') },
    { key: 'status', label: t('emailMarketing.fields.status') },
    { key: 'list', label: t('emailMarketing.fields.list'), hideable: true },
    { key: 'steps', label: t('emailMarketing.sequences.steps'), hideable: true, align: 'right' },
    { key: 'enrolled', label: t('emailMarketing.sequences.active'), hideable: true, align: 'right' },
]);
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.sequences.title')" :description="t('emailMarketing.sequences.description')">
        <template #actions>
            <Button v-if="canManage" variant="primary" :href="route('email_marketing.sequences.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('emailMarketing.sequences.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="sequences.data" row-key="id" :caption="t('emailMarketing.sequences.caption')">
            <template #cell-name="{ row }">
                <Link :href="route('email_marketing.sequences.show', { sequence: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
            </template>
            <template #cell-status="{ row }"><EmailBadge kind="sequence" :value="row.status" /></template>
            <template #cell-list="{ row }"><span class="text-ink-muted">{{ row.listName }}</span></template>
            <template #cell-steps="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.stepCount) }}</span></template>
            <template #cell-enrolled="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.enrollments.active) }}</span></template>
            <template #empty>
                <EmptyState :title="t('emailMarketing.sequences.empty')" :description="t('emailMarketing.sequences.emptyBody')" compact>
                    <template #icon><Workflow /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="sequences" />
    </Card>
</template>
