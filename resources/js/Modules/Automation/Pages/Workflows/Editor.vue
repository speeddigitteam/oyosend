<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, FlaskConical, Rocket, Save, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ConnectionsEditor from '../../Components/ConnectionsEditor.vue';
import EditorCanvas from '../../Components/EditorCanvas.vue';
import IssueList from '../../Components/IssueList.vue';
import NodeConfigPanel from '../../Components/NodeConfigPanel.vue';
import PayloadDialog from '../../Components/PayloadDialog.vue';
import WorkflowBadge from '../../Components/WorkflowBadge.vue';
import { NODE_META_KEY, type NodeMeta } from '../../Components/nodeMeta';
import { nodeSummary } from '../../Components/nodeSummary';
import type { EditorCatalogue, Issue, VersionRow, WorkflowAbilities, WorkflowRow } from '../../Components/types';
import {
    NODE_TYPES,
    connect,
    countIssues,
    createFlowNode,
    fingerprint,
    fromFlow,
    graphLevelIssues,
    issuesByNode,
    outline,
    parseGraph,
    pruneEdges,
    removeNode,
    toFlow,
    toHandleId,
    type ConnectRefusal,
    type FlowEdge,
    type FlowNode,
    type NodeType,
    type OutlineEntry,
} from '../../lib/graph';
import { asPayload, sendJson } from '../../lib/http';
import { useMinWidth } from '../../lib/useMinWidth';
import { variableSuggestions } from '../../lib/schema';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    workflow: WorkflowRow;
    version: VersionRow;
    readOnly: boolean;
    issues: Issue[];
    publishIssues: Issue[];
    catalogue: EditorCatalogue;
    abilities: WorkflowAbilities;
}>();

const { t } = useI18n();
const format = useFormat();
const toasts = useToasts();
const page = usePage();

const initial = toFlow(parseGraph(props.version.graph));
const nodes = ref<FlowNode[]>(initial.nodes);
const edges = ref<FlowEdge[]>(initial.edges);
const issues = ref<Issue[]>(props.publishIssues.length > 0 ? props.publishIssues : props.issues);
const savedPrint = ref(fingerprint(fromFlow(nodes.value, edges.value)));
const saving = ref(false);
const validating = ref(false);
const saveError = ref<string | null>(null);
const publishOpen = ref(false);
const publishNote = ref('');
const testOpen = ref(false);
const removeOpen = ref(false);
const processing = ref(false);
const canvas = ref<InstanceType<typeof EditorCanvas> | null>(null);
const wide = useMinWidth(768);

const locked = computed(() => props.readOnly);
const current = computed(() => fromFlow(nodes.value, edges.value));
const currentPrint = computed(() => fingerprint(current.value));
const dirty = computed(() => !locked.value && currentPrint.value !== savedPrint.value);
const counts = computed(() => countIssues(issues.value));
const hasTrigger = computed(() => nodes.value.some((node) => node.data.nodeType === 'trigger'));
const atNodeLimit = computed(() => nodes.value.length >= props.catalogue.limits.maxNodes);
const selected = computed(() => {
    const chosen = nodes.value.filter((node) => node.selected === true);

    return chosen.length === 1 ? chosen[0] : null;
});
const byNode = computed(() => issuesByNode(issues.value));
const wholeGraphIssues = computed(() => graphLevelIssues(issues.value));
const graphError = computed(() => (page.props.errors as Record<string, string> | undefined)?.graph ?? null);

const suggestions = computed(() => variableSuggestions(nodes.value, props.catalogue, selected.value?.id ?? null));
const rows = computed(() => outline(nodes.value, edges.value));

const meta = computed(() => {
    const map = new Map<string, NodeMeta>();

    for (const node of nodes.value) {
        const found = byNode.value.get(node.id) ?? [];
        map.set(node.id, {
            summary: nodeSummary(node, props.catalogue, t, (value) => format.number(value)),
            errors: found.filter((issue) => issue.severity === 'error').length,
            warnings: found.filter((issue) => issue.severity === 'warning').length,
        });
    }

    return map;
});
provide(NODE_META_KEY, meta);

function nodeName(id: string): string {
    const node = nodes.value.find((item) => item.id === id);

    if (!node) {
        return id;
    }

    return node.data.label !== '' ? node.data.label : t(`automation.nodes.${node.data.nodeType}`);
}

const palette = computed(() =>
    NODE_TYPES.map((type) => ({
        type,
        disabled: locked.value || atNodeLimit.value || (type === 'trigger' && hasTrigger.value),
        reason: type === 'trigger' && hasTrigger.value ? t('automation.editor.triggerOnce') : atNodeLimit.value ? t('automation.editor.nodeLimit', { max: props.catalogue.limits.maxNodes }) : '',
    })),
);

function select(id: string | null): void {
    nodes.value = nodes.value.map((node) => ({ ...node, selected: id !== null && node.id === id }));
}

function addNode(type: NodeType): void {
    if (locked.value) {
        return;
    }

    const anchor = selected.value ?? [...nodes.value].sort((a, b) => b.position.y - a.position.y)[0];
    const position = anchor ? { x: anchor.position.x, y: anchor.position.y + 150 } : { x: 0, y: 0 };
    const node = createFlowNode(type, nodes.value.map((item) => item.id), position, {
        maxLoopIterations: props.catalogue.limits.maxLoopIterations,
        approvalHours: props.catalogue.limits.approvalDefaultHours,
    });

    nodes.value = [...nodes.value.map((item) => ({ ...item, selected: false })), { ...node, selected: true }];

    // The new step is placed below the lowest one, often outside the visible canvas.
    void nextTick(() => canvas.value?.fitView());
}

async function refreshHandles(id: string): Promise<void> {
    await nextTick();
    canvas.value?.refreshNode(id);
}

function updateNode(id: string, values: { label?: string; config?: Record<string, unknown> }): void {
    nodes.value = nodes.value.map((node) =>
        node.id === id
            ? { ...node, data: { ...node.data, ...(values.label !== undefined ? { label: values.label } : {}), ...(values.config ? { config: values.config } : {}) } }
            : node,
    );

    if (values.config) {
        edges.value = pruneEdges(nodes.value, edges.value);
        void refreshHandles(id);
    }
}

function removeSelected(): void {
    const node = selected.value;

    if (!node || locked.value) {
        return;
    }

    const next = removeNode(nodes.value, edges.value, node.id);
    nodes.value = next.nodes;
    edges.value = next.edges;
    removeOpen.value = false;
}

function refusal(reason: ConnectRefusal): void {
    toasts.warning(t(`automation.editor.connectionRefused.${reason}`));
}

function connectFromPanel(payload: { handle: string | null; target: string }): void {
    const node = selected.value;

    if (!node || locked.value) {
        return;
    }

    const result = connect(nodes.value, edges.value, { source: node.id, target: payload.target, sourceHandle: toHandleId(payload.handle) });

    if (result.edges === null) {
        refusal(result.refusal as ConnectRefusal);

        return;
    }

    edges.value = result.edges;
}

function disconnect(edgeId: string): void {
    edges.value = edges.value.filter((edge) => edge.id !== edgeId);
}

// Saving and live validation.
let validation: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;

async function validate(): Promise<void> {
    validation?.abort();
    const controller = new AbortController();
    validation = controller;
    validating.value = true;

    try {
        const response = await sendJson<{ valid: boolean; issues: Issue[] }>(
            'POST',
            route('automation.workflows.validate', { workflow: props.workflow.id }),
            { graph: current.value },
            controller.signal,
        );

        if (validation === controller && response.ok && response.data) {
            issues.value = response.data.issues;
        }
    } catch {
        // Superseded by a newer request.
    } finally {
        if (validation === controller) {
            validating.value = false;
        }
    }
}

watch(currentPrint, () => {
    if (locked.value) {
        return;
    }

    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void validate(), 700);
});

async function save(): Promise<boolean> {
    if (locked.value || saving.value) {
        return !dirty.value;
    }

    saving.value = true;
    saveError.value = null;
    const print = currentPrint.value;

    try {
        const response = await sendJson<{ valid: boolean; issues: Issue[] }>(
            'PUT',
            route('automation.workflows.draft', { workflow: props.workflow.id }),
            { graph: current.value },
        );

        if (!response.ok) {
            saveError.value = response.message ?? (response.status === 0 ? t('automation.editor.saveFailed') : t('automation.editor.saveRefused'));
            toasts.error(saveError.value);

            return false;
        }

        savedPrint.value = print;
        issues.value = response.data?.issues ?? issues.value;

        return true;
    } finally {
        saving.value = false;
    }
}

async function saveClicked(): Promise<void> {
    if (await save()) {
        toasts.success(t('automation.editor.saved'));
    }
}

async function publish(): Promise<void> {
    if (!(await save())) {
        return;
    }

    processing.value = true;
    router.post(
        route('automation.workflows.publish', { workflow: props.workflow.id }),
        { note: publishNote.value.trim() === '' ? null : publishNote.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                publishOpen.value = false;
            },
        },
    );
}

async function runTest(payload: Record<string, unknown>): Promise<void> {
    if (!(await save())) {
        return;
    }

    processing.value = true;
    router.post(route('automation.workflows.test', { workflow: props.workflow.id }), { payload: asPayload(payload) }, {
        onFinish: () => {
            processing.value = false;
            testOpen.value = false;
        },
    });
}

function editAsDraft(): void {
    router.post(route('automation.workflows.versions.restore', { workflow: props.workflow.id, version: props.version.id }));
}

watch(
    () => props.publishIssues,
    (value) => {
        if (value.length > 0) {
            issues.value = value;
        }
    },
);

const canEditAsDraft = computed(
    () => props.readOnly && props.version.status !== 'draft' && props.abilities.manage && props.workflow.status !== 'archived',
);
const readOnlyNotice = computed(() => {
    if (props.workflow.status === 'archived') {
        return t('automation.editor.archivedNotice');
    }

    return props.version.status !== 'draft' ? t('automation.editor.readOnly') : t('automation.editor.readOnlyNoAccess');
});

// Leave guard.
function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) {
        event.preventDefault();
    }
}

let removeRouterGuard: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeRouterGuard = router.on('before', (event) => {
        const target = event.detail.visit.url.pathname;

        if (dirty.value && !target.includes(`/automation/workflows/${props.workflow.id}`) && !window.confirm(t('automation.editor.leavePrompt'))) {
            event.preventDefault();
        }
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    removeRouterGuard?.();

    if (timer) {
        clearTimeout(timer);
    }

    validation?.abort();
});

function handleText(entry: OutlineEntry): string[] {
    return entry.outgoing.map((link) => {
        const via = link.handle === null ? t('automation.handles.next') : t(`automation.handles.${link.handle}`, link.handle);

        return `${via}: ${nodeName(link.target)}`;
    });
}

</script>

<template>
    <PageHeader :eyebrow="t('automation.eyebrow')" :title="workflow.name" :description="t('automation.editor.title')">
        <template #actions>
            <Button :href="route('automation.workflows.show', { workflow: workflow.id })" variant="ghost">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.backToWorkflow') }}
            </Button>
        </template>
    </PageHeader>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <WorkflowBadge kind="version" :value="version.status" />
        <Badge tone="neutral">
            {{ version.status === 'draft' ? t('automation.editor.draftLabel', { number: format.number(version.number) }) : t('automation.editor.versionLabel', { number: format.number(version.number) }) }}
        </Badge>
        <span v-if="!locked" class="text-[0.8125rem]" :class="saveError ? 'text-danger' : dirty ? 'text-warn' : 'text-ink-subtle'" role="status">
            {{ saving ? t('automation.editor.saving') : saveError ? saveError : dirty ? t('automation.editor.unsaved') : t('automation.editor.saved') }}
        </span>
        <span class="text-[0.8125rem]" :class="counts.errors > 0 ? 'text-danger' : 'text-ink-subtle'" role="status">
            <template v-if="validating">{{ t('automation.editor.validating') }}</template>
            <template v-else-if="counts.errors + counts.warnings === 0">{{ t('automation.editor.valid') }}</template>
            <template v-else>
                {{ t('automation.editor.errorsCount', { count: format.number(counts.errors) }) }},
                {{ t('automation.editor.warningsCount', { count: format.number(counts.warnings) }) }}
            </template>
        </span>

        <div class="ml-auto flex flex-wrap gap-2">
            <Button v-if="canEditAsDraft" variant="primary" type="button" @click="editAsDraft">{{ t('automation.editor.editAsDraft') }}</Button>
            <template v-if="!locked">
                <Button variant="secondary" type="button" :loading="saving" :disabled="!dirty" @click="saveClicked">
                    <template #icon><Save class="size-4" aria-hidden="true" /></template>
                    {{ t('automation.editor.saveDraft') }}
                </Button>
                <Button variant="secondary" type="button" @click="testOpen = true">
                    <template #icon><FlaskConical class="size-4" aria-hidden="true" /></template>
                    {{ t('automation.editor.test') }}
                </Button>
                <Button v-if="abilities.publish" variant="primary" type="button" @click="publishOpen = true">
                    <template #icon><Rocket class="size-4" aria-hidden="true" /></template>
                    {{ t('automation.editor.publish') }}
                </Button>
            </template>
        </div>
    </div>

    <p v-if="locked" class="mb-3 rounded-card bg-info-soft px-4 py-2.5 text-[0.8125rem] text-info" role="note">{{ readOnlyNotice }}</p>
    <p v-if="graphError" class="mb-3 rounded-card bg-danger-soft px-4 py-2.5 text-[0.8125rem] text-danger" role="alert">
        {{ t('automation.editor.publishRefused') }} {{ graphError }}
    </p>

    <!-- Small screens: the structure as a list. Editing needs the canvas. -->
    <div v-if="!wide" class="space-y-3">
        <p class="rounded-card bg-info-soft px-4 py-2.5 text-[0.8125rem] text-info" role="note">{{ t('automation.editor.mobileNotice') }}</p>
        <Card :title="t('automation.editor.outline')">
            <ol class="space-y-3">
                <li v-for="entry in rows" :key="entry.node.id" class="glass-well rounded-card p-3" :style="{ marginInlineStart: `${Math.min(entry.depth, 4) * 8}px` }">
                    <p class="eyebrow">{{ t(`automation.nodes.${entry.node.data.nodeType}`) }}</p>
                    <p class="text-[0.8125rem] font-medium text-ink">{{ nodeName(entry.node.id) }}</p>
                    <p v-if="meta.get(entry.node.id)?.summary" class="text-xs text-ink-muted">{{ meta.get(entry.node.id)?.summary }}</p>
                    <p v-if="!entry.reachable" class="text-xs text-warn">{{ t('automation.editor.unreachable') }}</p>
                    <ul v-if="handleText(entry).length > 0" class="mt-1 space-y-0.5 text-xs text-ink-subtle">
                        <li v-for="line in handleText(entry)" :key="line">{{ line }}</li>
                    </ul>
                    <IssueList v-if="byNode.get(entry.node.id)" class="mt-2" :issues="byNode.get(entry.node.id) ?? []" />
                </li>
            </ol>
        </Card>
        <Card v-if="issues.length > 0" :title="t('automation.editor.overview')">
            <IssueList :issues="issues" :name-of="nodeName" />
        </Card>
    </div>

    <!-- The editor -->
    <div v-else class="grid gap-3 lg:grid-cols-[13rem_minmax(0,1fr)_22rem]">
        <Card v-if="!locked" :title="t('automation.editor.palette')" :description="t('automation.editor.paletteHint')" class="lg:order-1">
            <ul class="grid grid-cols-2 gap-1.5 lg:grid-cols-1">
                <li v-for="item in palette" :key="item.type">
                    <button
                        type="button"
                        class="w-full rounded-control border border-line bg-control px-2.5 py-1.5 text-left text-[0.8125rem] text-ink transition-colors hover:bg-control-hover disabled:opacity-55"
                        :disabled="item.disabled"
                        :title="item.reason !== '' ? item.reason : t(`automation.nodeHelp.${item.type}`)"
                        @click="addNode(item.type)"
                    >
                        <span class="block font-medium">{{ t(`automation.nodes.${item.type}`) }}</span>
                        <span class="block text-xs text-ink-subtle">{{ t(`automation.nodeHelp.${item.type}`) }}</span>
                    </button>
                </li>
            </ul>
        </Card>

        <div class="min-w-0 space-y-3 lg:order-2">
            <div class="glass-well h-[60vh] min-h-[28rem] overflow-hidden rounded-card border border-line">
                <EditorCanvas
                    ref="canvas"
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    :read-only="locked"
                    :label="t('automation.editor.canvas')"
                    @refused="refusal"
                />
            </div>
            <p v-if="!locked" class="text-xs text-ink-subtle">{{ t('automation.editor.canvasHint') }}</p>
        </div>

        <Card class="lg:order-3" :title="selected ? t('automation.editor.node.title') : t('automation.editor.overview')">
            <div v-if="selected" :key="selected.id" class="space-y-5">
                <div>
                    <p class="eyebrow">{{ t(`automation.nodes.${selected.data.nodeType}`) }}</p>
                    <p class="text-xs text-ink-subtle">{{ t(`automation.nodeHelp.${selected.data.nodeType}`) }}</p>
                </div>

                <Input
                    :model-value="selected.data.label"
                    :label="t('automation.editor.node.label')"
                    :hint="t('automation.editor.node.labelHint')"
                    :disabled="locked"
                    maxlength="120"
                    optional
                    @update:model-value="updateNode(selected.id, { label: String($event ?? '') })"
                />

                <NodeConfigPanel
                    :node="selected"
                    :catalogue="catalogue"
                    :suggestions="suggestions"
                    :nodes="nodes"
                    :disabled="locked"
                    :can-manage-webhooks="abilities.manageWebhooks"
                    @update:config="updateNode(selected.id, { config: $event })"
                />

                <section class="border-t border-line pt-4">
                    <h3 class="text-[0.9375rem]">{{ t('automation.editor.node.connections') }}</h3>
                    <p class="mb-3 text-xs text-ink-subtle">{{ t('automation.editor.node.connectionsHint') }}</p>
                    <ConnectionsEditor
                        :node="selected"
                        :nodes="nodes"
                        :edges="edges"
                        :disabled="locked"
                        @connect="connectFromPanel"
                        @disconnect="disconnect"
                    />
                </section>

                <section v-if="byNode.get(selected.id)" class="border-t border-line pt-4">
                    <h3 class="mb-2 text-[0.9375rem]">{{ t('automation.editor.node.problems') }}</h3>
                    <IssueList :issues="byNode.get(selected.id) ?? []" />
                </section>

                <div v-if="!locked" class="border-t border-line pt-4">
                    <Button variant="danger" size="sm" type="button" @click="removeOpen = true">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.editor.node.remove') }}
                    </Button>
                </div>

                <p class="text-xs text-ink-subtle">{{ t('automation.editor.node.id') }}: <span class="font-mono">{{ selected.id }}</span></p>
            </div>

            <div v-else class="space-y-4">
                <p class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.selectNothing') }}</p>
                <p v-if="issues.length === 0" class="text-[0.8125rem] text-ok">{{ t('automation.editor.noProblems') }}</p>
                <template v-else>
                    <section v-if="wholeGraphIssues.length > 0">
                        <h3 class="mb-2 text-[0.9375rem]">{{ t('automation.editor.graphProblems') }}</h3>
                        <IssueList :issues="wholeGraphIssues" />
                    </section>
                    <section v-if="issues.length > wholeGraphIssues.length">
                        <h3 class="mb-2 text-[0.9375rem]">{{ t('automation.editor.problems') }}</h3>
                        <IssueList :issues="issues.filter((issue) => issue.node_id !== null)" :name-of="nodeName" selectable @select="select" />
                    </section>
                </template>
            </div>
        </Card>
    </div>

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('automation.editor.node.removeTitle')"
        :description="t('automation.editor.node.removeBody')"
        :confirm-label="t('automation.editor.node.remove')"
        @confirm="removeSelected"
    />

    <Modal v-model:open="publishOpen" :title="t('automation.editor.publishTitle')" :description="t('automation.editor.publishBody')" :persistent="processing">
        <Input v-model="publishNote" :label="t('automation.editor.publishNote')" maxlength="255" optional />
        <IssueList v-if="counts.errors > 0" class="mt-4" :issues="issues.filter((issue) => issue.severity === 'error')" :name-of="nodeName" />
        <template #footer>
            <Button variant="ghost" type="button" :disabled="processing" @click="publishOpen = false">{{ t('automation.common.cancel') }}</Button>
            <Button variant="primary" type="button" :loading="processing" @click="publish">{{ t('automation.editor.publishSubmit') }}</Button>
        </template>
    </Modal>

    <PayloadDialog
        v-model:open="testOpen"
        :title="t('automation.workflows.payload.testTitle')"
        :description="t('automation.workflows.payload.testDescription')"
        :submit-label="t('automation.workflows.payload.testSubmit')"
        :processing="processing"
        @submit="runTest"
    />
</template>
