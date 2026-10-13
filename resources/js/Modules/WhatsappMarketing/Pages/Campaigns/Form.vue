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
import NumberPicker from '../../Components/NumberPicker.vue';
import type { CampaignRow, NumberPicker as NumberPickerData, SendableTemplate } from '../../Components/types';
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
    numbers?: NumberPickerData;
    maxRecipients: number;
    canSend: boolean;
}>();

const numberPicker = computed<NumberPickerData>(() => props.numbers ?? { options: [], defaultId: null });
const senderNumberId = ref(props.campaign?.senderNumberId ?? numberPicker.value.defaultId ?? '');

// ADR 0022: an optional fixed media header and dynamic button values for the template.
const headerType = ref(props.campaign?.headerMediaType ?? '');
const headerFile = ref<File | null>(null);
type ButtonVar = { index: number; type: string; value: string };
const buttonVars = ref<ButtonVar[]>((props.campaign?.buttonParams ?? []).map((button) => ({ index: button.index, type: button.sub_type, value: button.value })));

const mediaTypeOptions = computed(() => [
    { value: '', label: t('whatsapp.campaigns.headerNone') },
    ...['image', 'video', 'document'].map((value) => ({ value, label: t(`whatsapp.rich.mediaType.${value}`) })),
]);
const buttonTypeOptions = computed(() => ['url', 'quick_reply', 'copy_code'].map((value) => ({ value, label: t(`whatsapp.rich.buttonType.${value}`) })));

function addButtonVar(): void {
    if (buttonVars.value.length < 10) {
        buttonVars.value.push({ index: buttonVars.value.length, type: 'url', value: '' });
    }
}

function removeButtonVar(index: number): void {
    buttonVars.value.splice(index, 1);
}

function onHeaderFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    headerFile.value = target.files && target.files.length > 0 ? target.files[0] : null;
}

// header_type / header_file are not initial form fields, so their errors arrive under a looser key.
const richErrors = computed(() => form.errors as Record<string, string | undefined>);

/** The rich-template extras shared by every save path. */
function richExtras(): Record<string, unknown> {
    return {
        sender_number_id: senderNumberId.value || null,
        header_type: headerType.value || null,
        header_media_id: headerType.value !== '' ? (props.campaign?.headerMediaId ?? null) : null,
        header_file: headerType.value !== '' ? headerFile.value : null,
        buttons: buttonVars.value.map((button) => ({ index: button.index, type: button.type, value: button.value })),
    };
}

const { t } = useI18n();
const format = useFormat();

const form = useForm({
    name: props.campaign?.name ?? '',
    template_id: props.campaign?.templateId ?? '',
    list_id: props.campaign?.listId ?? '',
    segment_id: '',
});

// Audience source: a hand-built list, or a CRM segment resolved to the contacts the launcher may see.
type Segment = { id: string; label: string; kind: string; approximateSize: number };
const source = ref<'list' | 'segment'>('list');
const segments = ref<Segment[]>([]);
const segmentsLoaded = ref(false);

async function loadSegments(): Promise<void> {
    if (segmentsLoaded.value) {
        return;
    }

    try {
        const response = await fetch(route('whatsapp_marketing.campaigns.segments'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            const data = (await response.json()) as { segments?: Segment[] };
            segments.value = data.segments ?? [];
        }
    } catch {
        segments.value = [];
    }

    segmentsLoaded.value = true;
}

watch(source, (value) => {
    if (value === 'segment') {
        void loadSegments();
    }
});

const sourceOptions = computed(() => [
    { value: 'list', label: t('whatsapp.campaigns.sourceList') },
    { value: 'segment', label: t('whatsapp.campaigns.sourceSegment') },
]);
const segmentOptions = computed(() =>
    segments.value.map((item) => ({ value: item.id, label: t('whatsapp.campaigns.segmentOption', { name: item.label, count: format.number(item.approximateSize) }) })),
);
const selectedSegment = computed(() => segments.value.find((item) => item.id === form.segment_id) ?? null);

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
const audienceChosen = computed(() => (source.value === 'list' ? form.list_id !== '' : form.segment_id !== ''));
const ready = computed(() => form.name.trim() !== '' && template.value !== null && audienceChosen.value && complete.value);

function submit(): void {
    // A CRM segment creates its own backing list on the server; only available when creating a campaign.
    if (!props.campaign && source.value === 'segment') {
        form
            .transform((data) => ({ name: data.name.trim(), template_id: data.template_id, segment_id: data.segment_id, variables: buildVariableMap(rows.value) }))
            .post(route('whatsapp_marketing.campaigns.from_segment'), { preserveScroll: true });

        return;
    }

    form
        .transform((data) => ({ name: data.name.trim(), template_id: data.template_id, list_id: data.list_id, variables: buildVariableMap(rows.value), ...richExtras() }))
        .submit(props.campaign ? 'put' : 'post', props.campaign ? route('whatsapp_marketing.campaigns.update', { campaign: props.campaign.id }) : route('whatsapp_marketing.campaigns.store'), {
            preserveScroll: true,
            forceFormData: headerFile.value !== null,
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
                        v-if="!campaign"
                        v-model="source"
                        :options="sourceOptions"
                        :label="t('whatsapp.campaigns.audience')"
                        :hint="t('whatsapp.campaigns.audienceHint')"
                    />
                    <Select
                        v-if="source === 'list'"
                        v-model="form.list_id"
                        :options="listOptions"
                        :label="t('whatsapp.campaigns.list')"
                        :placeholder="t('whatsapp.campaigns.chooseList')"
                        :hint="lists.length === 0 ? t('whatsapp.campaigns.noLists') : t('whatsapp.campaigns.listHint', { max: format.number(maxRecipients) })"
                        :error="form.errors.list_id"
                    />
                    <template v-else>
                        <Select
                            v-model="form.segment_id"
                            :options="segmentOptions"
                            :label="t('whatsapp.campaigns.segment')"
                            :placeholder="t('whatsapp.campaigns.chooseSegment')"
                            :hint="segmentsLoaded && segments.length === 0 ? t('whatsapp.campaigns.noSegments') : t('whatsapp.campaigns.segmentScopeNote')"
                            :error="form.errors.segment_id"
                        />
                        <p v-if="selectedSegment" class="text-sm text-ink-subtle" data-testid="segment-size">
                            {{ t('whatsapp.campaigns.segmentSize', { count: format.number(selectedSegment.approximateSize) }) }}
                        </p>
                    </template>
                    <p v-if="overCap" class="text-sm text-warn" data-testid="over-cap">{{ t('whatsapp.campaigns.overCap', { max: format.number(maxRecipients) }) }}</p>
                </div>
            </Card>

            <Card v-if="template && template.variableCount > 0" :title="t('whatsapp.campaigns.variablesTitle')" :description="t('whatsapp.campaigns.variablesDescription')">
                <p v-if="generalVariableError" role="alert" class="mb-3 text-sm text-danger">{{ generalVariableError }}</p>
                <VariableMappingEditor v-model:rows="rows" :fields="fields" :errors="variableErrors" />
            </Card>

            <Card :title="t('whatsapp.campaigns.advancedTitle')" :description="t('whatsapp.campaigns.advancedDescription')">
                <div class="grid gap-4">
                    <NumberPicker v-model="senderNumberId" :picker="numberPicker" :label="t('whatsapp.campaigns.sendFrom')" />

                    <div class="grid gap-2">
                        <Select v-model="headerType" :options="mediaTypeOptions" :label="t('whatsapp.campaigns.header')" :error="richErrors.header_type" />
                        <div v-if="headerType !== ''" class="grid gap-1">
                            <label class="text-sm font-medium text-ink" for="campaign-header-file">{{ t('whatsapp.rich.file') }}</label>
                            <input id="campaign-header-file" type="file" class="text-sm text-ink" data-testid="campaign-header-file" @change="onHeaderFile" />
                            <p v-if="campaign?.headerMediaId" class="text-xs text-ink-subtle">{{ t('whatsapp.campaigns.headerKeep') }}</p>
                            <p v-if="richErrors.header_file" class="text-xs text-danger" role="alert">{{ richErrors.header_file }}</p>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <p class="eyebrow">{{ t('whatsapp.campaigns.buttonVars') }}</p>
                        <p class="text-xs text-ink-subtle">{{ t('whatsapp.campaigns.buttonVarsHint') }}</p>
                        <div v-for="(button, index) in buttonVars" :key="index" class="grid gap-2 rounded-card border border-line bg-hover p-3 sm:grid-cols-[6rem_9rem_minmax(0,1fr)_auto] sm:items-end">
                            <Input v-model.number="button.index" type="number" min="0" max="9" :label="t('whatsapp.campaigns.buttonIndex')" />
                            <Select v-model="button.type" :options="buttonTypeOptions" :label="t('whatsapp.campaigns.buttonType')" />
                            <Input v-model="button.value" :label="t('whatsapp.campaigns.buttonValue')" maxlength="2000" />
                            <Button variant="ghost" size="sm" type="button" @click="removeButtonVar(index)">{{ t('whatsapp.common.delete') }}</Button>
                        </div>
                        <div><Button v-if="buttonVars.length < 10" variant="secondary" size="sm" type="button" @click="addButtonVar">{{ t('whatsapp.campaigns.addButtonVar') }}</Button></div>
                    </div>
                </div>
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
