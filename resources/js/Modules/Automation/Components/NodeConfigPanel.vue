<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { moduleLabel, permissionLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import { branchCases, nextCaseId, type FlowNode } from '../lib/graph';
import { definitionLabel } from '../lib/labels';
import KeyValueEditor from './KeyValueEditor.vue';
import RuleGroupEditor, { type RuleGroup } from './RuleGroupEditor.vue';
import SchemaFields from './SchemaFields.vue';
import type { CatalogueDefinition, EditorCatalogue } from './types';
import VariableInput from './VariableInput.vue';

/**
 * The settings of one selected step. Every control is declarative: a choice
 * from the server's catalogue, a number, text, or a value from the run. There
 * is no free code or expression field anywhere.
 */
const props = defineProps<{
    node: FlowNode;
    catalogue: EditorCatalogue;
    suggestions: string[];
    nodes: FlowNode[];
    disabled: boolean;
    canManageWebhooks: boolean;
}>();

const emit = defineEmits<{ 'update:config': [value: Record<string, unknown>] }>();
const { t } = useI18n();

type Config = Record<string, unknown>;
type Choice = string | number | null | undefined;

const config = computed<Config>(() => props.node.data.config);
const type = computed(() => props.node.data.nodeType);

function patch(values: Config, remove: string[] = []): void {
    const next: Config = { ...config.value, ...values };

    for (const key of remove) {
        delete next[key];
    }

    emit('update:config', next);
}

function str(value: unknown): string {
    return typeof value === 'string' ? value : value === undefined || value === null ? '' : String(value);
}

function whole(raw: Choice): number | undefined {
    const text = String(raw ?? '').trim();

    return /^-?\d{1,9}$/.test(text) ? Number.parseInt(text, 10) : undefined;
}

function setWhole(key: string, raw: Choice): void {
    const value = whole(raw);

    if (value === undefined) {
        patch({}, [key]);
    } else {
        patch({ [key]: value });
    }
}

function record(value: unknown): Config {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Config) : {};
}

function definitionOptions(items: CatalogueDefinition[], selected: string): { value: string; label: string; disabled?: boolean }[] {
    const options = items
        .map((item) => ({
            value: item.key,
            label: `${moduleLabel(item.module)}: ${definitionLabel(item.label, item.key)}`,
        }))
        .sort((a, b) => a.label.localeCompare(b.label));

    if (selected !== '' && !items.some((item) => item.key === selected)) {
        options.unshift({ value: selected, label: selected });
    }

    return [{ value: '', label: t('automation.common.choose'), disabled: true }, ...options];
}

// Trigger.
const triggerKey = computed(() => str(config.value.trigger_key));
const trigger = computed(() => props.catalogue.triggers.find((item) => item.key === triggerKey.value));
const triggerOptions = computed(() => definitionOptions(props.catalogue.triggers, triggerKey.value));

function setTrigger(key: Choice): void {
    patch({ trigger_key: str(key), config: {} });
}

// Action.
const actionKey = computed(() => str(config.value.action_key));
const action = computed(() => props.catalogue.actions.find((item) => item.key === actionKey.value));
const actionOptions = computed(() => definitionOptions(props.catalogue.actions, actionKey.value));

function setAction(key: Choice): void {
    patch({ action_key: str(key), input: {} });
}

// Retries (action and webhook).
const retry = computed<Config | null>(() => (typeof config.value.retry === 'object' && config.value.retry !== null ? record(config.value.retry) : null));

function toggleRetry(on: boolean): void {
    if (on) {
        patch({ retry: { max_attempts: 3, backoff_seconds: 30 } });
    } else {
        patch({}, ['retry']);
    }
}

function setRetry(key: 'max_attempts' | 'backoff_seconds', raw: Choice): void {
    const value = whole(raw);
    patch({ retry: { ...(retry.value ?? {}), ...(value === undefined ? {} : { [key]: value }) } });
}

// Branch.
const cases = computed(() => branchCases(config.value));

function setCases(next: Config[]): void {
    patch({ cases: next });
}

function rawCases(): Config[] {
    return Array.isArray(config.value.cases) ? (config.value.cases as Config[]) : [];
}

function addCase(): void {
    setCases([...rawCases(), { id: nextCaseId(config.value), label: '', match: 'all', rules: [{ field: '', operator: 'equals', value: '' }] }]);
}

function updateCase(index: number, values: Config): void {
    setCases(rawCases().map((item, position) => (position === index ? { ...item, ...values } : item)));
}

function removeCase(index: number): void {
    setCases(rawCases().filter((_, position) => position !== index));
}

// Approval.
const approver = computed(() => record(config.value.approver));
const approverType = computed(() => (approver.value.type === 'membership' ? 'membership' : 'permission'));
const permissionOptions = computed(() => [
    { value: '', label: t('automation.common.choose'), disabled: true },
    ...props.catalogue.approverPermissions
        .map((item) => ({ value: item.name, label: permissionLabel(item.name) }))
        .sort((a, b) => a.label.localeCompare(b.label)),
]);
const memberOptions = computed(() => [
    { value: '', label: t('automation.common.choose'), disabled: true },
    ...props.catalogue.members.map((member) => ({ value: member.id, label: member.name })),
]);

function setApproverType(value: Choice): void {
    patch({ approver: value === 'membership' ? { type: 'membership', membership_id: '' } : { type: 'permission', permission: '' } });
}

// Webhook.
const endpointOptions = computed(() => [
    { value: '', label: t('automation.editor.webhook.chooseEndpoint'), disabled: true },
    ...props.catalogue.webhookEndpoints.map((endpoint) => ({
        value: endpoint.id,
        label: endpoint.active ? endpoint.name : `${endpoint.name} (${t('automation.editor.webhook.inactive')})`,
    })),
]);

// Transform.
const mappings = computed(() => (Array.isArray(config.value.mappings) ? (config.value.mappings as Config[]) : []));
const operationOptions = computed(() => props.catalogue.mappingOperations.map((operation) => ({ value: operation.key, label: t(`automation.mapping.${operation.key}`) })));

function operationFields(key: unknown): string[] {
    return props.catalogue.mappingOperations.find((operation) => operation.key === key)?.fields ?? [];
}

function setMappings(next: Config[]): void {
    patch({ mappings: next });
}

function addMapping(): void {
    setMappings([...mappings.value, { target: '', operation: 'copy', source: '' }]);
}

function updateMapping(index: number, values: Config): void {
    setMappings(mappings.value.map((item, position) => (position === index ? { ...item, ...values } : item)));
}

function setOperation(index: number, operation: Choice): void {
    const current = mappings.value[index];
    const fields = operationFields(operation);
    const next: Config = { target: str(current.target), operation: str(operation) };

    for (const field of fields) {
        next[field] = field === 'values' ? [] : '';
    }

    setMappings(mappings.value.map((item, position) => (position === index ? next : item)));
}

function removeMapping(index: number): void {
    setMappings(mappings.value.filter((_, position) => position !== index));
}

function lines(value: unknown): string {
    return Array.isArray(value) ? value.map((item) => String(item)).join('\n') : str(value);
}

function setLines(index: number, raw: string | null | undefined): void {
    updateMapping(index, { values: String(raw ?? '').split('\n').map((line) => line.trim()).filter((line) => line !== '') });
}

// Loop.
const loopMode = computed(() => (config.value.count !== undefined ? 'count' : 'items'));

function setLoopMode(mode: Choice): void {
    if (mode === 'count') {
        patch({ count: 1 }, ['items']);
    } else {
        patch({ items: '' }, ['count']);
    }
}

// Parallel.
const joinOptions = computed(() => [
    { value: '', label: t('automation.editor.parallel.joinNone') },
    ...props.nodes
        .filter((item) => item.id !== props.node.id && item.data.nodeType !== 'trigger')
        .map((item) => ({ value: item.id, label: item.data.label !== '' ? `${item.data.label} (${item.id})` : `${t(`automation.nodes.${item.data.nodeType}`)} (${item.id})` })),
]);

function setJoin(value: Choice): void {
    if (value === '' || value === null) {
        patch({}, ['join_node']);
    } else {
        patch({ join_node: String(value) });
    }
}

const payloadExample = '{' + '{ trigger.name }' + '}';

const hint = computed(() => ({
    delay: t('automation.editor.delay.hint', { days: props.catalogue.limits.maxDelayDays }),
    parallel: t('automation.editor.parallel.hint', { max: props.catalogue.limits.maxParallelBranches }),
    timeout: t('automation.editor.approval.timeoutHint', { max: props.catalogue.limits.approvalMaxHours }),
}));
</script>

<template>
    <fieldset :disabled="disabled" class="min-w-0 space-y-4 border-0 p-0">
        <!-- Trigger -->
        <template v-if="type === 'trigger'">
            <Select
                :model-value="triggerKey"
                :options="triggerOptions"
                :label="t('automation.editor.trigger.kind')"
                :disabled="disabled"
                @update:model-value="setTrigger"
            />
            <p v-if="trigger && !trigger.allowed" class="text-[0.8125rem] text-warn">{{ t('automation.editor.trigger.notAllowed') }}</p>
            <div v-if="trigger">
                <p class="eyebrow mb-2">{{ t('automation.editor.trigger.filters') }}</p>
                <SchemaFields
                    v-if="Object.keys(trigger.inputSchema.properties ?? {}).length > 0"
                    :schema="trigger.inputSchema"
                    :model-value="record(config.config)"
                    :disabled="disabled"
                    @update:model-value="patch({ config: $event })"
                />
                <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.trigger.noFilters') }}</p>
            </div>
        </template>

        <!-- Condition -->
        <RuleGroupEditor
            v-else-if="type === 'condition'"
            :group="config as RuleGroup"
            :operators="catalogue.operators"
            :suggestions="suggestions"
            :disabled="disabled"
            @update:group="emit('update:config', $event)"
        />

        <!-- Branch -->
        <template v-else-if="type === 'branch'">
            <p class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.branch.casesHint') }}</p>
            <div v-for="(item, index) in cases" :key="item.id || index" class="glass-well space-y-3 rounded-card p-3">
                <div class="flex items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <Input
                            :model-value="item.label ?? ''"
                            :label="t('automation.editor.branch.caseLabel')"
                            :hint="t('automation.editor.branch.caseId', { id: item.id })"
                            :disabled="disabled"
                            maxlength="60"
                            @update:model-value="updateCase(index, { label: String($event ?? '') })"
                        />
                    </div>
                    <Button v-if="!disabled && cases.length > 1" icon size="sm" variant="ghost" type="button" @click="removeCase(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.editor.branch.removeCase') }}
                    </Button>
                </div>
                <RuleGroupEditor
                    :group="{ match: item.match, rules: item.rules }"
                    :operators="catalogue.operators"
                    :suggestions="suggestions"
                    :disabled="disabled"
                    @update:group="updateCase(index, { match: $event.match, rules: $event.rules })"
                />
            </div>
            <Button v-if="!disabled && cases.length < 10" size="sm" variant="secondary" type="button" @click="addCase">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.branch.addCase') }}
            </Button>
        </template>

        <!-- Action -->
        <template v-else-if="type === 'action'">
            <Select
                :model-value="actionKey"
                :options="actionOptions"
                :label="t('automation.editor.action.kind')"
                :disabled="disabled"
                @update:model-value="setAction"
            />
            <p v-if="action?.requiredPermission" class="text-[0.8125rem] text-ink-subtle">
                {{ t('automation.editor.action.requires', { permission: permissionLabel(action.requiredPermission) }) }}
            </p>
            <p v-if="action && !action.allowed" class="text-[0.8125rem] text-warn">{{ t('automation.editor.action.notAllowed') }}</p>
            <div v-if="action">
                <p class="eyebrow mb-2">{{ t('automation.editor.action.inputs') }}</p>
                <SchemaFields
                    v-if="Object.keys(action.inputSchema.properties ?? {}).length > 0"
                    :schema="action.inputSchema"
                    :model-value="record(config.input)"
                    :suggestions="suggestions"
                    templates
                    :disabled="disabled"
                    @update:model-value="patch({ input: $event })"
                />
                <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.action.noInputs') }}</p>
            </div>
        </template>

        <!-- Delay -->
        <template v-else-if="type === 'delay'">
            <Input
                :model-value="str(config.amount)"
                type="number"
                min="1"
                :label="t('automation.editor.delay.amount')"
                :hint="hint.delay"
                :disabled="disabled"
                @update:model-value="setWhole('amount', $event)"
            />
            <Select
                :model-value="str(config.unit) || 'hours'"
                :options="[
                    { value: 'minutes', label: t('automation.editor.delay.minutes') },
                    { value: 'hours', label: t('automation.editor.delay.hours') },
                    { value: 'days', label: t('automation.editor.delay.days') },
                ]"
                :label="t('automation.editor.delay.unit')"
                :disabled="disabled"
                @update:model-value="patch({ unit: str($event) })"
            />
        </template>

        <!-- Schedule -->
        <template v-else-if="type === 'schedule'">
            <Select
                :model-value="config.mode === 'at' ? 'at' : 'cron'"
                :options="[
                    { value: 'cron', label: t('automation.editor.schedule.cron') },
                    { value: 'at', label: t('automation.editor.schedule.at') },
                ]"
                :label="t('automation.editor.schedule.mode')"
                :disabled="disabled"
                @update:model-value="$event === 'at' ? patch({ mode: 'at', at: '' }, ['cron']) : patch({ mode: 'cron', cron: '0 9 * * *' }, ['at'])"
            />
            <Input
                v-if="config.mode !== 'at'"
                :model-value="str(config.cron)"
                :label="t('automation.editor.schedule.cronField')"
                :hint="t('automation.editor.schedule.cronHint')"
                :disabled="disabled"
                mono
                maxlength="100"
                @update:model-value="patch({ cron: str($event) })"
            />
            <VariableInput
                v-else
                :model-value="str(config.at)"
                :label="t('automation.editor.schedule.atField')"
                :hint="t('automation.editor.schedule.atHint')"
                mode="template"
                :suggestions="suggestions"
                :disabled="disabled"
                @update:model-value="patch({ at: $event })"
            />
        </template>

        <!-- Parallel -->
        <template v-else-if="type === 'parallel'">
            <p class="text-[0.8125rem] text-ink-subtle">{{ hint.parallel }}</p>
            <Select
                :model-value="str(config.join_node)"
                :options="joinOptions"
                :label="t('automation.editor.parallel.join')"
                :hint="t('automation.editor.parallel.joinHint')"
                :disabled="disabled"
                @update:model-value="setJoin"
            />
        </template>

        <!-- Approval -->
        <template v-else-if="type === 'approval'">
            <Input
                :model-value="str(config.title)"
                :label="t('automation.editor.approval.title')"
                :disabled="disabled"
                maxlength="200"
                @update:model-value="patch({ title: str($event) })"
            />
            <Textarea
                :model-value="str(config.message)"
                :label="t('automation.editor.approval.message')"
                :disabled="disabled"
                optional
                maxlength="2000"
                @update:model-value="patch({ message: str($event) })"
            />
            <Select
                :model-value="approverType"
                :options="[
                    { value: 'permission', label: t('automation.editor.approval.byPermission') },
                    { value: 'membership', label: t('automation.editor.approval.byMember') },
                ]"
                :label="t('automation.editor.approval.approverType')"
                :disabled="disabled"
                @update:model-value="setApproverType"
            />
            <Select
                v-if="approverType === 'permission'"
                :model-value="str(approver.permission)"
                :options="permissionOptions"
                :label="t('automation.editor.approval.permission')"
                :disabled="disabled"
                @update:model-value="patch({ approver: { type: 'permission', permission: str($event) } })"
            />
            <Select
                v-else
                :model-value="str(approver.membership_id)"
                :options="memberOptions"
                :label="t('automation.editor.approval.member')"
                :disabled="disabled"
                @update:model-value="patch({ approver: { type: 'membership', membership_id: str($event) } })"
            />
            <Checkbox
                :model-value="config.prevent_self_approval !== false"
                :label="t('automation.editor.approval.preventSelf')"
                :disabled="disabled"
                @update:model-value="patch({ prevent_self_approval: $event })"
            />
            <Input
                :model-value="str(config.timeout_hours)"
                type="number"
                min="1"
                :label="t('automation.editor.approval.timeout')"
                :hint="hint.timeout"
                :disabled="disabled"
                @update:model-value="setWhole('timeout_hours', $event)"
            />
            <Select
                :model-value="str(config.on_timeout) || 'reject'"
                :options="[
                    { value: 'approve', label: t('automation.editor.approval.timeoutApprove') },
                    { value: 'reject', label: t('automation.editor.approval.timeoutReject') },
                    { value: 'fail', label: t('automation.editor.approval.timeoutFail') },
                ]"
                :label="t('automation.editor.approval.onTimeout')"
                :disabled="disabled"
                @update:model-value="patch({ on_timeout: str($event) })"
            />
        </template>

        <!-- Webhook -->
        <template v-else-if="type === 'webhook'">
            <Select
                :model-value="str(config.endpoint_id)"
                :options="endpointOptions"
                :label="t('automation.editor.webhook.endpoint')"
                :disabled="disabled"
                @update:model-value="patch({ endpoint_id: str($event) })"
            />
            <p v-if="catalogue.webhookEndpoints.length === 0" class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.webhook.none') }}</p>
            <a
                v-if="canManageWebhooks"
                :href="route('automation.webhooks.index')"
                class="inline-block text-[0.8125rem] text-brand-text underline-offset-2 hover:underline"
            >
                {{ t('automation.editor.webhook.manage') }}
            </a>
            <div>
                <p class="eyebrow mb-1">{{ t('automation.editor.webhook.payload') }}</p>
                <p class="mb-2 text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.webhook.payloadHint', { example: payloadExample }) }}</p>
                <KeyValueEditor :model-value="record(config.payload)" :disabled="disabled" @update:model-value="patch({ payload: $event })" />
            </div>
        </template>

        <!-- Transform -->
        <template v-else-if="type === 'transform'">
            <p class="eyebrow">{{ t('automation.editor.transform.mappings') }}</p>
            <div v-for="(mapping, index) in mappings" :key="index" class="glass-well space-y-2 rounded-card p-3">
                <Input
                    :model-value="str(mapping.target)"
                    :label="t('automation.editor.transform.target')"
                    :hint="t('automation.editor.transform.targetHint')"
                    :disabled="disabled"
                    mono
                    maxlength="64"
                    @update:model-value="updateMapping(index, { target: str($event) })"
                />
                <Select
                    :model-value="str(mapping.operation) || 'copy'"
                    :options="operationOptions"
                    :label="t('automation.editor.transform.operation')"
                    :disabled="disabled"
                    @update:model-value="setOperation(index, $event)"
                />
                <template v-for="field in operationFields(mapping.operation)" :key="field">
                    <VariableInput
                        v-if="field === 'source'"
                        :model-value="str(mapping.source)"
                        :label="t('automation.editor.transform.source')"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        :maxlength="200"
                        @update:model-value="updateMapping(index, { source: $event })"
                    />
                    <VariableInput
                        v-else-if="field === 'value'"
                        :model-value="str(mapping.value)"
                        :label="t('automation.editor.transform.value')"
                        mode="template"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        @update:model-value="updateMapping(index, { value: $event })"
                    />
                    <Textarea
                        v-else-if="field === 'values'"
                        :model-value="lines(mapping.values)"
                        :label="t('automation.editor.transform.values')"
                        :disabled="disabled"
                        :rows="3"
                        @update:model-value="setLines(index, $event)"
                    />
                </template>
                <div v-if="!disabled && mappings.length > 1" class="flex justify-end">
                    <Button size="sm" variant="ghost" type="button" @click="removeMapping(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.editor.transform.removeMapping') }}
                    </Button>
                </div>
            </div>
            <Button v-if="!disabled" size="sm" variant="secondary" type="button" @click="addMapping">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.transform.addMapping') }}
            </Button>
        </template>

        <!-- Loop -->
        <template v-else-if="type === 'loop'">
            <p class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.loop.hint') }}</p>
            <Select
                :model-value="loopMode"
                :options="[
                    { value: 'items', label: t('automation.editor.loop.overItems') },
                    { value: 'count', label: t('automation.editor.loop.overCount') },
                ]"
                :label="t('automation.editor.loop.over')"
                :disabled="disabled"
                @update:model-value="setLoopMode"
            />
            <VariableInput
                v-if="loopMode === 'items'"
                :model-value="str(config.items)"
                :label="t('automation.editor.loop.items')"
                :hint="t('automation.editor.loop.itemsHint')"
                :suggestions="suggestions"
                :disabled="disabled"
                :maxlength="200"
                @update:model-value="patch({ items: $event })"
            />
            <Input
                v-else
                :model-value="str(config.count)"
                type="number"
                min="1"
                :label="t('automation.editor.loop.count')"
                :disabled="disabled"
                @update:model-value="setWhole('count', $event)"
            />
            <Input
                :model-value="str(config.max_iterations)"
                type="number"
                min="1"
                :max="catalogue.limits.maxLoopIterations"
                :label="t('automation.editor.loop.max')"
                :disabled="disabled"
                @update:model-value="setWhole('max_iterations', $event)"
            />
        </template>

        <!-- Error handler -->
        <template v-else-if="type === 'error_handler'">
            <p class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.errorHandler.hint') }}</p>
            <Select
                :model-value="config.then === 'fail' ? 'fail' : 'resume'"
                :options="[
                    { value: 'resume', label: t('automation.editor.errorHandler.resume') },
                    { value: 'fail', label: t('automation.editor.errorHandler.fail') },
                ]"
                :label="t('automation.editor.errorHandler.then')"
                :disabled="disabled"
                @update:model-value="patch({ then: str($event) })"
            />
        </template>

        <!-- End -->
        <template v-else-if="type === 'end'">
            <Select
                :model-value="config.status === 'failed' ? 'failed' : 'success'"
                :options="[
                    { value: 'success', label: t('automation.editor.end.success') },
                    { value: 'failed', label: t('automation.editor.end.failed') },
                ]"
                :label="t('automation.editor.end.status')"
                :disabled="disabled"
                @update:model-value="patch({ status: str($event) })"
            />
            <Input
                :model-value="str(config.message)"
                :label="t('automation.editor.end.message')"
                :disabled="disabled"
                optional
                :maxlength="200"
                @update:model-value="patch({ message: str($event) })"
            />
        </template>

        <!-- Retries for steps that call something -->
        <template v-if="type === 'action' || type === 'webhook'">
            <div class="border-t border-line pt-4">
                <Checkbox
                    :model-value="retry !== null"
                    :label="t('automation.editor.action.retry')"
                    :description="retry === null ? t('automation.editor.action.retryOff') : undefined"
                    :disabled="disabled"
                    @update:model-value="toggleRetry"
                />
                <div v-if="retry" class="mt-3 grid grid-cols-2 gap-2">
                    <Input
                        :model-value="str(retry.max_attempts)"
                        type="number"
                        min="1"
                        :max="catalogue.limits.maxAttempts"
                        :label="t('automation.editor.action.retryAttempts')"
                        :disabled="disabled"
                        @update:model-value="setRetry('max_attempts', $event)"
                    />
                    <Input
                        :model-value="str(retry.backoff_seconds)"
                        type="number"
                        min="1"
                        max="3600"
                        :label="t('automation.editor.action.retryBackoff')"
                        :disabled="disabled"
                        @update:model-value="setRetry('backoff_seconds', $event)"
                    />
                </div>
            </div>
        </template>
    </fieldset>
</template>
