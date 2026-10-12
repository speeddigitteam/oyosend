<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LayoutTemplate, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated, TemplateRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ templates: Paginated<TemplateRow>; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const deleting = ref<TemplateRow | null>(null);
const busy = ref(false);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('emailMarketing.fields.name') },
    { key: 'subject', label: t('emailMarketing.fields.subject'), hideable: true },
    { key: 'blocks', label: t('emailMarketing.fields.blocks'), hideable: true, align: 'right' },
    { key: 'updated', label: t('emailMarketing.fields.updated'), hideable: true },
    { key: 'actions', label: t('emailMarketing.common.actions'), srOnly: true, align: 'right' },
]);

function remove(): void {
    if (deleting.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('email_marketing.templates.destroy', { template: deleting.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.templates.title')" :description="t('emailMarketing.templates.description')">
        <template #actions>
            <Button v-if="canManage" variant="primary" :href="route('email_marketing.templates.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('emailMarketing.templates.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="templates.data" row-key="id" :caption="t('emailMarketing.templates.caption')">
            <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
            <template #cell-subject="{ row }"><span class="text-ink-muted">{{ row.subject ?? t('emailMarketing.common.dash') }}</span></template>
            <template #cell-blocks="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.blockCount) }}</span></template>
            <template #cell-updated="{ row }">
                <span class="tabular text-ink-muted">{{ row.updatedAt ? format.date(row.updatedAt, 'datetime') : t('emailMarketing.common.dash') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <span class="flex justify-end gap-1">
                    <Button v-if="can('email_marketing.campaigns.create')" size="sm" variant="ghost" :href="route('email_marketing.campaigns.create', { template: row.id })">
                        {{ t('emailMarketing.templates.use') }}
                    </Button>
                    <Button v-if="canManage" size="sm" variant="ghost" :href="route('email_marketing.templates.edit', { template: row.id })">
                        {{ t('emailMarketing.common.edit') }}
                    </Button>
                    <Button v-if="canManage" size="sm" variant="ghost" @click="deleting = row">{{ t('emailMarketing.common.delete') }}</Button>
                </span>
            </template>
            <template #empty>
                <EmptyState :title="t('emailMarketing.templates.empty')" :description="t('emailMarketing.templates.emptyBody')" compact>
                    <template #icon><LayoutTemplate /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="templates" />
    </Card>

    <ConfirmDialog
        :open="deleting !== null"
        :title="t('emailMarketing.templates.deleteTitle')"
        :description="t('emailMarketing.templates.deleteBody')"
        :confirm-label="t('emailMarketing.common.delete')"
        :loading="busy"
        @update:open="deleting = $event ? deleting : null"
        @confirm="remove"
    />
</template>
