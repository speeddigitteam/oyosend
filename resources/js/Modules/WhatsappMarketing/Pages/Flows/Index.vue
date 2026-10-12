<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Workflow } from 'lucide-vue-next';
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
import FlowFormDialog from '../../Components/FlowFormDialog.vue';
import type { AgentOption, FlowLimits, FlowRow, FlowStep } from '../../Components/types';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

defineProps<{
    flows: FlowRow[];
    agents: AgentOption[];
    limits: FlowLimits;
    placeholders: string[];
}>();

const { t } = useI18n();
const format = useFormat();

const dialog = ref(false);
const editing = ref<FlowRow | null>(null);
const confirmOpen = ref(false);
const removing = ref<FlowRow | null>(null);
const deleting = ref(false);

function create(): void {
    editing.value = null;
    dialog.value = true;
}

function edit(flow: FlowRow): void {
    editing.value = flow;
    dialog.value = true;
}

function askDelete(flow: FlowRow): void {
    removing.value = flow;
    confirmOpen.value = true;
}

function destroy(): void {
    if (!removing.value) {
        return;
    }

    deleting.value = true;
    router.delete(route('whatsapp_marketing.flows.destroy', { flow: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (deleting.value = false),
    });
}

/** A short, readable summary of a step list: "reply, tag, menu". */
function summary(steps: FlowStep[]): string {
    return steps.map((step) => t(`whatsapp.flows.step.${step.type}`)).join(', ');
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.flows.columns.name') },
    { key: 'trigger', label: t('whatsapp.flows.columns.trigger') },
    { key: 'steps', label: t('whatsapp.flows.columns.steps'), hideable: true },
    { key: 'priority', label: t('whatsapp.flows.columns.priority'), hideable: true },
    { key: 'active', label: t('whatsapp.flows.columns.active') },
    { key: 'actions', label: t('whatsapp.common.actions') },
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.flows.title')" :description="t('whatsapp.flows.description')">
        <template #actions>
            <Button variant="primary" @click="create">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.flows.add') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="flows" />

    <Card class="mb-3" :title="t('whatsapp.flows.howTitle')">
        <ul class="grid gap-1.5 text-sm text-ink-muted" data-testid="flows-notes">
            <li>{{ t('whatsapp.flows.noteStop') }}</li>
            <li>{{ t('whatsapp.flows.notePerson') }}</li>
            <li>{{ t('whatsapp.flows.noteOrder') }}</li>
            <li>{{ t('whatsapp.flows.noteData') }}</li>
        </ul>
    </Card>

    <Card flush>
        <DataTable :columns="columns" :rows="flows" row-key="id" :caption="t('whatsapp.flows.caption')" storage-key="whatsapp-flows">
            <template #cell-name="{ row }"><span class="font-medium text-ink wrap-anywhere">{{ row.name }}</span></template>
            <template #cell-trigger="{ row }">
                <div class="grid gap-1">
                    <span class="text-ink">{{ row.trigger === 'welcome' ? t('whatsapp.flows.triggerWelcome') : t('whatsapp.flows.triggerKeyword') }}</span>
                    <span v-if="row.trigger === 'keyword'" class="text-xs text-ink-muted wrap-anywhere">
                        {{ row.matchType === 'exact' ? t('whatsapp.flows.matchExact') : t('whatsapp.flows.matchContains') }}: {{ row.keywords.join(', ') }}
                    </span>
                </div>
            </template>
            <template #cell-steps="{ row }"><span class="text-ink-muted">{{ summary(row.actions) }}</span></template>
            <template #cell-priority="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.priority) }}</span></template>
            <template #cell-active="{ row }">
                <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('whatsapp.common.active') : t('whatsapp.common.inactive') }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Button size="sm" variant="ghost" @click="edit(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="askDelete(row)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.common.delete') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('whatsapp.flows.empty')" :description="t('whatsapp.flows.emptyBody')">
                    <template #icon><Workflow /></template>
                    <Button variant="primary" @click="create">{{ t('whatsapp.flows.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <FlowFormDialog v-model:open="dialog" :flow="editing" :agents="agents" :limits="limits" />
    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="t('whatsapp.flows.deleteTitle', { name: removing?.name ?? '' })"
        :description="t('whatsapp.flows.deleteBody')"
        :confirm-label="t('whatsapp.common.delete')"
        :loading="deleting"
        @confirm="destroy"
    />
</template>
