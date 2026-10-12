<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import { parseTags } from './supportFormat';
import type { CustomerOption, Option, QueueOption, TicketDetail, TicketPriority } from './types';

const props = defineProps<{
    ticket: TicketDetail;
    priorities: TicketPriority[];
    queues: QueueOption[];
    categories: Option[];
    contacts?: CustomerOption[];
    companies?: CustomerOption[];
}>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const form = useForm({
    subject: '',
    priority: 'normal' as string,
    category_id: '',
    queue_id: '',
    contact_id: '',
    company_id: '',
    requester_name: '',
    requester_email: '',
    tags: '',
});

const hasContact = computed(() => form.contact_id !== '');
const canPickCustomer = computed(() => (props.contacts?.length ?? 0) > 0 || (props.companies?.length ?? 0) > 0);

/** The directory lists at most a bounded set; the ticket's current record is always selectable. */
function withCurrent(list: CustomerOption[] | undefined, id: string | null, name: string | null): CustomerOption[] {
    const items = list ?? [];

    return id !== null && !items.some((item) => item.id === id) ? [{ id, name: name ?? id, email: null }, ...items] : items;
}

const contactOptions = computed(() => [
    { value: '', label: t('support.common.notSet') },
    ...withCurrent(props.contacts, props.ticket.requester.contactId, props.ticket.requester.name).map((item) => ({ value: item.id, label: item.name })),
]);
const companyOptions = computed(() => [
    { value: '', label: t('support.common.notSet') },
    ...withCurrent(props.companies, props.ticket.requester.companyId, props.ticket.requester.companyName ?? null).map((item) => ({ value: item.id, label: item.name })),
]);
const priorityOptions = computed(() => props.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) })));
const queueOptions = computed(() => [{ value: '', label: t('support.common.notSet') }, ...props.queues.map((queue) => ({ value: queue.id, label: queue.name }))]);
const categoryOptions = computed(() => [{ value: '', label: t('support.common.notSet') }, ...props.categories.map((category) => ({ value: category.id, label: category.name }))]);

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.defaults({
        subject: props.ticket.subject,
        priority: props.ticket.priority,
        category_id: props.ticket.category?.id ?? '',
        queue_id: props.ticket.queue?.id ?? '',
        contact_id: props.ticket.requester.contactId ?? '',
        company_id: props.ticket.requester.companyId ?? '',
        requester_name: props.ticket.requesterName ?? '',
        requester_email: props.ticket.requesterEmail ?? '',
        tags: props.ticket.tags.join(', '),
    }).reset();
    form.clearErrors();
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        category_id: data.category_id === '' ? null : data.category_id,
        queue_id: data.queue_id === '' ? null : data.queue_id,
        tags: parseTags(data.tags),
        // Sent only when changed, so a record outside the picker's list is never cleared by accident.
        contact_id: data.contact_id === (props.ticket.requester.contactId ?? '') ? undefined : (data.contact_id === '' ? null : data.contact_id),
        company_id: data.company_id === (props.ticket.requester.companyId ?? '') ? undefined : (data.company_id === '' ? null : data.company_id),
        ...(hasContact.value ? { requester_name: undefined, requester_email: undefined } : {}),
    })).put(route('support.tickets.update', { ticket: props.ticket.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('support.edit.title', { number: ticket.number })" :persistent="form.processing">
        <form id="support-edit-form" class="grid gap-4" @submit.prevent="submit">
            <Input v-model="form.subject" :label="t('support.fields.subject')" :error="form.errors.subject" required maxlength="200" />
            <Select v-model="form.priority" :options="priorityOptions" :label="t('support.fields.priority')" :error="form.errors.priority" />
            <Select v-model="form.queue_id" :options="queueOptions" :label="t('support.fields.queue')" :error="form.errors.queue_id" />
            <Select v-model="form.category_id" :options="categoryOptions" :label="t('support.fields.category')" :error="form.errors.category_id" />
            <template v-if="canPickCustomer">
                <Select v-model="form.contact_id" :options="contactOptions" :label="t('support.fields.contact')" :error="form.errors.contact_id" />
                <Select v-model="form.company_id" :options="companyOptions" :label="t('support.fields.company')" :error="form.errors.company_id" />
            </template>
            <template v-if="!hasContact">
                <Input v-model="form.requester_name" :label="t('support.fields.requesterName')" :error="form.errors.requester_name" optional maxlength="160" />
                <Input v-model="form.requester_email" type="email" :label="t('support.fields.requesterEmail')" :error="form.errors.requester_email" optional maxlength="190" />
            </template>
            <Input v-model="form.tags" :label="t('support.fields.tags')" :hint="t('support.create.tagsHint')" :error="form.errors.tags" optional />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-edit-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>
</template>
