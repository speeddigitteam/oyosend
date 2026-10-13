<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { ContactMatch, SendableTemplate } from '../../Components/types';
import VariableMappingEditor from '../../Components/VariableMappingEditor.vue';
import { buildVariableMap, type VariableRow, variableRows, variablesComplete } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

interface Capture {
    id: string;
    conversationId: string;
    phone: string | null;
    leadId: string | null;
    contactId: string | null;
    capturedAt: string;
}

const props = defineProps<{
    leadCapture: { enabled: boolean; keywords: string[] };
    leadTag: string;
    templates: SendableTemplate[];
    fields: string[];
    reminderTypes: string[];
    recentCaptures: Capture[];
    can: { manageSettings: boolean; send: boolean };
}>();

const { t } = useI18n();
const format = useFormat();

// Lead capture gate.
const leadForm = useForm({
    enabled: props.leadCapture.enabled,
    keywords: props.leadCapture.keywords.join(', '),
});

function saveLeadCapture(): void {
    leadForm
        .transform((data) => ({
            enabled: data.enabled,
            keywords: data.keywords
                .split(',')
                .map((keyword) => keyword.trim())
                .filter((keyword) => keyword !== ''),
        }))
        .put(route('whatsapp_marketing.crm.lead_capture'), { preserveScroll: true });
}

// Manual transactional reminder / notification sender.
const reminderForm = useForm({ reminder_type: props.reminderTypes[0] ?? 'generic', template_id: '', contact_id: '' });
const template = computed(() => props.templates.find((item) => item.id === reminderForm.template_id) ?? null);
const rows = ref<VariableRow[]>(variableRows(0, null));

watch(
    () => reminderForm.template_id,
    () => {
        rows.value = variableRows(template.value?.variableCount ?? 0, null);
    },
);

const reminderTypeOptions = computed(() => props.reminderTypes.map((key) => ({ value: key, label: t(`whatsapp.crm.reminderType.${key}`) })));
const templateOptions = computed(() => props.templates.map((item) => ({ value: item.id, label: `${item.name} (${item.language})` })));
const variableErrors = computed(() => {
    const errors = reminderForm.errors as Record<string, string | undefined>;

    return Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith('variables')));
});

// Contact search, within the member's contact scope.
const query = ref('');
const results = ref<ContactMatch[]>([]);
const selectedContact = ref<ContactMatch | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

watch(query, (value) => {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    if (value.trim() === '') {
        results.value = [];

        return;
    }

    searchTimer = setTimeout(() => void runSearch(value.trim()), 300);
});

async function runSearch(term: string): Promise<void> {
    try {
        const response = await fetch(`${route('whatsapp_marketing.crm.contacts')}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            const data = (await response.json()) as { contacts?: ContactMatch[] };
            results.value = data.contacts ?? [];
        }
    } catch {
        results.value = [];
    }
}

function choose(contact: ContactMatch): void {
    selectedContact.value = contact;
    reminderForm.contact_id = contact.id;
    results.value = [];
    query.value = contact.name;
}

const reminderReady = computed(
    () => reminderForm.template_id !== '' && reminderForm.contact_id !== '' && variablesComplete(rows.value),
);

function sendReminder(): void {
    reminderForm
        .transform((data) => ({
            reminder_type: data.reminder_type,
            template_id: data.template_id,
            contact_id: data.contact_id,
            variables: buildVariableMap(rows.value),
        }))
        .post(route('whatsapp_marketing.crm.reminders'), {
            preserveScroll: true,
            onSuccess: () => {
                reminderForm.reset('template_id');
                rows.value = variableRows(0, null);
            },
        });
}
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.crm.title')" :description="t('whatsapp.crm.description')" />
    <WhatsappNav current="crm" />

    <div class="grid gap-3 lg:grid-cols-2">
        <Card :title="t('whatsapp.crm.leadCaptureTitle')" :description="t('whatsapp.crm.leadCaptureDescription')">
            <form v-if="can.manageSettings" class="grid gap-4" novalidate @submit.prevent="saveLeadCapture">
                <Checkbox v-model="leadForm.enabled" :label="t('whatsapp.crm.leadCaptureEnable')" :description="t('whatsapp.crm.leadCaptureEnableHint', { tag: leadTag })" />
                <Input
                    v-model="leadForm.keywords"
                    :label="t('whatsapp.crm.keywords')"
                    :hint="t('whatsapp.crm.keywordsHint')"
                    :error="leadForm.errors.keywords"
                    :disabled="!leadForm.enabled"
                />
                <div>
                    <Button type="submit" variant="primary" :loading="leadForm.processing">{{ t('whatsapp.crm.saveSettings') }}</Button>
                </div>
            </form>
            <p v-else class="text-sm text-ink-subtle">{{ t('whatsapp.crm.leadCaptureReadOnly') }}</p>
        </Card>

        <Card :title="t('whatsapp.crm.recentTitle')" :description="t('whatsapp.crm.recentDescription')">
            <p v-if="recentCaptures.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.crm.recentEmpty') }}</p>
            <ul v-else class="grid gap-2">
                <li v-for="capture in recentCaptures" :key="capture.id" class="glass-well flex items-center justify-between gap-3 rounded-control px-3 py-2 text-sm">
                    <span class="text-ink">{{ capture.phone ?? t('whatsapp.crm.unknownNumber') }}</span>
                    <span class="text-ink-subtle">{{ format.date(capture.capturedAt, 'datetime') }}</span>
                </li>
            </ul>
        </Card>

        <Card v-if="can.send" class="lg:col-span-2" :title="t('whatsapp.crm.reminderTitle')" :description="t('whatsapp.crm.reminderDescription')">
            <form class="grid gap-4 lg:grid-cols-2" novalidate @submit.prevent="sendReminder">
                <div class="grid content-start gap-4">
                    <Select v-model="reminderForm.reminder_type" :options="reminderTypeOptions" :label="t('whatsapp.crm.reminderType.label')" />
                    <Select
                        v-model="reminderForm.template_id"
                        :options="templateOptions"
                        :label="t('whatsapp.crm.reminderTemplate')"
                        :placeholder="t('whatsapp.campaigns.chooseTemplate')"
                        :hint="templates.length === 0 ? t('whatsapp.campaigns.noTemplates') : t('whatsapp.crm.reminderTemplateHint')"
                        :error="reminderForm.errors.template_id"
                    />
                    <div class="grid gap-1">
                        <Input v-model="query" :label="t('whatsapp.crm.contact')" :hint="t('whatsapp.crm.contactHint')" :error="reminderForm.errors.contact_id" autocomplete="off" />
                        <ul v-if="results.length > 0" class="glass-well grid gap-1 rounded-control p-1">
                            <li v-for="contact in results" :key="contact.id">
                                <button type="button" class="w-full rounded-control px-2 py-1 text-left text-sm text-ink hover:bg-hover" @click="choose(contact)">
                                    {{ contact.name }} <span class="text-ink-subtle">{{ contact.phone }}</span>
                                </button>
                            </li>
                        </ul>
                        <p v-if="selectedContact" class="text-xs text-ink-subtle">{{ t('whatsapp.crm.contactChosen', { name: selectedContact.name }) }}</p>
                    </div>
                </div>

                <div class="grid content-start gap-4">
                    <div v-if="template && template.variableCount > 0" class="grid gap-2">
                        <p class="text-sm font-medium text-ink">{{ t('whatsapp.campaigns.variablesTitle') }}</p>
                        <VariableMappingEditor v-model:rows="rows" :fields="fields" :errors="variableErrors" />
                    </div>
                    <p class="text-xs text-ink-subtle">{{ t('whatsapp.crm.reminderConsentNote') }}</p>
                    <div>
                        <Button type="submit" variant="primary" :loading="reminderForm.processing" :disabled="!reminderReady">{{ t('whatsapp.crm.sendReminder') }}</Button>
                    </div>
                </div>
            </form>
        </Card>
    </div>
</template>
