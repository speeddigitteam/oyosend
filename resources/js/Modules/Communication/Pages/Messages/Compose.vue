<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Send } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import { addressFor, newRequestId } from '../../Components/communicationFormat';
import type { ChannelStatus, ContactOption, TemplateOption } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    contacts: ContactOption[];
    channels: ChannelStatus[];
    purposes: string[];
    canChoosePurpose: boolean;
    templates: TemplateOption[];
    contactId: string | null;
}>();

const { t } = useI18n();
const { label } = useCommunicationLabels();

const firstReady = props.channels.find((channel) => channel.state === 'configured')?.channel ?? props.channels[0]?.channel ?? 'email';

const form = useForm({
    contact_id: props.contactId ?? '',
    channel: firstReady as string,
    // Without the sensitive permission free text is always marketing; the server decides, this only mirrors it.
    purpose: (props.canChoosePurpose && props.purposes.includes('transactional') ? 'transactional' : 'marketing') as string,
    template_id: '',
    subject: '',
    body: '',
    request_id: newRequestId(),
});

const anyReady = computed(() => props.channels.some((channel) => channel.state === 'configured'));
const channelReady = computed(() => props.channels.find((channel) => channel.channel === form.channel)?.state === 'configured');
const contact = computed(() => props.contacts.find((option) => option.id === form.contact_id));
const missingAddress = computed(() => form.contact_id !== '' && addressFor(form.channel, contact.value) === null);
const usesTemplate = computed(() => form.template_id !== '');
const showsSubject = computed(() => form.channel === 'email' && !usesTemplate.value);

const contactOptions = computed(() =>
    props.contacts.map((option) => ({ value: option.id, label: option.email ? `${option.name} (${option.email})` : option.name })),
);
const channelOptions = computed(() =>
    props.channels.map(({ channel, state }) => ({
        value: channel,
        label: state === 'configured' ? label('channel', channel) : t('communication.compose.channelNotConfigured', { channel: label('channel', channel) }),
        disabled: state !== 'configured',
    })),
);
const purposeOptions = computed(() => props.purposes.map((value) => ({ value, label: label('purpose', value) })));
const templateChoices = computed(() =>
    props.templates.filter((template) => template.channel === form.channel && (!props.canChoosePurpose || template.purpose === form.purpose)),
);
const templateOptions = computed(() => [
    { value: '', label: t('communication.compose.templateNone') },
    ...templateChoices.value.map((template) => ({ value: template.id, label: `${template.name} (${label('locale', template.locale)})` })),
]);

watch([() => form.channel, () => form.purpose], () => {
    if (usesTemplate.value && !templateChoices.value.some((template) => template.id === form.template_id)) {
        form.template_id = '';
    }
});

function send(): void {
    form
        .transform((data) => ({
            ...data,
            purpose: props.canChoosePurpose ? data.purpose : null,
            template_id: data.template_id === '' ? null : data.template_id,
            subject: data.template_id !== '' || data.channel !== 'email' || data.subject.trim() === '' ? null : data.subject,
            body: data.template_id !== '' || data.body.trim() === '' ? null : data.body,
        }))
        .post(route('communication.messages.store'));
}

const contactHint = computed(() => {
    if (!missingAddress.value) {
        return undefined;
    }

    return form.channel === 'email' ? t('communication.compose.contactNoEmail') : t('communication.compose.contactNoPhone');
});
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.compose.title')" :description="t('communication.compose.description')">
        <template #actions>
            <Button variant="secondary" :href="route('communication.messages.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('communication.compose.back') }}
            </Button>
        </template>
    </PageHeader>
    <CommunicationNav current="messages" />

    <p v-if="!anyReady" class="mb-3 max-w-3xl rounded-card bg-warn-soft px-4 py-3 text-sm text-warn" role="status">
        {{ t('communication.compose.noChannelReady') }}
    </p>
    <p v-if="contacts.length === 0" class="mb-3 max-w-3xl rounded-card bg-hover px-4 py-3 text-sm text-ink-muted" role="status">
        {{ t('communication.compose.noContacts') }}
    </p>

    <form class="grid max-w-3xl gap-3" @submit.prevent="send">
        <Card>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <Select
                        v-model="form.contact_id"
                        :options="contactOptions"
                        :label="t('communication.compose.contact')"
                        :placeholder="t('communication.compose.contactPlaceholder')"
                        :hint="contactHint"
                        :error="form.errors.contact_id"
                        required
                    />
                </div>
                <Select v-model="form.channel" :options="channelOptions" :label="t('communication.compose.channel')" :error="form.errors.channel" />
                <Select
                    v-if="canChoosePurpose"
                    v-model="form.purpose"
                    :options="purposeOptions"
                    :label="t('communication.compose.purpose')"
                    :hint="t('communication.compose.purposeHint')"
                    :error="form.errors.purpose"
                />
                <p v-if="!canChoosePurpose" class="text-[0.8125rem] text-ink-muted sm:col-span-2" data-testid="purpose-fixed">
                    {{ t('communication.compose.purposeFixed') }}
                </p>
                <div v-if="templateChoices.length > 0" class="sm:col-span-2">
                    <Select
                        v-model="form.template_id"
                        :options="templateOptions"
                        :label="t('communication.compose.template')"
                        :hint="usesTemplate ? t('communication.compose.templateHint') : undefined"
                        :error="form.errors.template_id"
                        optional
                    />
                </div>
                <div v-if="showsSubject" class="sm:col-span-2">
                    <Input v-model="form.subject" :label="t('communication.compose.subject')" :error="form.errors.subject" maxlength="200" required />
                </div>
                <div v-if="!usesTemplate" class="sm:col-span-2">
                    <Textarea
                        v-model="form.body"
                        :rows="8"
                        :label="t('communication.compose.body')"
                        :hint="t('communication.compose.bodyHint')"
                        :error="form.errors.body"
                        maxlength="5000"
                        required
                    />
                </div>
            </div>
        </Card>

        <div class="flex justify-end">
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="!channelReady || form.contact_id === ''">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('communication.compose.send') }}
            </Button>
        </div>
    </form>
</template>
