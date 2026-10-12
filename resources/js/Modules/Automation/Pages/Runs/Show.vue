<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, ChevronRight, RotateCcw, Square } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import RunGraph from '../../Components/RunGraph.vue';
import WorkflowBadge from '../../Components/WorkflowBadge.vue';
import type { ApprovalRow, RunRow, StepRow } from '../../Components/types';
import { useMinWidth } from '../../lib/useMinWidth';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    run: RunRow;
    payload: unknown;
    steps: StepRow[];
    approvals: ApprovalRow[];
    graph: unknown;
    abilities: { manage: boolean; viewWorkflows: boolean; cancel: boolean; retry: boolean };
}>();

const { t, te } = useI18n();
const format = useFormat();
const wide = useMinWidth(768);

const expanded = ref<Set<string>>(new Set());
const confirm = ref<'cancel' | 'retry' | null>(null);
const confirmOpen = computed({ get: () => confirm.value !== null, set: (value: boolean) => { if (!value) confirm.value = null; } });
const working = ref(false);

function toggle(id: string): void {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

function pretty(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function stepName(step: StepRow): string {
    if (step.label) {
        return step.label;
    }

    const key = `automation.nodes.${step.nodeType}`;

    return te(key) ? t(key) : step.nodeId;
}

const hasPayload = computed(() => {
    const value = props.payload;

    return typeof value === 'object' && value !== null && Object.keys(value as object).length > 0;
});

function confirmed(): void {
    const action = confirm.value;

    if (action === null) {
        return;
    }

    working.value = true;
    router.post(route(action === 'cancel' ? 'automation.runs.cancel' : 'automation.runs.retry', { run: props.run.id }), {}, {
        preserveScroll: true,
        onFinish: () => {
            working.value = false;
            confirm.value = null;
        },
    });
}

const texts = computed(() =>
    confirm.value === 'retry'
        ? { title: t('automation.runs.show.retryTitle'), body: t('automation.runs.show.retryBody'), label: t('automation.runs.show.retry'), tone: 'default' as const }
        : { title: t('automation.runs.show.cancelTitle'), body: t('automation.runs.show.cancelBody'), label: t('automation.runs.show.cancel'), tone: 'danger' as const },
);

function when(value: string | null): string {
    return value ? format.date(value, 'datetime') : t('automation.common.dash');
}
</script>

<template>
    <PageHeader :eyebrow="t('automation.runs.title')" :title="run.workflowName ?? t('automation.runs.show.title')">
        <template #actions>
            <Button variant="ghost" :href="route('automation.runs.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('automation.runs.show.back') }}
            </Button>
            <Button v-if="abilities.retry" variant="secondary" type="button" @click="confirm = 'retry'">
                <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                {{ t('automation.runs.show.retry') }}
            </Button>
            <Button v-if="abilities.cancel" variant="danger" type="button" @click="confirm = 'cancel'">
                <template #icon><Square class="size-4" aria-hidden="true" /></template>
                {{ t('automation.runs.show.cancel') }}
            </Button>
        </template>
    </PageHeader>

    <p v-if="run.mode === 'dry_run'" class="mb-3 rounded-card bg-info-soft px-4 py-2.5 text-[0.8125rem] text-info" role="note">
        {{ t('automation.runs.show.dryRun') }}
    </p>
    <p v-if="run.cancelRequested && run.status !== 'cancelled'" class="mb-3 rounded-card bg-warn-soft px-4 py-2.5 text-[0.8125rem] text-warn" role="status">
        {{ t('automation.runs.show.cancelRequested') }}
    </p>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            <Card v-if="run.errorCode || run.errorMessage" :title="t('automation.runs.show.error')">
                <p class="text-[0.8125rem] text-danger" role="alert">
                    <span v-if="run.errorCode" class="font-mono">{{ run.errorCode }}</span>
                    <span v-if="run.errorCode && run.errorMessage">: </span>
                    {{ run.errorMessage }}
                </p>
            </Card>

            <Card v-if="wide" :title="t('automation.runs.show.graph')">
                <RunGraph :graph="graph" :steps="steps" :label="t('automation.runs.show.graph')" />
            </Card>

            <Card :title="t('automation.runs.show.stepsTitle')">
                <EmptyState v-if="steps.length === 0" :title="t('automation.runs.show.noSteps')" compact />
                <ol v-else class="space-y-2">
                    <li v-for="step in steps" :key="step.id" class="glass-well rounded-card">
                        <button
                            type="button"
                            class="flex w-full flex-wrap items-center gap-2 rounded-card px-3 py-2.5 text-left"
                            :aria-expanded="expanded.has(step.id)"
                            :aria-controls="`step-${step.id}`"
                            @click="toggle(step.id)"
                        >
                            <component :is="expanded.has(step.id) ? ChevronDown : ChevronRight" class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                            <span class="tabular w-6 shrink-0 text-xs text-ink-subtle">{{ format.number(step.sequence) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[0.8125rem] font-medium text-ink">{{ stepName(step) }}</span>
                                <span class="block text-xs text-ink-subtle">
                                    {{ te(`automation.nodes.${step.nodeType}`) ? t(`automation.nodes.${step.nodeType}`) : step.nodeType }}
                                    <template v-if="step.attempt > 1"> - {{ t('automation.runs.show.attempt', { number: format.number(step.attempt) }) }}</template>
                                    <template v-if="step.iteration"> - {{ t('automation.runs.show.iteration', { number: format.number(step.iteration) }) }}</template>
                                </span>
                            </span>
                            <WorkflowBadge kind="step" :value="step.status" />
                            <span class="sr-only">{{ expanded.has(step.id) ? t('automation.runs.show.hideDetails') : t('automation.runs.show.showDetails') }}</span>
                        </button>

                        <div v-if="expanded.has(step.id)" :id="`step-${step.id}`" class="space-y-3 border-t border-line px-3 py-3">
                            <p v-if="step.errorCode || step.errorMessage" class="text-[0.8125rem] text-danger">
                                <span v-if="step.errorCode" class="font-mono">{{ step.errorCode }}</span>
                                <span v-if="step.errorCode && step.errorMessage">: </span>
                                {{ step.errorMessage }}
                            </p>
                            <dl class="grid gap-x-4 gap-y-1 text-xs text-ink-muted sm:grid-cols-2">
                                <div><dt class="inline eyebrow">{{ t('automation.runs.started') }}</dt> <dd class="inline">{{ when(step.startedAt) }}</dd></div>
                                <div><dt class="inline eyebrow">{{ t('automation.runs.finished') }}</dt> <dd class="inline">{{ when(step.finishedAt) }}</dd></div>
                            </dl>
                            <div>
                                <p class="eyebrow mb-1">{{ t('automation.runs.show.input') }}</p>
                                <pre v-if="pretty(step.input) !== ''" class="max-h-64 overflow-auto rounded-control bg-control-sunken p-2.5 font-mono text-xs text-ink">{{ pretty(step.input) }}</pre>
                                <p v-else class="text-xs text-ink-subtle">{{ t('automation.runs.show.noOutput') }}</p>
                            </div>
                            <div>
                                <p class="eyebrow mb-1">{{ t('automation.runs.show.output') }}</p>
                                <pre v-if="pretty(step.output) !== ''" class="max-h-64 overflow-auto rounded-control bg-control-sunken p-2.5 font-mono text-xs text-ink">{{ pretty(step.output) }}</pre>
                                <p v-else class="text-xs text-ink-subtle">{{ t('automation.runs.show.noOutput') }}</p>
                            </div>
                            <div v-if="step.logs.length > 0">
                                <p class="eyebrow mb-1">{{ t('automation.runs.show.logs') }}</p>
                                <pre class="max-h-64 overflow-auto rounded-control bg-control-sunken p-2.5 font-mono text-xs text-ink">{{ pretty(step.logs) }}</pre>
                            </div>
                        </div>
                    </li>
                </ol>
            </Card>
        </div>

        <div class="space-y-3">
            <Card :title="t('automation.runs.show.summary')">
                <dl class="space-y-3 text-[0.8125rem]">
                    <div>
                        <dt class="eyebrow">{{ t('automation.common.status') }}</dt>
                        <dd class="mt-1">
                            <WorkflowBadge kind="run" :value="run.status" />
                            <Badge v-if="run.mode === 'dry_run'" tone="info" class="ml-1">{{ t('automation.mode.dry_run') }}</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('automation.runs.workflow') }}</dt>
                        <dd class="mt-1 text-ink">
                            <Link v-if="abilities.viewWorkflows" :href="route('automation.workflows.show', { workflow: run.workflowId })" class="hover:text-brand-text">
                                {{ run.workflowName ?? t('automation.common.unnamed') }}
                            </Link>
                            <template v-else>{{ run.workflowName ?? t('automation.common.unnamed') }}</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('automation.runs.source') }}</dt>
                        <dd class="mt-1 text-ink">{{ t(`automation.source.${run.source}`) }}</dd>
                    </div>
                    <div v-if="run.startedBy">
                        <dt class="eyebrow">{{ t('automation.runs.startedBy') }}</dt>
                        <dd class="mt-1 text-ink">{{ run.startedBy }}</dd>
                    </div>
                    <div v-if="run.versionNumber !== null">
                        <dt class="eyebrow">{{ t('automation.runs.version') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.number(run.versionNumber) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('automation.runs.started') }}</dt>
                        <dd class="mt-1 text-ink">{{ when(run.startedAt ?? run.createdAt) }}</dd>
                    </div>
                    <div v-if="run.finishedAt">
                        <dt class="eyebrow">{{ t('automation.runs.finished') }}</dt>
                        <dd class="mt-1 text-ink">{{ when(run.finishedAt) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('automation.runs.steps') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.number(run.stepsExecuted) }}</dd>
                    </div>
                    <div v-if="run.retryCount > 0">
                        <dt class="eyebrow">{{ t('automation.runs.show.retries', { count: format.number(run.retryCount) }) }}</dt>
                    </div>
                    <div v-if="run.resumeAt">
                        <dt class="eyebrow">{{ t('automation.runs.show.resumeAt', { when: format.date(run.resumeAt, 'datetime') }) }}</dt>
                    </div>
                    <div v-if="run.parentRunId">
                        <Link :href="route('automation.runs.show', { run: run.parentRunId })" class="text-brand-text hover:underline">
                            {{ t('automation.runs.show.parent') }}
                        </Link>
                    </div>
                </dl>
            </Card>

            <Card :title="t('automation.runs.show.payload')">
                <pre v-if="hasPayload" class="max-h-72 overflow-auto rounded-control bg-control-sunken p-2.5 font-mono text-xs text-ink">{{ pretty(payload) }}</pre>
                <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('automation.runs.show.payloadEmpty') }}</p>
            </Card>

            <Card v-if="approvals.length > 0" :title="t('automation.runs.show.approvals')">
                <ul class="space-y-3">
                    <li v-for="approval in approvals" :key="approval.id" class="text-[0.8125rem]">
                        <p class="font-medium text-ink">{{ approval.title }}</p>
                        <p class="mt-0.5"><WorkflowBadge kind="approval" :value="approval.status" /></p>
                        <p v-if="approval.decidedBy" class="mt-1 text-xs text-ink-muted">{{ t('automation.approvals.decidedBy', { name: approval.decidedBy }) }}</p>
                        <p v-if="approval.decisionComment" class="mt-0.5 text-xs text-ink-muted">{{ approval.decisionComment }}</p>
                    </li>
                </ul>
            </Card>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="texts.title"
        :description="texts.body"
        :confirm-label="texts.label"
        :tone="texts.tone"
        :loading="working"
        @confirm="confirmed"
    />
</template>
