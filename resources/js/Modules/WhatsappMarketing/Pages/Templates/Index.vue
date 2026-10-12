<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FileText, Pencil, Plus, Trash2 } from 'lucide-vue-next';
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
import TemplateFormDialog from '../../Components/TemplateFormDialog.vue';
import TemplateStatusBadge from '../../Components/TemplateStatusBadge.vue';
import TemplateStatusDialog from '../../Components/TemplateStatusDialog.vue';
import type { TemplateRow } from '../../Components/types';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    templates: TemplateRow[];
    categories: string[];
    statuses: string[];
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const formOpen = ref(false);
const editing = ref<TemplateRow | null>(null);
const statusOpen = ref(false);
const statusTarget = ref<TemplateRow | null>(null);
const confirmOpen = ref(false);
const removing = ref<TemplateRow | null>(null);
const deleting = ref(false);

function create(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(template: TemplateRow): void {
    editing.value = template;
    formOpen.value = true;
}

function recordStatus(template: TemplateRow): void {
    statusTarget.value = template;
    statusOpen.value = true;
}

function askDelete(template: TemplateRow): void {
    removing.value = template;
    confirmOpen.value = true;
}

function destroy(): void {
    if (!removing.value) {
        return;
    }

    deleting.value = true;
    router.delete(route('whatsapp_marketing.templates.destroy', { template: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (deleting.value = false),
    });
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.templates.columns.template') },
    { key: 'status', label: t('whatsapp.templates.columns.status') },
    { key: 'category', label: t('whatsapp.templates.columns.category'), hideable: true },
    { key: 'variables', label: t('whatsapp.templates.columns.variables'), hideable: true },
    { key: 'body', label: t('whatsapp.templates.columns.body'), hideable: true },
    ...(props.canManage ? [{ key: 'actions', label: t('whatsapp.common.actions') }] : []),
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.templates.title')" :description="t('whatsapp.templates.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="create">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.templates.add') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="templates" />

    <p class="mb-3 rounded-card border border-line bg-hover px-4 py-3 text-sm text-ink-muted" data-testid="templates-note">
        {{ t('whatsapp.templates.note') }}
    </p>

    <Card flush>
        <DataTable :columns="columns" :rows="templates" row-key="id" :caption="t('whatsapp.templates.caption')" storage-key="whatsapp-templates">
            <template #cell-name="{ row }">
                <div class="min-w-0">
                    <p class="font-medium text-ink wrap-anywhere">{{ row.name }}</p>
                    <p class="text-xs text-ink-subtle">{{ row.language }}</p>
                </div>
            </template>
            <template #cell-status="{ row }">
                <div class="grid gap-1">
                    <TemplateStatusBadge :status="row.status" />
                    <span class="text-xs text-ink-subtle">
                        {{ t(row.statusSource === 'provider' ? 'whatsapp.templates.sourceWebhook' : 'whatsapp.templates.sourceAdmin') }}
                    </span>
                    <span v-if="row.statusChangedAt" class="tabular text-xs text-ink-subtle">{{ format.date(row.statusChangedAt, 'datetime') }}</span>
                    <span v-if="row.statusReason" class="text-xs text-ink-muted wrap-anywhere">{{ row.statusReason }}</span>
                </div>
            </template>
            <template #cell-category="{ row }">
                <Badge>{{ t(`whatsapp.templateCategory.${row.category}`) }}</Badge>
            </template>
            <template #cell-variables="{ row }">
                <span class="tabular text-ink-muted">{{ format.number(row.variableCount) }}</span>
            </template>
            <template #cell-body="{ row }">
                <div class="max-w-md min-w-0 text-sm">
                    <p v-if="row.header" class="font-medium text-ink wrap-anywhere">{{ row.header.text }}</p>
                    <p class="line-clamp-3 whitespace-pre-wrap text-ink-muted wrap-anywhere">{{ row.body }}</p>
                    <p v-if="row.footer" class="text-xs text-ink-subtle wrap-anywhere">{{ row.footer.text }}</p>
                    <ul v-if="row.buttons.length" class="mt-1 flex flex-wrap gap-1" :aria-label="t('whatsapp.templates.buttons')">
                        <li v-for="(button, index) in row.buttons" :key="index">
                            <Badge tone="info">{{ button.text }}</Badge>
                        </li>
                    </ul>
                </div>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex flex-wrap gap-1">
                    <Button size="sm" variant="ghost" @click="edit(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="recordStatus(row)">{{ t('whatsapp.templates.recordStatus') }}</Button>
                    <Button size="sm" variant="ghost" @click="askDelete(row)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.common.delete') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('whatsapp.templates.empty')" :description="t('whatsapp.templates.emptyBody')">
                    <template #icon><FileText /></template>
                    <Button v-if="canManage" variant="primary" @click="create">{{ t('whatsapp.templates.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <template v-if="canManage">
        <TemplateFormDialog v-model:open="formOpen" :template="editing" :categories="categories" :statuses="statuses" />
        <TemplateStatusDialog v-model:open="statusOpen" :template="statusTarget" :statuses="statuses" />
        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('whatsapp.templates.deleteTitle', { name: removing?.name ?? '' })"
            :description="t('whatsapp.templates.deleteBody')"
            :confirm-label="t('whatsapp.common.delete')"
            :loading="deleting"
            @confirm="destroy"
        />
    </template>
</template>
