<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Braces, GitBranch, Pencil, Plus, Tags } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import PipelineFormDialog from '../../Components/PipelineFormDialog.vue';
import type { CrmTag, CustomFieldSetting, LeadSourceSetting, SegmentSetting, SettingsPipeline } from '../../Components/types';

type EntityType = CustomFieldSetting['entityType'];
type FieldType = CustomFieldSetting['type'];

defineOptions({ layout: TenantLayout });
defineProps<{
    pipelines: SettingsPipeline[];
    sources: LeadSourceSetting[];
    tags: CrmTag[];
    customFields: CustomFieldSetting[];
    segments: SegmentSetting[];
}>();

const { t } = useI18n();
const format = useFormat();
const pipelineOpen = ref(false);
const pipelineEditOpen = ref(false);
const sourceOpen = ref(false);
const stageOpen = ref(false);
const tagOpen = ref(false);
const customFieldOpen = ref(false);
const segmentOpen = ref(false);
const stagePipeline = ref<SettingsPipeline | null>(null);
const pipelineTarget = ref<SettingsPipeline | null>(null);
const customFieldOptions = ref('');
const segmentFilterKey = ref('search');
const segmentFilterValue = ref('');

const source = useForm({ name: '', description: '' as string | null, position: 0 });
const pipelineEdit = useForm({ name: '', active: true, default: false });
const stage = useForm({ name: '', probability: 0, is_won: false, is_lost: false });
const tag = useForm({ name: '', colour: '' });
const customField = useForm({
    entity_type: 'lead' as EntityType,
    key: '',
    name: '',
    type: 'text' as FieldType,
    required: false,
    options: [] as string[],
    position: 0,
});
const segment = useForm({
    name: '',
    entity_type: 'lead' as EntityType,
    filters: {} as Record<string, string>,
});

const customFieldEntityTypeOptions = computed(() => [
    { value: 'lead', label: t('crm.entityTypes.lead') },
    { value: 'deal', label: t('crm.entityTypes.deal') },
    { value: 'contact', label: t('crm.entityTypes.contact') },
    { value: 'company', label: t('crm.entityTypes.company') },
]);
const segmentEntityTypeOptions = computed(() => [
    ...customFieldEntityTypeOptions.value.slice(0, 2),
    { value: 'activity', label: t('crm.entityTypes.activity') },
    ...customFieldEntityTypeOptions.value.slice(2),
]);
const fieldTypeOptions = computed(() => [
    { value: 'text', label: t('crm.fieldTypes.text') },
    { value: 'number', label: t('crm.fieldTypes.number') },
    { value: 'boolean', label: t('crm.fieldTypes.boolean') },
    { value: 'date', label: t('crm.fieldTypes.date') },
    { value: 'select', label: t('crm.fieldTypes.select') },
]);
const filtersByEntity: Record<EntityType, string[]> = {
    lead: ['search', 'status', 'source_id', 'owner_id', 'min_score', 'max_score'],
    deal: ['search', 'status', 'pipeline_id', 'stage_id', 'owner_id', 'min_amount', 'max_amount'],
    activity: ['search', 'status', 'type', 'owner_id'],
    contact: ['search', 'company_id', 'city', 'country'],
    company: ['search', 'industry', 'city', 'country'],
};
const segmentFilterOptions = computed(() => filtersByEntity[segment.entity_type].map((value) => ({
    value,
    label: t(`crm.segmentFilters.${value}`),
})));

watch(() => segment.entity_type, () => {
    segmentFilterKey.value = 'search';
    segmentFilterValue.value = '';
});

function submitSource(): void {
    source.post(route('crm.settings.sources.store'), {
        preserveScroll: true,
        onSuccess: () => { sourceOpen.value = false; source.reset(); },
    });
}
function editPipeline(pipeline: SettingsPipeline): void {
    pipelineTarget.value = pipeline;
    pipelineEdit.name = pipeline.name;
    pipelineEdit.active = pipeline.active;
    pipelineEdit.default = pipeline.default;
    pipelineEdit.clearErrors();
    pipelineEditOpen.value = true;
}
function submitPipelineEdit(): void {
    if (!pipelineTarget.value) return;
    pipelineEdit.put(route('crm.settings.pipelines.update', { pipeline: pipelineTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => { pipelineEditOpen.value = false; pipelineTarget.value = null; },
    });
}
function openStage(pipeline: SettingsPipeline): void {
    stagePipeline.value = pipeline;
    stage.clearErrors();
    stageOpen.value = true;
}
function submitStage(): void {
    if (!stagePipeline.value) return;
    stage.post(route('crm.settings.stages.store', { pipeline: stagePipeline.value.id }), {
        preserveScroll: true,
        onSuccess: () => { stageOpen.value = false; stage.reset(); },
    });
}
function submitTag(): void {
    tag.post(route('crm.settings.tags.store'), {
        preserveScroll: true,
        onSuccess: () => { tagOpen.value = false; tag.reset(); },
    });
}
function submitCustomField(): void {
    customField.options = customField.type === 'select'
        ? customFieldOptions.value.split(',').map((value) => value.trim()).filter(Boolean)
        : [];
    customField.post(route('crm.settings.custom-fields.store'), {
        preserveScroll: true,
        onSuccess: () => {
            customFieldOpen.value = false;
            customField.reset();
            customFieldOptions.value = '';
        },
    });
}
function submitSegment(): void {
    segment.filters = { [segmentFilterKey.value]: segmentFilterValue.value.trim() };
    segment.post(route('crm.settings.segments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            segmentOpen.value = false;
            segment.reset();
            segmentFilterKey.value = 'search';
            segmentFilterValue.value = '';
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('crm.eyebrow')" :title="t('crm.settings.title')" :description="t('crm.settings.description')" />
    <div class="grid gap-3 xl:grid-cols-2">
        <Card :title="t('crm.settings.pipelines')" flush>
            <template #actions>
                <Button size="sm" variant="primary" @click="pipelineOpen = true">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('crm.settings.addPipeline') }}
                </Button>
            </template>
            <EmptyState v-if="pipelines.length === 0" :title="t('crm.settings.noPipelines')" compact>
                <template #icon><GitBranch /></template>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="pipeline in pipelines" :key="pipeline.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold text-ink">{{ pipeline.name }}</h3>
                        <Badge v-if="pipeline.default" tone="brand">{{ t('crm.settings.default') }}</Badge>
                        <Badge v-if="!pipeline.active" tone="neutral">{{ t('crm.settings.inactive') }}</Badge>
                        <Button icon size="sm" variant="ghost" class="ms-auto" :aria-label="t('crm.settings.editPipeline', { name: pipeline.name })" @click="editPipeline(pipeline)">
                            <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        </Button>
                        <Button size="sm" variant="ghost" @click="openStage(pipeline)">
                            <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                            {{ t('crm.settings.addStage') }}
                        </Button>
                    </div>
                    <ol class="mt-3 flex min-w-0 gap-1 overflow-x-auto pb-1">
                        <li v-for="item in pipeline.stages" :key="item.id" class="glass-well min-w-32 flex-1 rounded-control px-3 py-2">
                            <p class="truncate text-sm text-ink">{{ item.name }}</p>
                            <p class="tabular mt-1 font-mono text-xs text-ink-subtle">{{ format.number(item.probability) }}%</p>
                        </li>
                    </ol>
                </li>
            </ul>
        </Card>

        <Card :title="t('crm.settings.sources')" flush>
            <template #actions>
                <Button size="sm" variant="primary" @click="sourceOpen = true">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('crm.settings.addSource') }}
                </Button>
            </template>
            <EmptyState v-if="sources.length === 0" :title="t('crm.settings.noSources')" compact>
                <template #icon><GitBranch /></template>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="item in sources" :key="item.id" class="px-5 py-3">
                    <div class="flex items-center gap-2">
                        <p class="font-medium text-ink">{{ item.name }}</p>
                        <Badge v-if="!item.active" tone="neutral">{{ t('crm.settings.inactive') }}</Badge>
                    </div>
                    <p v-if="item.description" class="mt-1 text-[0.8125rem] text-ink-muted">{{ item.description }}</p>
                </li>
            </ul>
        </Card>

        <Card :title="t('crm.settings.tags')">
            <template #actions>
                <Button size="sm" variant="primary" @click="tagOpen = true">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('crm.settings.addTag') }}
                </Button>
            </template>
            <EmptyState v-if="tags.length === 0" :title="t('crm.settings.noTags')" compact>
                <template #icon><Tags /></template>
            </EmptyState>
            <div v-else class="flex flex-wrap gap-2">
                <Badge v-for="item in tags" :key="item.id" tone="brand">{{ item.name }}</Badge>
            </div>
        </Card>

        <Card :title="t('crm.settings.customFields')" flush>
            <template #actions>
                <Button size="sm" variant="primary" @click="customFieldOpen = true">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('crm.settings.addCustomField') }}
                </Button>
            </template>
            <EmptyState v-if="customFields.length === 0" :title="t('crm.settings.noCustomFields')" compact>
                <template #icon><Braces /></template>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="item in customFields" :key="item.id" class="flex items-center justify-between gap-3 px-5 py-3">
                    <div>
                        <p class="font-medium text-ink">{{ item.name }}</p>
                        <p class="font-mono text-xs text-ink-subtle">{{ t(`crm.entityTypes.${item.entityType}`) }} · {{ item.key }}</p>
                    </div>
                    <Badge>{{ t(`crm.fieldTypes.${item.type}`) }}</Badge>
                </li>
            </ul>
        </Card>

        <Card :title="t('crm.settings.segments')" flush class="xl:col-span-2">
            <template #actions>
                <Button size="sm" variant="primary" @click="segmentOpen = true">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('crm.settings.addSegment') }}
                </Button>
            </template>
            <EmptyState v-if="segments.length === 0" :title="t('crm.settings.noSegments')" compact>
                <template #icon><Braces /></template>
            </EmptyState>
            <ul v-else class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="item in segments" :key="item.id" class="glass-well rounded-control p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-ink">{{ item.name }}</p>
                        <Badge>{{ t(`crm.entityTypes.${item.entityType}`) }}</Badge>
                    </div>
                    <p class="mt-2 text-xs text-ink-subtle">{{ Object.keys(item.filters).map((key) => t(`crm.segmentFilters.${key}`)).join(', ') || t('crm.common.notSet') }}</p>
                </li>
            </ul>
        </Card>
    </div>

    <PipelineFormDialog v-model:open="pipelineOpen" />

    <Modal v-model:open="pipelineEditOpen" :title="t('crm.settings.editPipelineTitle')" :persistent="pipelineEdit.processing">
        <form id="crm-pipeline-edit-form" class="grid gap-4" @submit.prevent="submitPipelineEdit">
            <Input v-model="pipelineEdit.name" :label="t('crm.fields.name')" :error="pipelineEdit.errors.name" maxlength="120" required autofocus />
            <Checkbox v-model="pipelineEdit.active" :label="t('crm.status.active')" />
            <Checkbox v-model="pipelineEdit.default" :label="t('crm.settings.makeDefault')" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="pipelineEditOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-pipeline-edit-form" type="submit" variant="primary" :loading="pipelineEdit.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="sourceOpen" :title="t('crm.settings.addSource')" :persistent="source.processing">
        <form id="crm-source-form" class="grid gap-4" @submit.prevent="submitSource">
            <Input v-model="source.name" :label="t('crm.fields.name')" :error="source.errors.name" maxlength="100" required autofocus />
            <Textarea v-model="source.description" :label="t('crm.settings.sourceDescription')" :error="source.errors.description" optional />
        </form>
        <template #footer>
            <Button variant="ghost" @click="sourceOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-source-form" type="submit" variant="primary" :loading="source.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="stageOpen" :title="t('crm.settings.addStageTo', { name: stagePipeline?.name ?? '' })" :persistent="stage.processing">
        <form id="crm-stage-form" class="grid gap-4" @submit.prevent="submitStage">
            <Input v-model="stage.name" :label="t('crm.settings.stageName')" :error="stage.errors.name" maxlength="100" required autofocus />
            <Input v-model="stage.probability" type="number" min="0" max="100" :label="t('crm.fields.probability')" :error="stage.errors.probability" required />
            <div class="flex flex-wrap gap-4">
                <Checkbox v-model="stage.is_won" :label="t('crm.settings.wonStage')" />
                <Checkbox v-model="stage.is_lost" :label="t('crm.settings.lostStage')" />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="stageOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-stage-form" type="submit" variant="primary" :loading="stage.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="tagOpen" :title="t('crm.settings.addTag')" :persistent="tag.processing">
        <form id="crm-tag-form" class="grid gap-4" @submit.prevent="submitTag">
            <Input v-model="tag.name" :label="t('crm.fields.name')" :error="tag.errors.name" maxlength="80" required autofocus />
            <Input v-model="tag.colour" :label="t('crm.settings.colour')" :hint="t('crm.settings.colourHint')" :error="tag.errors.colour" maxlength="24" optional />
        </form>
        <template #footer>
            <Button variant="ghost" @click="tagOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-tag-form" type="submit" variant="primary" :loading="tag.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="customFieldOpen" :title="t('crm.settings.addCustomField')" :persistent="customField.processing">
        <form id="crm-custom-field-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitCustomField">
            <Select v-model="customField.entity_type" :options="customFieldEntityTypeOptions" :label="t('crm.settings.entityType')" :error="customField.errors.entity_type" required />
            <Select v-model="customField.type" :options="fieldTypeOptions" :label="t('crm.settings.fieldType')" :error="customField.errors.type" required />
            <Input v-model="customField.name" :label="t('crm.fields.name')" :error="customField.errors.name" maxlength="100" required />
            <Input v-model="customField.key" :label="t('crm.settings.fieldKey')" :hint="t('crm.settings.fieldKeyHint')" :error="customField.errors.key" maxlength="60" pattern="[a-z][a-z0-9_]*" required />
            <Input v-if="customField.type === 'select'" v-model="customFieldOptions" class="sm:col-span-2" :label="t('crm.settings.options')" :hint="t('crm.settings.optionsHint')" :error="customField.errors.options" required />
            <Checkbox v-model="customField.required" class="sm:col-span-2" :label="t('crm.settings.requiredField')" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="customFieldOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-custom-field-form" type="submit" variant="primary" :loading="customField.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="segmentOpen" :title="t('crm.settings.addSegment')" :persistent="segment.processing">
        <form id="crm-segment-form" class="grid gap-4" @submit.prevent="submitSegment">
            <Input v-model="segment.name" :label="t('crm.fields.name')" :error="segment.errors.name" maxlength="120" required autofocus />
            <Select v-model="segment.entity_type" :options="segmentEntityTypeOptions" :label="t('crm.settings.entityType')" :error="segment.errors.entity_type" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="segmentFilterKey" :options="segmentFilterOptions" :label="t('crm.settings.filterKey')" required />
                <Input v-model="segmentFilterValue" :label="t('crm.settings.filterValue')" :error="segment.errors.filters" required />
            </div>
            <p class="text-xs text-ink-subtle">{{ t('crm.settings.segmentHint') }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" @click="segmentOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-segment-form" type="submit" variant="primary" :loading="segment.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>
</template>
