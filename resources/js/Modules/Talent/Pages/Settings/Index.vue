<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';
import { TabsContent, TabsRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import NamedItemDialog from '../../Components/NamedItemDialog.vue';
import StageDialog from '../../Components/StageDialog.vue';
import TalentTabList from '../../Components/TalentTabList.vue';
import TemplateDialog from '../../Components/TemplateDialog.vue';
import { parseInteger } from '../../Components/talentFormat';
import type {
    AssigneeRole,
    ChecklistKind,
    PersonOption,
    PipelineDetail,
    RejectionReason,
    Stage,
    StageType,
    TalentSettings,
    Template,
} from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: TalentSettings;
    pipelines: PipelineDetail[];
    rejectionReasons: RejectionReason[];
    templates: Template[];
    employees: PersonOption[];
    options: { stageTypes: StageType[]; checklistKinds: ChecklistKind[]; assigneeRoles: AssigneeRole[] };
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const tab = ref('general');
const tabs = computed(() => ['general', 'pipelines', 'reasons', 'templates', 'forms'].map((value) => ({ value, label: t(`talent.settings.tabs.${value}`) })));

const form = useForm({
    default_probation_days: String(props.settings.defaultProbationDays),
    probation_reminder_days: String(props.settings.probationReminderDays),
    certification_reminder_days: String(props.settings.certificationReminderDays),
    candidate_retention_months: String(props.settings.candidateRetentionMonths),
});

const NUMBERS = [
    { key: 'default_probation_days', min: 1, max: 730 },
    { key: 'probation_reminder_days', min: 1, max: 180 },
    { key: 'certification_reminder_days', min: 1, max: 365 },
    { key: 'candidate_retention_months', min: 1, max: 120 },
] as const;

const invalid = computed(() => NUMBERS.some(({ key, min, max }) => {
    const value = parseInteger(form[key]);

    return value === null || value < min || value > max;
}));

function save(): void {
    form.transform((data) => Object.fromEntries(NUMBERS.map(({ key }) => [key, parseInteger(data[key])])))
        .put(route('talent.settings.update'), { preserveScroll: true });
}

// Pipelines and stages.
const pipelineOpen = ref(false);
const editingPipeline = ref<PipelineDetail | null>(null);
const stageOpen = ref(false);
const stagePipeline = ref<PipelineDetail | null>(null);
const editingStage = ref<Stage | null>(null);

function openPipeline(pipeline: PipelineDetail | null): void {
    editingPipeline.value = pipeline;
    pipelineOpen.value = true;
}

function openStage(pipeline: PipelineDetail, stage: Stage | null): void {
    stagePipeline.value = pipeline;
    editingStage.value = stage;
    stageOpen.value = true;
}

const nextPosition = computed(() => {
    const positions = (stagePipeline.value?.stages ?? []).map((stage) => stage.position ?? 0);

    return positions.length === 0 ? 0 : Math.max(...positions) + 10;
});

// Rejection reasons.
const reasonOpen = ref(false);
const editingReason = ref<RejectionReason | null>(null);

function openReason(reason: RejectionReason | null): void {
    editingReason.value = reason;
    reasonOpen.value = true;
}

// Templates.
const templateOpen = ref(false);
const editingTemplate = ref<Template | null>(null);

function openTemplate(template: Template | null): void {
    editingTemplate.value = template;
    templateOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.settings.title')" :description="t('talent.settings.description')" />

    <TabsRoot v-model="tab" class="min-w-0">
        <TalentTabList :label="t('talent.settings.tabsLabel')" :items="tabs" />

        <TabsContent value="general">
            <!-- One full-width box: the fields lay out by the box's own width (container queries), so they never
                 stretch across the whole page; the save sits in the box's footer. -->
            <Card :title="t('talent.settings.numbers')" :description="t('talent.settings.numbersHint')">
                <form id="talent-settings-general" class="@container" novalidate @submit.prevent="save">
                    <div class="grid gap-4 @2xl:grid-cols-2 @6xl:grid-cols-4">
                        <Input
                            v-model="form.default_probation_days"
                            inputmode="numeric"
                            :label="t('talent.settings.defaultProbationDays')"
                            :hint="t('talent.settings.defaultProbationHint')"
                            :error="form.errors.default_probation_days"
                        />
                        <Input
                            v-model="form.probation_reminder_days"
                            inputmode="numeric"
                            :label="t('talent.settings.probationReminderDays')"
                            :hint="t('talent.settings.probationReminderHint')"
                            :error="form.errors.probation_reminder_days"
                        />
                        <Input
                            v-model="form.certification_reminder_days"
                            inputmode="numeric"
                            :label="t('talent.settings.certificationReminderDays')"
                            :hint="t('talent.settings.certificationReminderHint')"
                            :error="form.errors.certification_reminder_days"
                        />
                        <Input
                            v-model="form.candidate_retention_months"
                            inputmode="numeric"
                            :label="t('talent.settings.candidateRetentionMonths')"
                            :hint="t('talent.settings.candidateRetentionHint')"
                            :error="form.errors.candidate_retention_months"
                        />
                    </div>
                </form>
                <template #footer>
                    <div class="flex justify-end">
                        <Button type="submit" form="talent-settings-general" variant="primary" :loading="form.processing" :disabled="invalid">
                            {{ t('talent.common.save') }}
                        </Button>
                    </div>
                </template>
            </Card>
        </TabsContent>

        <TabsContent value="pipelines">
            <div class="grid gap-3">
                <div class="flex justify-end">
                    <Button variant="primary" @click="openPipeline(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.settings.addPipeline') }}
                    </Button>
                </div>
                <Card v-for="pipeline in pipelines" :key="pipeline.id" :title="pipeline.name" data-testid="pipeline">
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <Badge v-if="pipeline.isDefault" tone="brand">{{ t('talent.settings.defaultBadge') }}</Badge>
                            <Badge v-if="!pipeline.isActive" tone="neutral">{{ t('talent.common.inactive') }}</Badge>
                            <Button size="sm" variant="ghost" @click="openPipeline(pipeline)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                                {{ t('talent.common.edit') }}
                            </Button>
                        </div>
                    </template>
                    <ol class="grid gap-1">
                        <li v-for="stage in pipeline.stages" :key="stage.id" class="flex flex-wrap items-center justify-between gap-2 rounded-control px-2 py-1.5 hover:bg-hover">
                            <span class="text-sm text-ink">{{ stage.name }}</span>
                            <span class="flex items-center gap-2">
                                <span class="text-xs text-ink-subtle">{{ t(`talent.status.stageType.${stage.type}`) }}</span>
                                <Button size="sm" variant="ghost" @click="openStage(pipeline, stage)">{{ t('talent.common.edit') }}</Button>
                            </span>
                        </li>
                    </ol>
                    <div class="mt-3">
                        <Button size="sm" variant="secondary" @click="openStage(pipeline, null)">
                            <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                            {{ t('talent.settings.addStage') }}
                        </Button>
                    </div>
                </Card>
            </div>
        </TabsContent>

        <TabsContent value="reasons">
            <Card :title="t('talent.settings.reasons')" :description="t('talent.settings.reasonsHint')">
                <template #actions>
                    <Button size="sm" variant="secondary" @click="openReason(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.settings.addReason') }}
                    </Button>
                </template>
                <EmptyState v-if="rejectionReasons.length === 0" :title="t('talent.settings.noReasons')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="reason in rejectionReasons" :key="reason.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <span class="text-sm text-ink">{{ reason.name }}</span>
                        <span class="flex items-center gap-2">
                            <Badge v-if="reason.isActive === false" tone="neutral">{{ t('talent.common.inactive') }}</Badge>
                            <Button size="sm" variant="ghost" @click="openReason(reason)">{{ t('talent.common.edit') }}</Button>
                        </span>
                    </li>
                </ul>
            </Card>
        </TabsContent>

        <TabsContent value="templates">
            <div class="grid gap-3">
                <div class="flex justify-end">
                    <Button variant="primary" @click="openTemplate(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.settings.addTemplate') }}
                    </Button>
                </div>
                <EmptyState v-if="templates.length === 0" :title="t('talent.settings.noTemplates')" :description="t('talent.settings.noTemplatesBody')" />
                <Card v-for="template in templates" :key="template.id" :title="template.name" :description="t(`talent.status.kind.${template.kind}`)" data-testid="template">
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <Badge v-if="template.isDefault" tone="brand">{{ t('talent.settings.defaultBadge') }}</Badge>
                            <Badge v-if="!template.isActive" tone="neutral">{{ t('talent.common.inactive') }}</Badge>
                            <Button size="sm" variant="ghost" @click="openTemplate(template)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                                {{ t('talent.common.edit') }}
                            </Button>
                        </div>
                    </template>
                    <p v-if="template.tasks.length === 0" class="text-sm text-ink-muted">{{ t('talent.settings.noTemplateTasks') }}</p>
                    <ol v-else class="grid gap-1.5">
                        <li v-for="task in template.tasks" :key="task.id" class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span class="text-ink">{{ task.title }}</span>
                            <span class="text-xs text-ink-subtle">
                                {{ task.assignee?.name ?? t(`talent.status.assigneeRole.${task.assigneeRole}`) }}
                                <template v-if="task.dueOffsetDays !== null"> - {{ t('talent.settings.offsetDays', { days: format.number(task.dueOffsetDays) }) }}</template>
                            </span>
                        </li>
                    </ol>
                </Card>
            </div>
        </TabsContent>

        <TabsContent value="forms">
            <Card :title="t('talent.forms.title')" :description="t('talent.settings.formsHint')">
                <Button v-if="can('talent.cycles.manage')" variant="secondary" :href="route('talent.cycles.index')">{{ t('talent.settings.openCycles') }}</Button>
                <p v-else class="text-sm text-ink-muted">{{ t('talent.settings.formsNoAccess') }}</p>
            </Card>
        </TabsContent>
    </TabsRoot>

    <NamedItemDialog
        v-model:open="pipelineOpen"
        :title="editingPipeline ? t('talent.settings.editPipeline') : t('talent.settings.addPipeline')"
        :url="editingPipeline ? route('talent.settings.pipelines.update', { pipeline: editingPipeline.id }) : route('talent.settings.pipelines.store')"
        :method="editingPipeline ? 'put' : 'post'"
        :name="editingPipeline?.name ?? ''"
        :is-active="editingPipeline?.isActive ?? true"
        :is-default="editingPipeline?.isDefault ?? false"
        with-default
        :name-label="t('talent.fields.name')"
    />
    <NamedItemDialog
        v-model:open="reasonOpen"
        :title="editingReason ? t('talent.settings.editReason') : t('talent.settings.addReason')"
        :url="editingReason ? route('talent.settings.rejection-reasons.update', { reason: editingReason.id }) : route('talent.settings.rejection-reasons.store')"
        :method="editingReason ? 'put' : 'post'"
        :name="editingReason?.name ?? ''"
        :is-active="editingReason?.isActive ?? true"
        :name-label="t('talent.settings.reasonName')"
    />
    <StageDialog
        v-if="stagePipeline"
        v-model:open="stageOpen"
        :pipeline-id="stagePipeline.id"
        :stage="editingStage"
        :types="options.stageTypes"
        :next-position="nextPosition"
    />
    <TemplateDialog
        v-model:open="templateOpen"
        :template="editingTemplate"
        :kinds="options.checklistKinds"
        :roles="options.assigneeRoles"
        :employees="employees"
    />
</template>
