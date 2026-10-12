<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Tags } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { CategoryRow, QueueRow } from './types';

const props = defineProps<{ categories: CategoryRow[]; queues: QueueRow[] }>();

const { t } = useI18n();

const open = ref(false);
const editing = ref<CategoryRow | null>(null);
const form = useForm({ name: '', queue_id: '', is_active: true });

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('support.fields.name') },
    { key: 'queue', label: t('support.queues.defaultQueue') },
    { key: 'actions', label: t('support.common.actions'), srOnly: true, align: 'right' },
]);
const queueOptions = computed(() => [{ value: '', label: t('support.common.notSet') }, ...props.queues.map((queue) => ({ value: queue.id, label: queue.name }))]);
const queueName = (id: string | null): string => props.queues.find((queue) => queue.id === id)?.name ?? t('support.common.notSet');

function openForm(category: CategoryRow | null): void {
    editing.value = category;
    form.defaults({ name: category?.name ?? '', queue_id: category?.queueId ?? '', is_active: category?.isActive ?? true }).reset();
    form.clearErrors();
    open.value = true;
}

function save(): void {
    form.transform((data) => ({ ...data, queue_id: data.queue_id === '' ? null : data.queue_id }));
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        form.put(route('support.categories.update', { category: editing.value.id }), options);
    } else {
        form.post(route('support.categories.store'), options);
    }
}
</script>

<template>
    <Card :title="t('support.queues.categories')" :description="t('support.queues.categoriesHint')" flush>
        <template #actions>
            <Button size="sm" variant="primary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.queues.addCategory') }}
            </Button>
        </template>
        <DataTable :columns="columns" :rows="categories" row-key="id" :caption="t('support.queues.categories')">
            <template #cell-name="{ row }">
                <span class="flex flex-wrap items-center gap-2 font-medium text-ink">
                    {{ row.name }}
                    <Badge v-if="!row.isActive" tone="neutral">{{ t('support.common.inactive') }}</Badge>
                </span>
            </template>
            <template #cell-queue="{ row }">{{ queueName(row.queueId) }}</template>
            <template #cell-actions="{ row }">
                <Button icon size="sm" variant="ghost" :aria-label="t('support.queues.editFor', { name: row.name })" @click="openForm(row)">
                    <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                </Button>
            </template>
            <template #empty>
                <EmptyState :title="t('support.queues.noCategories')" compact>
                    <template #icon><Tags /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Modal v-model:open="open" size="sm" :title="t(editing ? 'support.queues.editCategory' : 'support.queues.addCategory')" :persistent="form.processing">
        <form id="support-category-form" class="grid gap-4" @submit.prevent="save">
            <Input v-model="form.name" :label="t('support.fields.name')" :error="form.errors.name" required maxlength="120" />
            <Select v-model="form.queue_id" :options="queueOptions" :label="t('support.queues.defaultQueue')" :hint="t('support.queues.defaultQueueHint')" :error="form.errors.queue_id" />
            <Checkbox v-model="form.is_active" :label="t('support.queues.categoryActive')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-category-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>
</template>
