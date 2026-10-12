<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { AgentOption, FlowSimpleStep } from './types';
import { FLOW_STEP_TYPES, newStep } from './whatsappFormat';

/**
 * An ordered list of flow steps from the closed vocabulary: reply, tag, assign, hand off. Steps are plain data;
 * there is nothing to type that the server would evaluate.
 */
const props = defineProps<{
    max: number;
    agents: AgentOption[];
    label: string;
    errors?: Record<string, string | undefined>;
    errorPrefix?: string;
}>();

const steps = defineModel<FlowSimpleStep[]>({ required: true });

const { t } = useI18n();
const format = useFormat();

const typeOptions = computed(() => FLOW_STEP_TYPES.map((value) => ({ value, label: t(`whatsapp.flows.step.${value}`) })));
const agentOptions = computed(() => [
    { value: '', label: t('whatsapp.flows.nextAgent') },
    ...props.agents.map((agent) => ({ value: agent.id, label: agent.name })),
]);

function setType(index: number, type: string | number | null | undefined): void {
    const next = [...steps.value];
    next[index] = newStep(type as (typeof FLOW_STEP_TYPES)[number]);
    steps.value = next;
}

function patch(index: number, change: Record<string, unknown>): void {
    const next = [...steps.value];
    next[index] = { ...next[index], ...change } as FlowSimpleStep;
    steps.value = next;
}

function move(index: number, by: -1 | 1): void {
    const target = index + by;

    if (target < 0 || target >= steps.value.length) {
        return;
    }

    const next = [...steps.value];
    [next[index], next[target]] = [next[target]!, next[index]!];
    steps.value = next;
}

function remove(index: number): void {
    steps.value = steps.value.filter((_, position) => position !== index);
}

function add(): void {
    if (steps.value.length < props.max) {
        steps.value = [...steps.value, newStep('reply')];
    }
}

function error(index: number, field: string): string | undefined {
    const prefix = props.errorPrefix ?? 'actions';

    return props.errors?.[`${prefix}.${index}.${field}`];
}
</script>

<template>
    <div class="grid gap-3" role="group" :aria-label="label">
        <ol class="grid gap-3">
            <li v-for="(step, index) in steps" :key="index" class="grid gap-2 rounded-control border border-line p-3" data-testid="flow-step">
                <div class="flex flex-wrap items-end gap-2">
                    <Select
                        class="min-w-40 flex-1"
                        :model-value="step.type"
                        :options="typeOptions"
                        :label="t('whatsapp.flows.stepNumber', { number: format.number(index + 1) })"
                        @update:model-value="setType(index, $event)"
                    />
                    <div class="flex gap-1">
                        <Button size="sm" variant="ghost" icon :disabled="index === 0" @click="move(index, -1)">
                            <template #icon><ArrowUp class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.flows.moveUp') }}
                        </Button>
                        <Button size="sm" variant="ghost" icon :disabled="index === steps.length - 1" @click="move(index, 1)">
                            <template #icon><ArrowDown class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.flows.moveDown') }}
                        </Button>
                        <Button size="sm" variant="ghost" icon @click="remove(index)">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.flows.removeStep') }}
                        </Button>
                    </div>
                </div>

                <Textarea
                    v-if="step.type === 'reply'"
                    :model-value="step.text"
                    :label="t('whatsapp.flows.replyText')"
                    :hint="t('whatsapp.flows.replyHint')"
                    :error="error(index, 'text')"
                    :rows="3"
                    maxlength="1000"
                    @update:model-value="patch(index, { text: $event ?? '' })"
                />
                <Input
                    v-else-if="step.type === 'tag'"
                    :model-value="step.tag"
                    :label="t('whatsapp.flows.tag')"
                    :hint="t('whatsapp.flows.tagHint')"
                    :error="error(index, 'tag')"
                    maxlength="30"
                    @update:model-value="patch(index, { tag: String($event ?? '') })"
                />
                <Select
                    v-else-if="step.type === 'assign'"
                    :model-value="step.assignee_id ?? ''"
                    :options="agentOptions"
                    :label="t('whatsapp.flows.assignTo')"
                    :error="error(index, 'assignee_id')"
                    @update:model-value="patch(index, { assignee_id: $event === '' ? null : String($event) })"
                />
                <p v-else class="text-sm text-ink-muted">{{ t('whatsapp.flows.handoffHint') }}</p>
            </li>
        </ol>
        <div v-if="steps.length < max">
            <Button size="sm" variant="secondary" @click="add">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.flows.addStep') }}
            </Button>
        </div>
        <p v-else class="text-xs text-ink-subtle">{{ t('whatsapp.flows.stepLimit', { max: format.number(max) }) }}</p>
    </div>
</template>
