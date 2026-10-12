<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Archive, Copy, FlaskConical, KeyRound, MoreHorizontal, Pause, Pencil, Play, RotateCcw, SquarePen } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import IssueList from '../../Components/IssueList.vue';
import PayloadDialog from '../../Components/PayloadDialog.vue';
import WorkflowBadge from '../../Components/WorkflowBadge.vue';
import type { Issue, Revealed, RunRow, VersionRow, WorkflowAbilities, WorkflowRow } from '../../Components/types';
import { asPayload } from '../../lib/http';
import { definitionLabel } from '../../lib/labels';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    workflow: WorkflowRow;
    versions: VersionRow[];
    hasDraft: boolean;
    draftIssues: Issue[];
    publishIssues: Issue[];
    recentRuns: RunRow[];
    revealed: Revealed | null;
    webhookPath: string | null;
    abilities: WorkflowAbilities;
}>();

const { t } = useI18n();
const format = useFormat();
const toasts = useToasts();

const archived = computed(() => props.workflow.status === 'archived');
const canEdit = computed(() => props.abilities.manage && !archived.value);
const canRun = computed(() => props.abilities.start && props.workflow.status === 'active');
const canLifecycle = computed(() => props.abilities.publish && !archived.value);
const triggerLabel = computed(() =>
    props.workflow.triggerKey ? definitionLabel(`automation.triggers.${props.workflow.triggerKey}`, props.workflow.triggerKey) : t('automation.workflows.noTrigger'),
);

const renaming = ref(false);
const confirm = ref<'archive' | 'pause' | 'rotate' | null>(null);
const confirmOpen = computed({ get: () => confirm.value !== null, set: (value: boolean) => { if (!value) confirm.value = null; } });
const running = ref(false);
const testing = ref(false);
const working = ref(false);
const dismissed = ref(false);

const rename = useForm({ name: '', description: '' });

function openRename(): void {
    rename.defaults({ name: props.workflow.name, description: props.workflow.description ?? '' });
    rename.reset();
    rename.clearErrors();
    renaming.value = true;
}

function submitRename(): void {
    rename.transform((data) => ({
        name: data.name.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
    })).put(route('automation.workflows.update', { workflow: props.workflow.id }), {
        preserveScroll: true,
        onSuccess: () => {
            renaming.value = false;
        },
    });
}

function post(name: string, params: Record<string, string> = {}, data: Record<string, unknown> = {}, done?: () => void): void {
    working.value = true;
    router.post(route(name, { workflow: props.workflow.id, ...params }), asPayload(data), {
        preserveScroll: true,
        onFinish: () => {
            working.value = false;
            done?.();
        },
    });
}

function confirmed(): void {
    const action = confirm.value;
    const close = (): void => {
        confirm.value = null;
    };

    if (action === 'archive') {
        post('automation.workflows.archive', {}, {}, close);
    } else if (action === 'pause') {
        post('automation.workflows.pause', {}, {}, close);
    } else if (action === 'rotate') {
        post('automation.workflows.webhook.rotate', {}, {}, close);
    }
}

function startRun(payload: Record<string, unknown>): void {
    post('automation.workflows.run', {}, { payload }, () => {
        running.value = false;
    });
}

function startTest(payload: Record<string, unknown>): void {
    post('automation.workflows.test', {}, { payload }, () => {
        testing.value = false;
    });
}

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toasts.success(t('automation.workflows.webhook.copied'));
    } catch {
        toasts.error(t('automation.workflows.webhook.copy'));
    }
}

const versionColumns = computed<Column[]>(() => [
    { key: 'number', label: t('automation.workflows.show.version') },
    { key: 'status', label: t('automation.common.status') },
    { key: 'note', label: t('automation.workflows.show.versionNote'), hideable: true },
    { key: 'publishedBy', label: t('automation.workflows.show.publishedBy'), hideable: true },
    { key: 'publishedAt', label: t('automation.workflows.show.published'), hideable: true },
    { key: 'actions', label: t('automation.common.actions'), align: 'right', srOnly: true },
]);

const runColumns = computed<Column[]>(() => [
    { key: 'status', label: t('automation.common.status') },
    { key: 'source', label: t('automation.runs.source') },
    { key: 'startedAt', label: t('automation.runs.started') },
    { key: 'steps', label: t('automation.runs.steps'), align: 'right', hideable: true },
]);

function restore(version: VersionRow): void {
    post('automation.workflows.versions.restore', { version: version.id });
}

const confirmTexts = computed(() => {
    if (confirm.value === 'archive') {
        return { title: t('automation.workflows.show.archiveTitle'), body: t('automation.workflows.show.archiveBody'), label: t('automation.workflows.show.archive'), tone: 'danger' as const };
    }

    if (confirm.value === 'pause') {
        return { title: t('automation.workflows.show.pauseTitle'), body: t('automation.workflows.show.pauseBody'), label: t('automation.workflows.show.pause'), tone: 'default' as const };
    }

    return { title: t('automation.workflows.webhook.rotateTitle'), body: t('automation.workflows.webhook.rotateBody'), label: t('automation.workflows.webhook.rotate'), tone: 'danger' as const };
});
</script>

<template>
    <PageHeader :eyebrow="t('automation.workflows.title')" :title="workflow.name" :description="workflow.description ?? undefined">
        <template #actions>
            <Button v-if="canRun" variant="secondary" type="button" @click="running = true">
                <template #icon><Play class="size-4" aria-hidden="true" /></template>
                {{ t('automation.workflows.show.run') }}
            </Button>
            <Button variant="primary" :href="route('automation.workflows.editor', { workflow: workflow.id })">
                <template #icon><SquarePen class="size-4" aria-hidden="true" /></template>
                {{ canEdit ? t('automation.workflows.show.open') : t('automation.workflows.show.view') }}
            </Button>
            <Dropdown v-if="canEdit || canLifecycle">
                <template #trigger>
                    <Button icon variant="secondary" type="button">
                        <template #icon><MoreHorizontal class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.common.actions') }}
                    </Button>
                </template>
                <DropdownItem v-if="canEdit" @select="testing = true">
                    <template #icon><FlaskConical /></template>
                    {{ t('automation.workflows.show.test') }}
                </DropdownItem>
                <DropdownItem v-if="canEdit" @select="openRename">
                    <template #icon><Pencil /></template>
                    {{ t('automation.workflows.show.rename') }}
                </DropdownItem>
                <DropdownItem v-if="abilities.manage" @select="post('automation.workflows.duplicate')">
                    <template #icon><Copy /></template>
                    {{ t('automation.workflows.show.duplicate') }}
                </DropdownItem>
                <DropdownSeparator v-if="canLifecycle" />
                <DropdownItem v-if="canLifecycle && workflow.status === 'active'" @select="confirm = 'pause'">
                    <template #icon><Pause /></template>
                    {{ t('automation.workflows.show.pause') }}
                </DropdownItem>
                <DropdownItem v-if="canLifecycle && workflow.status === 'paused'" @select="post('automation.workflows.resume')">
                    <template #icon><Play /></template>
                    {{ t('automation.workflows.show.resume') }}
                </DropdownItem>
                <DropdownItem v-if="canLifecycle" tone="danger" @select="confirm = 'archive'">
                    <template #icon><Archive /></template>
                    {{ t('automation.workflows.show.archive') }}
                </DropdownItem>
            </Dropdown>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            <Card v-if="revealed && !dismissed" :title="t('automation.workflows.webhook.revealedTitle')">
                <p class="mb-3 rounded-card bg-warn-soft px-4 py-2.5 text-[0.8125rem] text-warn" role="alert">
                    {{ t('automation.workflows.webhook.revealedWarning') }}
                </p>
                <dl class="space-y-3">
                    <div v-for="item in [
                        { key: 'url', label: t('automation.workflows.webhook.url'), value: revealed.url },
                        { key: 'token', label: t('automation.workflows.webhook.token'), value: revealed.token },
                        { key: 'secret', label: t('automation.workflows.webhook.secret'), value: revealed.secret },
                    ]" :key="item.key">
                        <dt class="eyebrow">{{ item.label }}</dt>
                        <dd class="mt-1 flex items-center gap-2">
                            <code class="min-w-0 flex-1 break-all rounded-control bg-control-sunken px-2.5 py-1.5 font-mono text-xs text-ink">{{ item.value }}</code>
                            <Button size="sm" variant="secondary" type="button" @click="copy(item.value)">
                                <template #icon><Copy class="size-4" aria-hidden="true" /></template>
                                {{ t('automation.workflows.webhook.copy') }}
                            </Button>
                        </dd>
                    </div>
                </dl>
                <div class="mt-4 flex justify-end">
                    <Button variant="primary" type="button" @click="dismissed = true">{{ t('automation.workflows.webhook.dismiss') }}</Button>
                </div>
            </Card>

            <Card v-if="publishIssues.length > 0" :title="t('automation.workflows.show.publishIssuesTitle')">
                <IssueList :issues="publishIssues" />
            </Card>

            <Card v-else-if="hasDraft && draftIssues.length > 0" :title="t('automation.workflows.show.issuesTitle')">
                <IssueList :issues="draftIssues" />
            </Card>

            <Card :title="t('automation.workflows.show.recentRuns')">
                <EmptyState v-if="recentRuns.length === 0" :title="t('automation.workflows.show.noRuns')" :description="t('automation.workflows.show.noRunsBody')" compact />
                <DataTable v-else :columns="runColumns" row-key="id" :rows="recentRuns" :caption="t('automation.workflows.show.recentRuns')">
                    <template #cell-status="{ row }">
                        <Link :href="route('automation.runs.show', { run: row.id })" class="hover:opacity-80">
                            <WorkflowBadge kind="run" :value="row.status" />
                        </Link>
                        <Badge v-if="row.mode === 'dry_run'" tone="info" class="ml-1">{{ t('automation.mode.dry_run') }}</Badge>
                    </template>
                    <template #cell-source="{ row }"><span class="text-ink-muted">{{ t(`automation.source.${row.source}`) }}</span></template>
                    <template #cell-startedAt="{ row }"><span class="text-ink-muted">{{ format.date(row.startedAt ?? row.createdAt, 'datetime') }}</span></template>
                    <template #cell-steps="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.stepsExecuted) }}</span></template>
                </DataTable>
                <div v-if="abilities.viewRuns" class="mt-3">
                    <Link :href="route('automation.runs.index', { workflow: workflow.id })" class="text-[0.8125rem] text-brand-text hover:underline">
                        {{ t('automation.workflows.show.allRuns') }}
                    </Link>
                </div>
            </Card>

            <Card flush :title="t('automation.workflows.show.versions')">
                <EmptyState v-if="versions.length === 0" :title="t('automation.workflows.show.noVersions')" compact />
                <DataTable v-else :columns="versionColumns" row-key="id" :rows="versions" :caption="t('automation.workflows.show.versions')">
                    <template #cell-number="{ row }">
                        <span class="tabular text-ink">{{ t('automation.workflows.show.versionNumber', { number: format.number(row.number) }) }}</span>
                        <span v-if="row.id === workflow.publishedVersionId" class="ml-1 text-xs text-ok">{{ t('automation.workflows.show.current') }}</span>
                    </template>
                    <template #cell-status="{ row }"><WorkflowBadge kind="version" :value="row.status" /></template>
                    <template #cell-note="{ row }"><span class="text-ink-muted">{{ row.note ?? t('automation.common.dash') }}</span></template>
                    <template #cell-publishedBy="{ row }"><span class="text-ink-muted">{{ row.publishedBy ?? t('automation.common.dash') }}</span></template>
                    <template #cell-publishedAt="{ row }">
                        <span class="text-ink-muted">{{ format.date(row.publishedAt ?? row.updatedAt, 'datetime') }}</span>
                    </template>
                    <template #cell-actions="{ row }">
                        <Button v-if="canEdit && row.status !== 'draft'" size="sm" variant="ghost" type="button" :disabled="working" @click="restore(row)">
                            <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                            {{ t('automation.workflows.show.restore') }}
                        </Button>
                    </template>
                </DataTable>
            </Card>
        </div>

        <div class="space-y-3">
            <Card :title="t('automation.workflows.show.workflowDetails')">
                <dl class="space-y-3 text-[0.8125rem]">
                    <div>
                        <dt class="eyebrow">{{ t('automation.common.status') }}</dt>
                        <dd class="mt-1"><WorkflowBadge kind="workflow" :value="workflow.status" /></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('automation.workflows.trigger') }}</dt>
                        <dd class="mt-1 text-ink">{{ triggerLabel }}</dd>
                    </div>
                    <div v-if="workflow.actor">
                        <dt class="eyebrow">{{ t('automation.workflows.owner') }}</dt>
                        <dd class="mt-1 text-ink">{{ workflow.actor.name ?? t('automation.common.unknownPerson') }}</dd>
                    </div>
                    <div v-if="workflow.publishedAt">
                        <dt class="eyebrow">{{ t('automation.workflows.show.published') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(workflow.publishedAt, 'datetime') }}</dd>
                    </div>
                    <div v-if="workflow.nextRunAt">
                        <dt class="eyebrow">{{ t('automation.workflows.nextRun') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(workflow.nextRunAt, 'datetime') }}</dd>
                    </div>
                    <div v-if="workflow.lastRunAt">
                        <dt class="eyebrow">{{ t('automation.workflows.lastRun') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.relative(workflow.lastRunAt) }}</dd>
                    </div>
                </dl>
                <p v-if="!workflow.publishedVersionId && !archived" class="mt-4 text-[0.8125rem] text-warn">{{ t('automation.workflows.show.neverPublished') }}</p>
                <p v-if="hasDraft && workflow.publishedVersionId" class="mt-4 text-[0.8125rem] text-ink-muted">{{ t('automation.workflows.show.hasDraft') }}</p>
            </Card>

            <Card :title="t('automation.workflows.webhook.title')">
                <template v-if="workflow.hasWebhook">
                    <p class="text-[0.8125rem] text-ink-muted">{{ t('automation.workflows.webhook.description') }}</p>
                    <p v-if="webhookPath" class="mt-3">
                        <span class="eyebrow block">{{ t('automation.workflows.webhook.path') }}</span>
                        <code class="mt-1 block break-all font-mono text-xs text-ink">{{ webhookPath }}</code>
                    </p>
                    <div v-if="abilities.manageWebhooks && !archived" class="mt-4">
                        <Button size="sm" variant="secondary" type="button" @click="confirm = 'rotate'">
                            <template #icon><KeyRound class="size-4" aria-hidden="true" /></template>
                            {{ t('automation.workflows.webhook.rotate') }}
                        </Button>
                    </div>
                </template>
                <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('automation.workflows.webhook.none') }}</p>
            </Card>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="confirmTexts.title"
        :description="confirmTexts.body"
        :confirm-label="confirmTexts.label"
        :tone="confirmTexts.tone"
        :loading="working"
        @confirm="confirmed"
    />

    <PayloadDialog
        v-model:open="running"
        :title="t('automation.workflows.payload.runTitle')"
        :description="t('automation.workflows.payload.runDescription')"
        :submit-label="t('automation.workflows.payload.runSubmit')"
        :processing="working"
        @submit="startRun"
    />
    <PayloadDialog
        v-model:open="testing"
        :title="t('automation.workflows.payload.testTitle')"
        :description="t('automation.workflows.payload.testDescription')"
        :submit-label="t('automation.workflows.payload.testSubmit')"
        :processing="working"
        @submit="startTest"
    />

    <Modal v-model:open="renaming" :title="t('automation.workflows.show.renameTitle')" :persistent="rename.processing">
        <form id="rename-workflow" class="space-y-4" @submit.prevent="submitRename">
            <Input v-model="rename.name" :label="t('automation.common.name')" :error="rename.errors.name" maxlength="160" required />
            <Textarea v-model="rename.description" :label="t('automation.common.description')" :error="rename.errors.description" optional maxlength="2000" />
        </form>
        <template #footer>
            <Button variant="ghost" type="button" :disabled="rename.processing" @click="renaming = false">{{ t('automation.common.cancel') }}</Button>
            <Button variant="primary" type="submit" form="rename-workflow" :loading="rename.processing" :disabled="rename.name.trim() === ''">
                {{ t('automation.common.save') }}
            </Button>
        </template>
    </Modal>
</template>
