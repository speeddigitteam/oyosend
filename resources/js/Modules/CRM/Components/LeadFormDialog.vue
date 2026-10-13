<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Lead, LeadStatus, Option } from './types';

const props = withDefaults(defineProps<{
    lead?: Lead | null;
    sources?: Option[];
    contacts?: Option[];
    companies?: Option[];
    owners?: Option[];
    /** Contact a new lead starts with (opened from another app's customer view). */
    initialContactId?: string | null;
}>(), { lead: null, sources: () => [], contacts: () => [], companies: () => [], owners: () => [], initialContactId: null });

const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const editing = computed(() => props.lead !== null);

const form = useForm({
    title: '',
    contact_id: '' as string,
    company_id: '' as string,
    source_id: '' as string,
    status: 'new' as LeadStatus,
    owner_id: '' as string,
});

function fill(): void {
    form.clearErrors();
    form.title = props.lead?.title ?? '';
    form.contact_id = props.lead ? (props.lead.contact?.id ?? '') : (props.initialContactId ?? '');
    form.company_id = props.lead?.company?.id ?? '';
    form.source_id = props.lead?.source?.id ?? '';
    form.status = props.lead?.status ?? 'new';
    form.owner_id = props.lead?.owner?.id ?? '';
}

watch(open, (shown) => shown && fill());

const statusOptions = computed(() => (['new', 'contacted', 'disqualified'] as LeadStatus[]).map((status) => ({ value: status, label: t(`crm.status.${status}`) })));
const options = (items: Option[]) => [{ value: '', label: t('crm.common.notSet') }, ...items.map((item) => ({ value: item.id, label: item.name }))];

function submit(): void {
    const settings = { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } };

    if (props.lead) {
        form.put(route('crm.leads.update', { lead: props.lead.id }), settings);
    } else {
        form.post(route('crm.leads.store'), settings);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="t(editing ? 'crm.leads.editTitle' : 'crm.leads.createTitle')" :description="t('crm.leads.formHint')" :persistent="form.processing">
        <form id="crm-lead-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="form.title" class="sm:col-span-2" :label="t('crm.fields.title')" :error="form.errors.title" required maxlength="200" autofocus />
            <Select v-model="form.contact_id" :options="options(contacts)" :label="t('crm.fields.contact')" :error="form.errors.contact_id" />
            <Select v-model="form.company_id" :options="options(companies)" :label="t('crm.fields.company')" :error="form.errors.company_id" />
            <Select v-model="form.source_id" :options="options(sources)" :label="t('crm.fields.source')" :error="form.errors.source_id" />
            <Select v-if="!lead || (lead.status !== 'qualified' && lead.status !== 'converted')" v-model="form.status" :options="statusOptions" :label="t('crm.fields.status')" :error="form.errors.status" />
            <Select v-if="owners.length" v-model="form.owner_id" :options="options(owners)" :label="t('crm.fields.owner')" :error="form.errors.owner_id" />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('crm.common.cancel') }}</Button>
            <Button type="submit" form="crm-lead-form" variant="primary" :loading="form.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>
</template>
