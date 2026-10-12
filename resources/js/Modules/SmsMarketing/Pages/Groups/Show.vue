<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Download, FileUp, Pencil, Trash2, UserMinus, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
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
import type { Candidate, GroupRow, ImportRow, MemberRow, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    group: GroupRow;
    members: Paginated<MemberRow>;
    candidates: Candidate[];
    filters: { contact_q: string | null };
    imports: ImportRow[];
    importInfo: { columns: string[]; maxRows: number; maxBytes: number };
    contacts: { limit: number | null; used: number };
    canManage: boolean;
    /** Members whose contact the viewer may not see: counted, never listed. */
    hiddenMembers?: number;
}>();

const { t } = useI18n();
const format = useFormat();

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('smsMarketing.fields.contact') },
    { key: 'phone', label: t('smsMarketing.fields.phone') },
    { key: 'email', label: t('smsMarketing.fields.email'), hideable: true, hiddenByDefault: true },
    { key: 'actions', label: t('smsMarketing.common.actions'), srOnly: true },
]);

// Search for contacts to add
const search = ref(props.filters.contact_q ?? '');
const picked = ref<string[]>([]);

function find(): void {
    router.get(
        route('sms_marketing.groups.show', { group: props.group.id }),
        { contact_q: search.value.trim() || undefined },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function toggle(id: string, on: boolean): void {
    picked.value = on ? [...new Set([...picked.value, id])] : picked.value.filter((item) => item !== id);
}

function addPicked(): void {
    router.post(
        route('sms_marketing.groups.members.store', { group: props.group.id }),
        { contact_ids: picked.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                picked.value = [];
            },
        },
    );
}

function removeMember(member: MemberRow): void {
    router.delete(route('sms_marketing.groups.members.destroy', { group: props.group.id, member: member.id }), { preserveScroll: true });
}

// Rename / delete
const editOpen = ref(false);
const edit = useForm({ name: props.group.name, description: props.group.description ?? '' });
const deleteOpen = ref(false);
const busy = ref(false);

function saveEdit(): void {
    edit.transform((data) => ({ name: data.name.trim(), description: data.description.trim() === '' ? null : data.description.trim() })).put(
        route('sms_marketing.groups.update', { group: props.group.id }),
        { preserveScroll: true, onSuccess: () => (editOpen.value = false) },
    );
}

function remove(): void {
    busy.value = true;
    router.delete(route('sms_marketing.groups.destroy', { group: props.group.id }), {
        onFinish: () => {
            busy.value = false;
            deleteOpen.value = false;
        },
    });
}

// CSV import
const upload = useForm<{ file: File | null }>({ file: null });

function choose(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitImport(): void {
    upload.post(route('sms_marketing.groups.import', { group: props.group.id }), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => upload.reset(),
    });
}

const contactsLine = computed(() =>
    props.contacts.limit === null
        ? t('smsMarketing.groups.contactsUnlimited', { used: format.number(props.contacts.used) })
        : t('smsMarketing.groups.contactsOf', { used: format.number(props.contacts.used), limit: format.number(props.contacts.limit) }),
);
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="group.name" :description="group.description ?? t('smsMarketing.groups.showDescription')">
        <template #actions>
            <a
                :href="route('sms_marketing.groups.export', { group: group.id })"
                class="inline-flex h-[2.375rem] items-center justify-center gap-2 rounded-control border border-line-strong bg-control px-4 text-sm font-medium whitespace-nowrap text-ink hover:bg-control-hover"
            >
                <Download class="size-4" aria-hidden="true" />
                {{ t('smsMarketing.groups.export') }}
            </a>
            <Button v-if="canManage" @click="editOpen = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.common.edit') }}
            </Button>
            <Button v-if="canManage" variant="ghost" @click="deleteOpen = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.common.delete') }}
            </Button>
        </template>
    </PageHeader>

    <p class="mb-3 text-[0.8125rem] text-ink-muted">{{ contactsLine }}</p>

    <div class="grid gap-3 lg:grid-cols-3">
        <Card flush class="lg:col-span-2" :title="t('smsMarketing.groups.membersTitle', { count: format.number(group.membersCount) })">
            <p v-if="(hiddenMembers ?? 0) > 0" class="border-b border-line px-5 py-3 text-[0.8125rem] text-ink-muted" data-testid="hidden-members">
                {{ t('smsMarketing.groups.hiddenMembers', { count: format.number(hiddenMembers ?? 0) }) }}
            </p>
            <DataTable :columns="columns" :rows="members.data" row-key="id" :caption="t('smsMarketing.groups.membersCaption')">
                <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                <template #cell-phone="{ row }">
                    <span class="tabular text-ink-muted">{{ row.phone ?? t('smsMarketing.common.dash') }}</span>
                    <Badge v-if="!row.validNumber" tone="warn" class="ml-2">{{ t('smsMarketing.groups.invalidNumber') }}</Badge>
                </template>
                <template #cell-email="{ row }"><span class="text-ink-muted">{{ row.email ?? t('smsMarketing.common.dash') }}</span></template>
                <template #cell-actions="{ row }">
                    <div v-if="canManage" class="flex justify-end">
                        <Button size="sm" variant="ghost" @click="removeMember(row)">
                            <template #icon><UserMinus class="size-3.5" aria-hidden="true" /></template>
                            {{ t('smsMarketing.groups.remove') }}
                        </Button>
                    </div>
                </template>
                <template #empty>
                    <EmptyState :title="t('smsMarketing.groups.noMembers')" :description="t('smsMarketing.groups.noMembersBody')" compact />
                </template>
            </DataTable>
            <Pagination :meta="members" />
        </Card>

        <div v-if="canManage" class="grid content-start gap-3">
            <Card :title="t('smsMarketing.groups.addContacts')" :description="t('smsMarketing.groups.addContactsHint')">
                <form class="grid gap-3" @submit.prevent="find">
                    <Input v-model="search" type="search" :label="t('smsMarketing.groups.findContacts')" maxlength="100" />
                    <Button type="submit" size="sm">{{ t('smsMarketing.common.search') }}</Button>
                </form>
                <ul v-if="candidates.length > 0" class="mt-3 grid gap-2" data-testid="candidates">
                    <li v-for="candidate in candidates" :key="candidate.id">
                        <Checkbox
                            :model-value="picked.includes(candidate.id)"
                            :label="candidate.name"
                            :description="candidate.phone ?? t('smsMarketing.groups.noPhone')"
                            @update:model-value="toggle(candidate.id, $event)"
                        />
                    </li>
                </ul>
                <p v-else-if="filters.contact_q" class="mt-3 text-[0.8125rem] text-ink-muted">{{ t('smsMarketing.groups.noCandidates') }}</p>
                <Button v-if="picked.length > 0" class="mt-3" size="sm" variant="primary" @click="addPicked">
                    <template #icon><UserPlus class="size-3.5" aria-hidden="true" /></template>
                    {{ t('smsMarketing.groups.addPicked', { count: format.number(picked.length) }) }}
                </Button>
            </Card>

            <Card
                :title="t('smsMarketing.groups.importTitle')"
                :description="t('smsMarketing.groups.importHint', { rows: format.number(importInfo.maxRows) })"
            >
                <form class="grid gap-3" novalidate @submit.prevent="submitImport">
                    <Input
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        :label="t('smsMarketing.groups.file')"
                        :hint="t('smsMarketing.groups.fileHint')"
                        :error="upload.errors.file"
                        @change="choose"
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <Button type="submit" variant="primary" :loading="upload.processing" :disabled="upload.file === null">
                            <template #icon><FileUp class="size-4" aria-hidden="true" /></template>
                            {{ t('smsMarketing.groups.import') }}
                        </Button>
                        <a :href="route('sms_marketing.groups.template')" class="text-[0.8125rem] text-brand-text hover:underline">
                            {{ t('smsMarketing.groups.template') }}
                        </a>
                    </div>
                </form>
            </Card>
        </div>
    </div>

    <Card v-if="imports.length > 0" class="mt-3" :title="t('smsMarketing.groups.importHistory')" flush>
        <ul class="divide-y divide-line" data-testid="imports">
            <li v-for="item in imports" :key="item.id" class="px-5 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="min-w-0 text-sm font-medium break-all text-ink">{{ item.fileName }}</span>
                    <span class="tabular ml-auto text-xs text-ink-subtle">{{ item.createdAt ? format.date(item.createdAt, 'datetime') : '' }}</span>
                </div>
                <p class="mt-1 text-[0.8125rem] text-ink-muted">
                    {{
                        t('smsMarketing.groups.importResult', {
                            added: format.number(item.addedRows),
                            failed: format.number(item.failedRows),
                            total: format.number(item.totalRows),
                        })
                    }}
                </p>
                <details v-if="item.errors.length > 0" class="mt-2 text-[0.8125rem]">
                    <summary class="cursor-pointer text-danger">{{ t('smsMarketing.groups.importErrors', { count: format.number(item.errors.length) }) }}</summary>
                    <ul class="mt-2 grid gap-1">
                        <li v-for="(error, index) in item.errors" :key="index" class="break-words text-ink-muted">
                            <span class="tabular mr-1 text-ink-subtle">{{ t('smsMarketing.groups.line', { line: format.number(error.line) }) }}</span>{{ error.message }}
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
    </Card>

    <SmsFormDialog
        v-model:open="editOpen"
        :title="t('smsMarketing.groups.edit')"
        :submit-label="t('smsMarketing.common.save')"
        :processing="edit.processing"
        :disabled="edit.name.trim() === ''"
        @submit="saveEdit"
    >
        <Input v-model="edit.name" :label="t('smsMarketing.fields.name')" maxlength="120" :error="edit.errors.name" required autofocus />
        <Textarea v-model="edit.description" :label="t('smsMarketing.fields.description')" maxlength="500" optional :error="edit.errors.description" />
    </SmsFormDialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('smsMarketing.groups.deleteTitle')"
        :description="t('smsMarketing.groups.deleteBody', { name: group.name })"
        :confirm-label="t('smsMarketing.common.delete')"
        :loading="busy"
        @confirm="remove"
    />
</template>
