<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ComponentDialog from '../../Components/ComponentDialog.vue';
import StructureDialog from '../../Components/StructureDialog.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import type { Component, Structure } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    structure: Structure;
    components: Component[];
    options: { kinds: string[]; calcs: string[] };
    currency: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();

const editingStructure = ref(false);
const formOpen = ref(false);
const editing = ref<Component | null>(null);
const removing = ref<Component | null>(null);
const busy = ref(false);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'kind', label: t('payroll.fields.kind') },
    { key: 'value', label: t('payroll.fields.amount'), align: 'right' },
    { key: 'taxable', label: t('payroll.fields.taxable'), hideable: true },
    { key: 'validity', label: t('payroll.structures.validity'), hideable: true },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);

function add(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(component: Component): void {
    editing.value = component;
    formOpen.value = true;
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('payroll.structures.components.destroy', { structure: props.structure.id, component: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; removing.value = null; },
    });
}

function validity(component: Component): string {
    if (component.effectiveFrom === null && component.effectiveTo === null) {
        return t('payroll.structures.always');
    }

    return `${component.effectiveFrom ? format.date(component.effectiveFrom) : t('payroll.common.dash')} - ${component.effectiveTo ? format.date(component.effectiveTo) : t('payroll.common.dash')}`;
}
</script>

<template>
    <PageHeader :eyebrow="t('payroll.structures.title')" :title="structure.name" :description="structure.description ?? undefined">
        <template v-if="canManage" #actions>
            <Button @click="editingStructure = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.common.edit') }}
            </Button>
            <Button variant="primary" @click="add">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.structures.addComponent') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3">
        <p class="text-sm text-ink-muted">
            <Badge :tone="structure.isActive ? 'ok' : 'neutral'" dot>{{ structure.isActive ? t('payroll.common.active') : t('payroll.common.inactive') }}</Badge>
            <span class="ml-2">{{ t('payroll.structures.basicNote') }}</span>
        </p>

        <Card flush>
            <DataTable :columns="columns" :rows="components" row-key="id" :caption="t('payroll.structures.componentsCaption')">
                <template #cell-name="{ row }">
                    <span class="text-ink">{{ row.name }}</span>
                    <span class="ml-1 font-mono text-xs text-ink-subtle">{{ row.code }}</span>
                    <Badge v-if="!row.isActive" class="ml-1">{{ t('payroll.common.inactive') }}</Badge>
                </template>
                <template #cell-kind="{ row }">
                    <Badge :tone="row.kind === 'earning' ? 'ok' : 'warn'">{{ t(`payroll.componentKinds.${row.kind}`) }}</Badge>
                </template>
                <template #cell-value="{ row }">
                    <span v-if="row.calc === 'fixed'" class="tabular font-mono">{{ pay.money(row.amount ?? 0, currency) }}</span>
                    <span v-else class="tabular font-mono">{{ t('payroll.structures.percentOf', { rate: pay.percent(row.rateBps ?? 0) }) }}</span>
                </template>
                <template #cell-taxable="{ row }"><span class="text-ink-muted">{{ row.isTaxable ? t('payroll.common.yes') : t('payroll.common.no') }}</span></template>
                <template #cell-validity="{ row }"><span class="tabular text-ink-muted">{{ validity(row) }}</span></template>
                <template #cell-actions="{ row }">
                    <div v-if="canManage" class="flex justify-end gap-1">
                        <Button size="sm" variant="ghost" @click="edit(row)">
                            <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                            {{ t('payroll.common.edit') }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="removing = row">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            {{ t('payroll.common.remove') }}
                        </Button>
                    </div>
                </template>
                <template #empty>
                    <EmptyState :title="t('payroll.structures.noComponents')" :description="t(canManage ? 'payroll.structures.noComponentsBody' : 'payroll.structures.noComponentsViewOnly')">
                        <Button v-if="canManage" variant="primary" @click="add">{{ t('payroll.structures.addComponent') }}</Button>
                    </EmptyState>
                </template>
            </DataTable>
        </Card>
    </div>

    <template v-if="canManage">
        <StructureDialog v-model:open="editingStructure" :structure="structure" />
        <ComponentDialog
            v-model:open="formOpen"
            :structure-id="structure.id"
            :component="editing"
            :currency="currency"
            :kinds="options.kinds"
            :calcs="options.calcs"
        />
        <ConfirmDialog
            :open="removing !== null"
            :title="t('payroll.structures.removeTitle')"
            :description="t('payroll.structures.removeBody', { name: removing?.name ?? '' })"
            :confirm-label="t('payroll.common.remove')"
            :loading="busy"
            @update:open="!$event && (removing = null)"
            @confirm="remove"
        />
    </template>
</template>
