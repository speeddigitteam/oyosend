<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SmsBadge from '../../Components/SmsBadge.vue';
import SmsFormDialog from '../../Components/SmsFormDialog.vue';
import type { RegistrationStatus, SenderRow, SenderType } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    senders: SenderRow[];
    types: SenderType[];
    statuses: RegistrationStatus[];
    canManage: boolean;
}>();

const { t } = useI18n();

const open = ref(false);
const editing = ref<SenderRow | null>(null);
const removing = ref<SenderRow | null>(null);
const removeOpen = ref(false);
const busy = ref(false);

const form = useForm({
    label: '',
    value: '',
    type: 'masking' as SenderType,
    registration_status: 'not_registered' as RegistrationStatus,
    registration_reference: '',
    registration_note: '',
});

const columns = computed<Column[]>(() => [
    { key: 'label', label: t('smsMarketing.fields.name') },
    { key: 'value', label: t('smsMarketing.fields.senderId') },
    { key: 'type', label: t('smsMarketing.fields.type') },
    { key: 'registration', label: t('smsMarketing.fields.registration') },
    { key: 'actions', label: t('smsMarketing.common.actions'), srOnly: true },
]);
const typeOptions = computed(() => props.types.map((value) => ({ value, label: t(`smsMarketing.senders.types.${value}`) })));
const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: t(`smsMarketing.status.registration.${value}`) })));

// The same shape rules as the server (SenderIdRules), so a wrong id is explained before it is sent.
const valueError = computed(() => {
    const value = form.value.trim();

    if (value === '') {
        return undefined;
    }

    const ok = form.type === 'masking' ? /^(?=.*[A-Za-z])[A-Za-z0-9]{3,11}$/.test(value) : /^\+?\d{3,15}$/.test(value);

    return ok ? undefined : t(`smsMarketing.senders.invalid.${form.type}`);
});
const invalid = computed(() => form.label.trim() === '' || form.value.trim() === '' || valueError.value !== undefined);

function openForm(row: SenderRow | null): void {
    editing.value = row;
    form.defaults({
        label: row?.label ?? '',
        value: row?.value ?? '',
        type: row?.type ?? 'masking',
        registration_status: row?.registrationStatus ?? 'not_registered',
        registration_reference: row?.registrationReference ?? '',
        registration_note: row?.registrationNote ?? '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        label: data.label.trim(),
        value: data.value.trim(),
        registration_reference: data.registration_reference.trim() === '' ? null : data.registration_reference.trim(),
        registration_note: data.registration_note.trim() === '' ? null : data.registration_note.trim(),
    }));
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (editing.value) {
        form.put(route('sms_marketing.senders.update', { sender: editing.value.id }), options);
    } else {
        form.post(route('sms_marketing.senders.store'), options);
    }
}

function askRemove(row: SenderRow): void {
    removing.value = row;
    removeOpen.value = true;
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    busy.value = true;
    router.delete(route('sms_marketing.senders.destroy', { sender: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            removeOpen.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="t('smsMarketing.senders.title')" :description="t('smsMarketing.senders.description')">
        <template #actions>
            <Button v-if="canManage" variant="primary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.senders.add') }}
            </Button>
        </template>
    </PageHeader>

    <p class="mb-3 rounded-card border border-line bg-info-soft px-4 py-3 text-[0.8125rem] text-ink" data-testid="registration-note">
        {{ t('smsMarketing.senders.notVerified') }}
    </p>

    <Card flush>
        <DataTable :columns="columns" :rows="senders" row-key="id" :caption="t('smsMarketing.senders.caption')">
            <template #cell-label="{ row }"><span class="text-ink">{{ row.label }}</span></template>
            <template #cell-value="{ row }"><code class="font-mono text-xs text-ink">{{ row.value }}</code></template>
            <template #cell-type="{ row }"><span class="text-ink-muted">{{ t(`smsMarketing.senders.types.${row.type}`) }}</span></template>
            <template #cell-registration="{ row }">
                <SmsBadge kind="registration" :value="row.registrationStatus" />
                <span v-if="row.registrationReference" class="mt-1 block text-xs text-ink-subtle">{{ row.registrationReference }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-end gap-1">
                    <Button size="sm" variant="ghost" @click="openForm(row)">
                        <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                        {{ t('smsMarketing.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="askRemove(row)">
                        <template #icon><Trash2 class="size-3.5" aria-hidden="true" /></template>
                        {{ t('smsMarketing.common.delete') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('smsMarketing.senders.empty')" :description="t('smsMarketing.senders.emptyBody')" compact>
                    <template #icon><BadgeCheck /></template>
                    <Button v-if="canManage" size="sm" variant="primary" @click="openForm(null)">{{ t('smsMarketing.senders.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <SmsFormDialog
        v-model:open="open"
        :title="editing ? t('smsMarketing.senders.edit') : t('smsMarketing.senders.add')"
        :submit-label="t('smsMarketing.common.save')"
        :processing="form.processing"
        :disabled="invalid"
        @submit="submit"
    >
        <Input v-model="form.label" :label="t('smsMarketing.fields.name')" maxlength="80" :error="form.errors.label" required autofocus />
        <Select v-model="form.type" :options="typeOptions" :label="t('smsMarketing.fields.type')" :error="form.errors.type" />
        <Input
            v-model="form.value"
            :label="t('smsMarketing.fields.senderId')"
            maxlength="16"
            :hint="t(`smsMarketing.senders.rule.${form.type}`)"
            :error="form.errors.value ?? valueError"
            required
        />
        <Select
            v-model="form.registration_status"
            :options="statusOptions"
            :label="t('smsMarketing.fields.registration')"
            :hint="t('smsMarketing.senders.registrationHint')"
            :error="form.errors.registration_status"
        />
        <Input
            v-model="form.registration_reference"
            :label="t('smsMarketing.fields.reference')"
            maxlength="120"
            optional
            :error="form.errors.registration_reference"
        />
        <Textarea v-model="form.registration_note" :label="t('smsMarketing.fields.note')" maxlength="500" optional :error="form.errors.registration_note" />
    </SmsFormDialog>

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('smsMarketing.senders.deleteTitle')"
        :description="t('smsMarketing.senders.deleteBody', { name: removing?.label ?? '' })"
        :confirm-label="t('smsMarketing.common.delete')"
        :loading="busy"
        @confirm="remove"
    />
</template>
