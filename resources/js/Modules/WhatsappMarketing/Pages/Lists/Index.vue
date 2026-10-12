<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import FormDialog from '../../Components/FormDialog.vue';
import type { ListRow } from '../../Components/types';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

defineProps<{ lists: ListRow[]; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();

const dialog = ref(false);
const form = useForm({ name: '', description: '' });

function create(): void {
    form.reset();
    form.clearErrors();
    dialog.value = true;
}

function submit(): void {
    form.transform((data) => ({ name: data.name.trim(), description: data.description.trim() || null })).post(route('whatsapp_marketing.lists.store'), {
        preserveScroll: true,
        onSuccess: () => (dialog.value = false),
    });
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.lists.columns.name') },
    { key: 'description', label: t('whatsapp.lists.columns.description'), hideable: true },
    { key: 'members', label: t('whatsapp.lists.columns.members') },
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.lists.title')" :description="t('whatsapp.lists.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="create">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.lists.add') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="lists" />

    <Card flush>
        <DataTable :columns="columns" :rows="lists" row-key="id" :caption="t('whatsapp.lists.caption')" storage-key="whatsapp-lists">
            <template #cell-name="{ row }">
                <Link :href="route('whatsapp_marketing.lists.show', { list: row.id })" class="font-medium text-ink wrap-anywhere hover:underline">{{ row.name }}</Link>
            </template>
            <template #cell-description="{ row }">
                <span class="text-ink-muted wrap-anywhere">{{ row.description ?? t('whatsapp.common.none') }}</span>
            </template>
            <template #cell-members="{ row }"><span class="tabular text-ink">{{ format.number(row.memberCount) }}</span></template>
            <template #empty>
                <EmptyState :title="t('whatsapp.lists.empty')" :description="t('whatsapp.lists.emptyBody')">
                    <template #icon><UsersRound /></template>
                    <Button v-if="canManage" variant="primary" @click="create">{{ t('whatsapp.lists.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <FormDialog
        v-if="canManage"
        v-model:open="dialog"
        :title="t('whatsapp.lists.createTitle')"
        :submit-label="t('whatsapp.lists.create')"
        :processing="form.processing"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('whatsapp.lists.name')" :error="form.errors.name" maxlength="120" />
        <Textarea v-model="form.description" :label="t('whatsapp.lists.descriptionField')" :error="form.errors.description" optional :rows="3" maxlength="300" />
    </FormDialog>
</template>
