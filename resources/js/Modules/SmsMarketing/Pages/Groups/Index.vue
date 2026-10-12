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
import Pagination from '@/Core/Components/Pagination.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SmsFormDialog from '../../Components/SmsFormDialog.vue';
import type { GroupRow, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    groups: Paginated<GroupRow>;
    canManage: boolean;
    contacts: { limit: number | null; used: number };
}>();

const { t } = useI18n();
const format = useFormat();

const open = ref(false);
const form = useForm({ name: '', description: '' });

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('smsMarketing.fields.name') },
    { key: 'members', label: t('smsMarketing.fields.members') },
    { key: 'description', label: t('smsMarketing.fields.description'), hideable: true },
]);

const contactsLine = computed(() =>
    props.contacts.limit === null
        ? t('smsMarketing.groups.contactsUnlimited', { used: format.number(props.contacts.used) })
        : t('smsMarketing.groups.contactsOf', { used: format.number(props.contacts.used), limit: format.number(props.contacts.limit) }),
);

function create(): void {
    form.transform((data) => ({ name: data.name.trim(), description: data.description.trim() === '' ? null : data.description.trim() })).post(
        route('sms_marketing.groups.store'),
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
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="t('smsMarketing.groups.title')" :description="t('smsMarketing.groups.description')">
        <template #actions>
            <Button v-if="canManage" variant="primary" @click="open = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.groups.add') }}
            </Button>
        </template>
    </PageHeader>

    <p class="mb-3 text-[0.8125rem] text-ink-muted" data-testid="contacts-line">{{ contactsLine }}</p>

    <Card flush>
        <DataTable :columns="columns" :rows="groups.data" row-key="id" :caption="t('smsMarketing.groups.caption')">
            <template #cell-name="{ row }">
                <Link :href="route('sms_marketing.groups.show', { group: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
            </template>
            <template #cell-members="{ row }">
                <span class="tabular text-ink-muted">{{ t('smsMarketing.groups.members', { count: format.number(row.membersCount) }) }}</span>
            </template>
            <template #cell-description="{ row }"><span class="text-ink-muted">{{ row.description ?? t('smsMarketing.common.dash') }}</span></template>
            <template #empty>
                <EmptyState :title="t('smsMarketing.groups.empty')" :description="t('smsMarketing.groups.emptyBody')" compact>
                    <template #icon><UsersRound /></template>
                    <Button v-if="canManage" size="sm" variant="primary" @click="open = true">{{ t('smsMarketing.groups.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="groups" />
    </Card>

    <SmsFormDialog
        v-model:open="open"
        :title="t('smsMarketing.groups.add')"
        :submit-label="t('smsMarketing.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === ''"
        @submit="create"
    >
        <Input v-model="form.name" :label="t('smsMarketing.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
        <Textarea v-model="form.description" :label="t('smsMarketing.fields.description')" maxlength="500" optional :error="form.errors.description" />
    </SmsFormDialog>
</template>
