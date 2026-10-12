<script setup lang="ts">
import { Background } from '@vue-flow/background';
import { VueFlow, useVueFlow, type Connection } from '@vue-flow/core';
import { Maximize, Minus, Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { connect, type ConnectRefusal, type FlowEdge, type FlowNode } from '../lib/graph';
import WorkflowNode from './WorkflowNode.vue';
import '@vue-flow/core/dist/style.css';

/**
 * The drawing surface. It owns no workflow state: nodes and edges come in and
 * go out through v-model, and connections are validated with the same pure
 * rules the side panel uses (`connect()` in lib/graph).
 */
const props = defineProps<{
    readOnly: boolean;
    label: string;
}>();

const nodes = defineModel<FlowNode[]>('nodes', { required: true });
const edges = defineModel<FlowEdge[]>('edges', { required: true });

const emit = defineEmits<{
    connected: [connection: { source: string; target: string; sourceHandle: string | null | undefined }];
    refused: [reason: ConnectRefusal];
}>();

const { t } = useI18n();
const { fitView, zoomIn, zoomOut, updateNodeInternals } = useVueFlow();

function valid(connection: Connection): boolean {
    return (
        connect(nodes.value, edges.value, {
            source: connection.source,
            target: connection.target,
            sourceHandle: connection.sourceHandle,
        }).edges !== null
    );
}

function onConnect(connection: Connection): void {
    const result = connect(nodes.value, edges.value, {
        source: connection.source,
        target: connection.target,
        sourceHandle: connection.sourceHandle,
    });

    if (result.edges === null) {
        emit('refused', result.refusal as ConnectRefusal);

        return;
    }

    edges.value = result.edges;
    emit('connected', { source: connection.source, target: connection.target, sourceHandle: connection.sourceHandle });
}

defineExpose({
    fitView: () => fitView({ padding: 0.2 }),
    refreshNode: (id: string) => updateNodeInternals([id]),
});
</script>

<template>
    <div class="wf-canvas relative h-full w-full" role="group" :aria-label="label">
        <VueFlow
            v-model:nodes="nodes"
            v-model:edges="edges"
            :nodes-draggable="!props.readOnly"
            :nodes-connectable="!props.readOnly"
            :edges-updatable="false"
            :delete-key-code="props.readOnly ? null : 'Delete'"
            :is-valid-connection="valid"
            :min-zoom="0.25"
            :max-zoom="1.5"
            :snap-to-grid="true"
            :snap-grid="[12, 12]"
            :default-viewport="{ x: 0, y: 0, zoom: 1 }"
            fit-view-on-init
            @connect="onConnect"
        >
            <template #node-workflow="nodeProps">
                <WorkflowNode :id="nodeProps.id" :data="nodeProps.data" :selected="nodeProps.selected" />
            </template>
            <Background :gap="24" pattern-color="var(--line-strong)" />
        </VueFlow>

        <div class="absolute right-3 bottom-3 z-10 flex flex-col gap-1.5">
            <Button icon size="sm" type="button" @click="zoomIn()">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.zoomIn') }}
            </Button>
            <Button icon size="sm" type="button" @click="zoomOut()">
                <template #icon><Minus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.zoomOut') }}
            </Button>
            <Button icon size="sm" type="button" @click="fitView({ padding: 0.2 })">
                <template #icon><Maximize class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.fitView') }}
            </Button>
        </div>
    </div>
</template>

<style>
.wf-canvas .vue-flow__node {
    cursor: default;
}

.wf-canvas .vue-flow__handle {
    width: 12px;
    height: 12px;
    border-radius: 9999px;
    border: 2px solid var(--glass-strong);
    background: var(--brand);
}

.wf-canvas .vue-flow__handle.connectable {
    cursor: crosshair;
}

.wf-canvas .vue-flow__handle:hover,
.wf-canvas .vue-flow__handle.connectingfrom {
    background: var(--brand-hover);
    transform: translate(-50%, 50%) scale(1.25);
}

.wf-canvas .vue-flow__handle-top:hover {
    transform: translate(-50%, -50%) scale(1.25);
}

.wf-canvas .vue-flow__edge-path {
    stroke: var(--ink-subtle);
    stroke-width: 1.75;
}

.wf-canvas .vue-flow__edge.selected .vue-flow__edge-path,
.wf-canvas .vue-flow__edge:focus .vue-flow__edge-path {
    stroke: var(--brand);
}

.wf-canvas .wf-edge-loop .vue-flow__edge-path {
    stroke: var(--info);
    stroke-dasharray: 6 4;
}

.wf-canvas .vue-flow__connection-path {
    stroke: var(--brand);
    stroke-width: 2;
}

.wf-canvas .vue-flow__node:focus-visible {
    outline: 2px solid var(--brand);
    outline-offset: 3px;
    border-radius: var(--radius-card);
}
</style>
