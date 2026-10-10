<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { Activity, ActivityPriority, ActivityType, Option } from './types';

const props = withDefaults(defineProps<{ activity?: Activity | null; leads?: Option[]; deals?: Option[]; contacts?: Option[]; companies?: Option[]; owners?: Option[] }>(), {
    activity: null, leads: () => [], deals: () => [], contacts: () => [], companies: () => [], owners: () => [],
});
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const form = useForm({ type: 'task' as ActivityType, priority: 'normal' as ActivityPriority, subject: '', description: '' as string | null, starts_at: '', due_at: '', remind_at: '', lead_id: '', deal_id: '', contact_id: '', company_id: '', owner_id: '' });
const types = computed(() => (['task', 'meeting', 'call', 'follow_up'] as ActivityType[]).map((value) => ({ value, label: t(`crm.activityTypes.${value}`) })));
const priorities = computed(() => (['low', 'normal', 'high', 'urgent'] as ActivityPriority[]).map((value) => ({ value, label: t(`crm.status.${value}`) })));
const options = (items: Option[]) => [{ value: '', label: t('crm.common.notSet') }, ...items.map((item) => ({ value: item.id, label: item.name }))];
const localDate = (value: string | null) => value ? value.slice(0, 16) : '';
watch(open, (shown) => { if (!shown) return; const activity = props.activity; form.clearErrors(); form.type = activity?.type ?? 'task'; form.priority = activity?.priority ?? 'normal'; form.subject = activity?.subject ?? ''; form.description = activity?.description ?? ''; form.starts_at = localDate(activity?.startsAt ?? null); form.due_at = localDate(activity?.dueAt ?? null); form.remind_at = localDate(activity?.remindAt ?? null); form.lead_id = activity?.lead?.id ?? ''; form.deal_id = activity?.deal?.id ?? ''; form.contact_id = activity?.contact?.id ?? ''; form.company_id = activity?.company?.id ?? ''; form.owner_id = activity?.owner?.id ?? ''; });
function submit(): void { const settings = { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } }; if (props.activity) form.put(route('crm.activities.update', { activity: props.activity.id }), settings); else form.post(route('crm.activities.store'), settings); }
</script>

<template>
    <Modal v-model:open="open" size="lg" :title="t(activity ? 'crm.activities.editTitle' : 'crm.activities.createTitle')" :persistent="form.processing">
        <form id="crm-activity-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="form.subject" class="sm:col-span-2" :label="t('crm.fields.title')" :error="form.errors.subject" maxlength="200" required autofocus />
            <Select v-model="form.type" :options="types" :label="t('crm.fields.type')" :error="form.errors.type" required />
            <Select v-model="form.priority" :options="priorities" :label="t('crm.fields.priority')" :error="form.errors.priority" required />
            <Input v-model="form.starts_at" type="datetime-local" :label="t('crm.fields.startsAt')" :error="form.errors.starts_at" optional />
            <Input v-model="form.due_at" type="datetime-local" :label="t('crm.fields.dueAt')" :error="form.errors.due_at" optional />
            <Input v-model="form.remind_at" type="datetime-local" :label="t('crm.fields.remindAt')" :error="form.errors.remind_at" optional />
            <Select v-model="form.lead_id" :options="options(leads)" :label="t('crm.nav.leads')" :error="form.errors.lead_id" />
            <Select v-model="form.deal_id" :options="options(deals)" :label="t('crm.nav.deals')" :error="form.errors.deal_id" />
            <Select v-model="form.contact_id" :options="options(contacts)" :label="t('crm.fields.contact')" :error="form.errors.contact_id" />
            <Select v-model="form.company_id" :options="options(companies)" :label="t('crm.fields.company')" :error="form.errors.company_id" />
            <Select v-if="owners.length" v-model="form.owner_id" :options="options(owners)" :label="t('crm.fields.owner')" :error="form.errors.owner_id" />
            <Textarea v-model="form.description" class="sm:col-span-2" :label="t('crm.fields.description')" :error="form.errors.description" optional />
        </form>
        <template #footer><Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-activity-form" type="submit" variant="primary" :loading="form.processing">{{ t('crm.common.save') }}</Button></template>
    </Modal>
</template>
