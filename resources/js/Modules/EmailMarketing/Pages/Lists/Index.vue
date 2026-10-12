<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ListChecks, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import EmailBadge from '../../Components/EmailBadge.vue';
import type { ListRow, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ lists: Paginated<ListRow>; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();

const open = ref(false);
const form = useForm({ name: '', description: '' });

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('emailMarketing.fields.name') },
    { key: 'status', label: t('emailMarketing.fields.status') },
    { key: 'subscribed', label: t('emailMarketing.status.subscription.subscribed'), align: 'right', hideable: true },
    { key: 'pending', label: t('emailMarketing.status.subscription.pending'), align: 'right', hideable: true },
    { key: 'left', label: t('emailMarketing.lists.left'), align: 'right', hideable: true },
]);

function create(): void {
    form.transform((data) => ({ name: data.name.trim(), description: data.description.trim() === '' ? null : data.description.trim() })).post(
        route('email_marketing.lists.store'),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                form.reset();
            },
        },
    );
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.lists.title')" :description="t('emailMarketing.lists.description')">
        <template #actions>
            <Button v-if="canManage" variant="primary" @click="open = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('emailMarketing.lists.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="lists.data" row-key="id" :caption="t('emailMarketing.lists.caption')">
            <template #cell-name="{ row }">
                <Link :href="route('email_marketing.lists.show', { list: row.id })" class="text-ink hover:text-brand-text">
                    {{ row.name }}
                </Link>
                <span v-if="row.description" class="block max-w-md truncate text-xs text-ink-subtle">{{ row.description }}</span>
            </template>
            <template #cell-status="{ row }"><EmailBadge kind="list" :value="row.status" /></template>
            <template #cell-subscribed="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.counts.subscribed) }}</span></template>
            <template #cell-pending="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.counts.pending) }}</span></template>
            <template #cell-left="{ row }">
                <span class="tabular text-ink-muted">{{ format.number(row.counts.unsubscribed + row.counts.bounced + row.counts.complained) }}</span>
            </template>
            <template #empty>
                <EmptyState :title="t('emailMarketing.lists.empty')" :description="t('emailMarketing.lists.emptyBody')" compact>
                    <template #icon><ListChecks /></template>
                    <Button v-if="canManage" size="sm" variant="primary" @click="open = true">{{ t('emailMarketing.lists.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="lists" />
    </Card>

    <Modal :open="open" :title="t('emailMarketing.lists.add')" size="sm" @update:open="open = $event">
        <form id="new-list" class="grid gap-4" novalidate @submit.prevent="create">
            <Input v-model="form.name" :label="t('emailMarketing.fields.name')" maxlength="120" required autofocus :error="form.errors.name" />
            <Textarea v-model="form.description" :label="t('emailMarketing.fields.description')" maxlength="500" optional :error="form.errors.description" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button type="submit" form="new-list" variant="primary" :loading="form.processing" :disabled="form.name.trim() === ''">
                {{ t('emailMarketing.common.save') }}
            </Button>
        </template>
    </Modal>
</template>
