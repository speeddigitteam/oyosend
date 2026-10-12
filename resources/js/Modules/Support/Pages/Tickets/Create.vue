<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AttachmentPicker from '../../Components/AttachmentPicker.vue';
import SupportNav from '../../Components/SupportNav.vue';
import { parseTags } from '../../Components/supportFormat';
import type { TicketFormOptions, TicketPriority } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    form: TicketFormOptions & { tags: string[]; defaultPriority: TicketPriority; canAssign: boolean; maxAttachments: number };
    prefill: { contactId: string | null; companyId: string | null };
}>();

const { t } = useI18n();

// Channels an agent may log a ticket under; the others are set by integrations.
const loggable = ['web', 'phone', 'email', 'chat'];

const ticket = useForm<{
    subject: string;
    body: string;
    priority: string;
    channel: string;
    category_id: string;
    queue_id: string;
    contact_id: string;
    company_id: string;
    requester_name: string;
    requester_email: string;
    assignee_id: string;
    tags: string;
    attachments: File[];
}>({
    subject: '',
    body: '',
    priority: props.form.defaultPriority,
    channel: 'web',
    category_id: '',
    queue_id: '',
    contact_id: props.prefill.contactId ?? '',
    company_id: props.prefill.companyId ?? '',
    requester_name: '',
    requester_email: '',
    assignee_id: '',
    tags: '',
    attachments: [],
});

const none = computed(() => ({ value: '', label: t('support.common.notSet') }));
const priorityOptions = computed(() => props.form.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) })));
const channelOptions = computed(() => props.form.channels.filter((value) => loggable.includes(value)).map((value) => ({ value, label: t(`support.channel.${value}`) })));
const queueOptions = computed(() => [{ value: '', label: t('support.tickets.autoRoute') }, ...props.form.queues.map((queue) => ({ value: queue.id, label: queue.name }))]);
const categoryOptions = computed(() => [none.value, ...props.form.categories.map((category) => ({ value: category.id, label: category.name }))]);
const contactOptions = computed(() => [none.value, ...props.form.contacts.map((contact) => ({ value: contact.id, label: contact.email ? `${contact.name} (${contact.email})` : contact.name }))]);
const companyOptions = computed(() => [none.value, ...props.form.companies.map((company) => ({ value: company.id, label: company.name }))]);
const assigneeOptions = computed(() => [{ value: '', label: t('support.tickets.unassigned') }, ...props.form.agents.map((agent) => ({ value: agent.id, label: agent.name }))]);

const hasContact = computed(() => ticket.contact_id !== '');

function submit(): void {
    ticket
        .transform((data) => ({ ...data, tags: parseTags(data.tags), attachments: data.attachments.length ? data.attachments : undefined }))
        .post(route('support.tickets.store'), { preserveScroll: true, forceFormData: true });
}

const KNOWN = ['subject', 'body', 'priority', 'channel', 'category_id', 'queue_id', 'contact_id', 'company_id', 'requester_name', 'requester_email', 'assignee_id', 'tags', 'attachments'];

/** Errors that belong to no field (a plan limit, for instance). */
const otherErrors = computed(() =>
    Object.entries(ticket.errors)
        .filter(([key]) => !KNOWN.includes(key) && !key.startsWith('tags.') && !key.startsWith('attachments.'))
        .map(([, message]) => message),
);

const attachmentError = computed(() => {
    const errors = ticket.errors as Record<string, string | undefined>;

    return errors.attachments ?? Object.entries(errors).find(([key]) => key.startsWith('attachments.'))?.[1];
});
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.create.title')" :description="t('support.create.description')" />
    <SupportNav current="tickets" />

    <form class="grid gap-3 lg:grid-cols-3" @submit.prevent="submit">
        <Card class="lg:col-span-2" :title="t('support.create.request')">
            <div class="grid gap-4">
                <Input v-model="ticket.subject" :label="t('support.fields.subject')" :error="ticket.errors.subject" required maxlength="200" />
                <Textarea v-model="ticket.body" :rows="8" :label="t('support.fields.description')" :hint="t('support.create.bodyHint')" :error="ticket.errors.body" required maxlength="20000" />
                <AttachmentPicker v-model="ticket.attachments" :max="form.maxAttachments" :error="attachmentError" />
            </div>
        </Card>

        <div class="grid content-start gap-3">
            <Card :title="t('support.create.customer')">
                <div class="grid gap-4">
                    <Select v-model="ticket.contact_id" :options="contactOptions" :label="t('support.fields.contact')" :error="ticket.errors.contact_id" />
                    <Select v-model="ticket.company_id" :options="companyOptions" :label="t('support.fields.company')" :error="ticket.errors.company_id" />
                    <template v-if="!hasContact">
                        <Input v-model="ticket.requester_name" :label="t('support.fields.requesterName')" :error="ticket.errors.requester_name" optional maxlength="160" />
                        <Input v-model="ticket.requester_email" type="email" :label="t('support.fields.requesterEmail')" :hint="t('support.create.requesterHint')" :error="ticket.errors.requester_email" optional maxlength="190" />
                    </template>
                </div>
            </Card>

            <Card :title="t('support.create.handling')">
                <div class="grid gap-4">
                    <Select v-model="ticket.priority" :options="priorityOptions" :label="t('support.fields.priority')" :error="ticket.errors.priority" />
                    <Select v-model="ticket.channel" :options="channelOptions" :label="t('support.fields.channel')" :error="ticket.errors.channel" />
                    <Select v-model="ticket.queue_id" :options="queueOptions" :label="t('support.fields.queue')" :error="ticket.errors.queue_id" />
                    <Select v-model="ticket.category_id" :options="categoryOptions" :label="t('support.fields.category')" :error="ticket.errors.category_id" />
                    <Select v-if="form.canAssign" v-model="ticket.assignee_id" :options="assigneeOptions" :label="t('support.fields.assignee')" :hint="t('support.create.assigneeHint')" :error="ticket.errors.assignee_id" />
                    <Input v-model="ticket.tags" :label="t('support.fields.tags')" :hint="t('support.create.tagsHint')" :error="ticket.errors.tags" optional />
                </div>
            </Card>

            <ul v-if="otherErrors.length" class="grid gap-1 text-sm text-danger" role="alert">
                <li v-for="message in otherErrors" :key="message">{{ message }}</li>
            </ul>

            <div class="flex flex-wrap justify-end gap-2">
                <Button variant="ghost" :href="route('support.tickets.index')" :disabled="ticket.processing">{{ t('support.common.cancel') }}</Button>
                <Button type="submit" variant="primary" :loading="ticket.processing">{{ t('support.create.submit') }}</Button>
            </div>
        </div>
    </form>
</template>
