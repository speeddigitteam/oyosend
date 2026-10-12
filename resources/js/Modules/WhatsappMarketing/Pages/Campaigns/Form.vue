<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { sendJson } from '@/Modules/Automation/lib/http';
import type { CampaignRow, SendableTemplate } from '../../Components/types';
import VariableMappingEditor from '../../Components/VariableMappingEditor.vue';
import {
    buildVariableMap,
    renderTemplateBody,
    type VariableRow,
    variableRows,
    variablesComplete,
} from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaign: CampaignRow | null;
    templates: SendableTemplate[];
    lists: { id: string; name: string; memberCount: number }[];
    fields: string[];
    maxRecipients: number;
    canSend: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({
    name: props.campaign?.name ?? '',
    template_id: props.campaign?.templateId ?? '',
    list_id: props.campaign?.listId ?? '',
});

const template = computed(() => props.templates.find((item) => item.id === form.template_id) ?? null);
const rows = ref<VariableRow[]>(variableRows(template.value?.variableCount ?? 0, props.campaign?.variables ?? null));

watch(
    () => form.template_id,
    () => {
        rows.value = variableRows(template.value?.variableCount ?? 0, null);
    },
);

const templateOptions = computed(() => props.templates.map((item) => ({ value: item.id, label: `${item.name} (${item.language})` })));
const listOptions = computed(() =>
    props.lists.map((item) => ({ value: item.id, label: t('whatsapp.campaigns.listOption', { name: item.name, count: format.number(item.memberCount) }) })),
);
const complete = computed(() => variablesComplete(rows.value));
const variableErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith('variables')));
});
const generalVariableError = computed(() => variableErrors.value.variables);

// Live preview: what the template says for a sample contact. The server renders it; nothing is sent.
const preview = ref<string | null>(null);
const previewFailed = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let sequence = 0;

const localPreview = computed(() => {
    if (template.value === null) {
        return '';
    }

    return renderTemplateBody(
        template.value.body,
        rows.value.map((row) => (row.kind === 'text' ? row.value : row.field !== '' ? t(`whatsapp.fields.${row.field}`) : '')),
    );
});

async function loadPreview(): Promise<void> {
    if (template.value === null || !complete.value) {
        preview.value = null;
        previewFailed.value = false;

        return;
    }

    const current = ++sequence;
    const response = await sendJson<{ body: string }>('POST', route('whatsapp_marketing.campaigns.preview'), {
        template_id: template.value.id,
        variables: buildVariableMap(rows.value),
    });

    if (current !== sequence) {
        return;
    }

    previewFailed.value = !response.ok;
    preview.value = response.ok && response.data ? response.data.body : null;
}

watch(
    [() => form.template_id, rows],
    () => {
        if (timer !== null) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => void loadPreview(), 400);
    },
    { deep: true, immediate: true },
);
onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});

const selectedList = computed(() => props.lists.find((item) => item.id === form.list_id) ?? null);
const overCap = computed(() => selectedList.value !== null && selectedList.value.memberCount > props.maxRecipients);
const ready = computed(() => form.name.trim() !== '' && template.value !== null && form.list_id !== '' && complete.value);

function submit(): void {
    form
        .transform((data) => ({ name: data.name.trim(), template_id: data.template_id, list_id: data.list_id, variables: buildVariableMap(rows.value) }))
        .submit(props.campaign ? 'put' : 'post', props.campaign ? route('whatsapp_marketing.campaigns.update', { campaign: props.campaign.id }) : route('whatsapp_marketing.campaigns.store'), {
            preserveScroll: true,
        });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('whatsapp.eyebrow')"
        :title="campaign ? t('whatsapp.campaigns.editTitle') : t('whatsapp.campaigns.createTitle')"
        :description="t('whatsapp.campaigns.formDescription')"
    >
        <template #actions>
            <Button variant="ghost" :href="campaign ? route('whatsapp_marketing.campaigns.show', { campaign: campaign.id }) : route('whatsapp_marketing.campaigns.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.common.back') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="campaigns" />

    <form class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_22rem]" novalidate @submit.prevent="submit">
        <div class="grid min-w-0 content-start gap-3">
            <Card :title="t('whatsapp.campaigns.basics')">
                <div class="grid gap-4">
                    <Input v-model="form.name" :label="t('whatsapp.campaigns.name')" :error="form.errors.name" maxlength="120" />
                    <Select
                        v-model="form.template_id"
                        :options="templateOptions"
                        :label="t('whatsapp.campaigns.template')"
                        :placeholder="t('whatsapp.campaigns.chooseTemplate')"
                        :hint="templates.length === 0 ? t('whatsapp.campaigns.noTemplates') : t('whatsapp.campaigns.templateHint')"
                        :error="form.errors.template_id"
                    />
                    <Select
                        v-model="form.list_id"
                        :options="listOptions"
                        :label="t('whatsapp.campaigns.list')"
                        :placeholder="t('whatsapp.campaigns.chooseList')"
                        :hint="lists.length === 0 ? t('whatsapp.campaigns.noLists') : t('whatsapp.campaigns.listHint', { max: format.number(maxRecipients) })"
                        :error="form.errors.list_id"
                    />
                    <p v-if="overCap" class="text-sm text-warn" data-testid="over-cap">{{ t('whatsapp.campaigns.overCap', { max: format.number(maxRecipients) }) }}</p>
                </div>
            </Card>

            <Card v-if="template && template.variableCount > 0" :title="t('whatsapp.campaigns.variablesTitle')" :description="t('whatsapp.campaigns.variablesDescription')">
                <p v-if="generalVariableError" role="alert" class="mb-3 text-sm text-danger">{{ generalVariableError }}</p>
                <VariableMappingEditor v-model:rows="rows" :fields="fields" :errors="variableErrors" />
            </Card>
        </div>

        <div class="grid content-start gap-3">
            <Card :title="t('whatsapp.campaigns.previewTitle')" :description="t('whatsapp.campaigns.previewDescription')">
                <p v-if="template === null" class="text-sm text-ink-subtle">{{ t('whatsapp.campaigns.previewEmpty') }}</p>
                <template v-else>
                    <p class="glass-well whitespace-pre-wrap rounded-control px-3 py-2 text-sm text-ink wrap-anywhere" data-testid="campaign-preview">
                        {{ preview ?? localPreview }}
                    </p>
                    <p v-if="!complete" class="mt-2 text-xs text-ink-subtle">{{ t('whatsapp.campaigns.previewIncomplete') }}</p>
                    <p v-else-if="previewFailed" class="mt-2 text-xs text-warn">{{ t('whatsapp.campaigns.previewFailed') }}</p>
                </template>
            </Card>

            <Card :title="t('whatsapp.campaigns.saveTitle')">
                <p class="mb-3 text-sm text-ink-muted">{{ canSend ? t('whatsapp.campaigns.saveBodySend') : t('whatsapp.campaigns.saveBodyNoSend') }}</p>
                <Button type="submit" variant="primary" block :loading="form.processing" :disabled="!ready">{{ t('whatsapp.campaigns.saveDraft') }}</Button>
            </Card>
        </div>
    </form>
</template>
