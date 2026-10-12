<script setup lang="ts">
import { Background } from '@vue-flow/background';
import { VueFlow } from '@vue-flow/core';
import { computed, provide } from 'vue';
import { parseGraph, toFlow } from '../lib/graph';
import { NODE_META_KEY, type NodeMeta } from './nodeMeta';
import type { StepRow } from './types';
import WorkflowNode from './WorkflowNode.vue';
import '@vue-flow/core/dist/style.css';

/**
 * The graph a run executed, read-only, with each step's status as the ring
 * around its node. Nothing can be moved, connected or selected here.
 */
const props = defineProps<{
    graph: unknown;
    steps: StepRow[];
    label: string;
}>();

const flow = computed(() => toFlow(parseGraph(props.graph)));

const meta = computed(() => {
    const map = new Map<string, NodeMeta>();
    const latest = new Map<string, StepRow>();

    for (const step of props.steps) {
        const seen = latest.get(step.nodeId);

        if (!seen || step.sequence >= seen.sequence) {
            latest.set(step.nodeId, step);
        }
    }

    for (const node of flow.value.nodes) {
        map.set(node.id, { summary: '', errors: 0, warnings: 0, status: latest.get(node.id)?.status ?? 'skipped' });
    }

    return map;
});
provide(NODE_META_KEY, meta);
</script>

<template>
    <div class="wf-canvas h-[22rem] w-full" role="img" :aria-label="label">
        <VueFlow
            :nodes="flow.nodes"
            :edges="flow.edges"
            :nodes-draggable="false"
            :nodes-connectable="false"
            :elements-selectable="false"
            :edges-updatable="false"
            :min-zoom="0.25"
            :max-zoom="1.25"
            fit-view-on-init
        >
            <template #node-workflow="nodeProps">
                <WorkflowNode :id="nodeProps.id" :data="nodeProps.data" :selected="false" />
            </template>
            <Background :gap="24" pattern-color="var(--line-strong)" />
        </VueFlow>
    </div>
</template>
