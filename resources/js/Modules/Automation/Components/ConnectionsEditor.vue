<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Select from '@/Core/Components/Select.vue';
import { branchCases, handlesFor, toHandleId, type FlowEdge, type FlowNode } from '../lib/graph';

/**
 * Keyboard-friendly way to wire a step: for each output, pick the step it
 * leads to. Dragging on the canvas does the same thing; both go through the
 * same rules so neither can build a structure the server would refuse.
 */
const props = defineProps<{
    node: FlowNode;
    nodes: FlowNode[];
    edges: FlowEdge[];
    disabled: boolean;
}>();

const emit = defineEmits<{
    connect: [payload: { handle: string | null; target: string }];
    disconnect: [edgeId: string];
}>();

const { t } = useI18n();

const handles = computed(() => handlesFor(props.node.data.nodeType, props.node.data.config));

function nodeName(node: FlowNode): string {
    const base = node.data.label !== '' ? node.data.label : t(`automation.nodes.${node.data.nodeType}`);

    return `${base} (${node.id})`;
}

function handleLabel(handle: string | null): string {
    if (handle === null) {
        return t('automation.handles.next');
    }

    if (props.node.data.nodeType === 'branch' && handle !== 'default') {
        const found = branchCases(props.node.data.config).find((item) => item.id === handle);

        return found?.label ? found.label : handle;
    }

    return t(`automation.handles.${handle}`);
}

function edgesFor(handle: string | null): FlowEdge[] {
    return props.edges.filter((edge) => edge.source === props.node.id && edge.sourceHandle === toHandleId(handle));
}

function targetOf(edge: FlowEdge): FlowNode | undefined {
    return props.nodes.find((node) => node.id === edge.target);
}

const targets = computed(() => [
    { value: '', label: t('automation.editor.node.connectTo') },
    ...props.nodes
        .filter((node) => node.id !== props.node.id && node.data.nodeType !== 'trigger')
        .map((node) => ({ value: node.id, label: nodeName(node) })),
]);

function choose(handle: string | null, value: string | number | null | undefined): void {
    if (typeof value === 'string' && value !== '') {
        emit('connect', { handle, target: value });
    }
}
</script>

<template>
    <div class="space-y-4">
        <p v-if="handles.length === 0" class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.node.noOutputs') }}</p>

        <div v-for="handle in handles" :key="handle ?? '__out'" class="space-y-2">
            <p class="text-[0.8125rem] font-medium text-ink">{{ handleLabel(handle) }}</p>

            <ul v-if="edgesFor(handle).length > 0" class="space-y-1">
                <li v-for="edge in edgesFor(handle)" :key="edge.id" class="flex items-center justify-between gap-2 rounded-control bg-hover px-2.5 py-1.5 text-[0.8125rem]">
                    <span class="min-w-0 truncate text-ink">
                        {{ targetOf(edge) ? nodeName(targetOf(edge) as FlowNode) : edge.target }}
                        <span v-if="edge.data.loopBack" class="text-ink-subtle">({{ t('automation.editor.node.loopBack') }})</span>
                    </span>
                    <Button v-if="!disabled" icon size="sm" variant="ghost" type="button" @click="emit('disconnect', edge.id)">
                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.editor.node.removeConnection', { name: targetOf(edge) ? nodeName(targetOf(edge) as FlowNode) : edge.target }) }}
                    </Button>
                </li>
            </ul>
            <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.node.leadsNowhere') }}</p>

            <Select
                v-if="!disabled"
                :model-value="''"
                :options="targets"
                :aria-label="`${handleLabel(handle)}: ${t('automation.editor.node.addConnection')}`"
                @update:model-value="choose(handle, $event)"
            />
        </div>
    </div>
</template>
