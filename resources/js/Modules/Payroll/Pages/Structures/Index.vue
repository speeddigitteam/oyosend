<script setup lang="ts">
import { Layers, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import StructureDialog from '../../Components/StructureDialog.vue';
import type { Structure } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ structures: Structure[]; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();
const creating = ref(false);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'code', label: t('payroll.fields.code'), hideable: true },
    { key: 'components', label: t('payroll.structures.components'), align: 'right' },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.structures.title')" :description="t('payroll.structures.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="creating = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.structures.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="structures" row-key="id" :caption="t('payroll.structures.caption')">
            <template #cell-name="{ row }">
                <Button variant="ghost" size="sm" :href="route('payroll.structures.show', { structure: row.id })">{{ row.name }}</Button>
            </template>
            <template #cell-code="{ row }"><span class="font-mono text-ink-muted">{{ row.code ?? t('payroll.common.dash') }}</span></template>
            <template #cell-components="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.componentCount ?? 0) }}</span></template>
            <template #cell-status="{ row }">
                <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('payroll.common.active') : t('payroll.common.inactive') }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('payroll.structures.show', { structure: row.id })">{{ t('payroll.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="t('payroll.structures.empty')" :description="t(canManage ? 'payroll.structures.emptyBody' : 'payroll.structures.emptyViewOnly')">
                    <template #icon><Layers /></template>
                    <Button v-if="canManage" variant="primary" @click="creating = true">{{ t('payroll.structures.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <StructureDialog v-if="canManage" v-model:open="creating" :structure="null" />
</template>
