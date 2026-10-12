<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core';
import {
    BadgeCheck,
    CalendarClock,
    Columns3,
    Flag,
    GitBranch,
    GitFork,
    Play,
    Repeat,
    ShieldAlert,
    Shuffle,
    Timer,
    TriangleAlert,
    Webhook,
    Zap,
} from 'lucide-vue-next';
import { computed, inject, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { branchCases, handlesFor, toHandleId, type FlowNodeData, type NodeType } from '../lib/graph';
import { NODE_META_KEY } from './nodeMeta';

/**
 * One step on the canvas: its kind, the label the person gave it, a one line
 * summary, how many problems it has, and its named outputs. Connection points
 * are Vue Flow handles; the same connections can be made from the side panel.
 */
const props = defineProps<{ id: string; data: FlowNodeData; selected?: boolean }>();

const { t } = useI18n();
const meta = inject(NODE_META_KEY, ref(new Map()));

const ICONS: Record<NodeType, Component> = {
    trigger: Zap,
    condition: GitBranch,
    branch: GitFork,
    action: Play,
    delay: Timer,
    schedule: CalendarClock,
    parallel: Columns3,
    approval: BadgeCheck,
    webhook: Webhook,
    transform: Shuffle,
    loop: Repeat,
    error_handler: ShieldAlert,
    end: Flag,
};

const info = computed(() => meta.value.get(props.id));
const title = computed(() => (props.data.label !== '' ? props.data.label : t(`automation.nodes.${props.data.nodeType}`)));
const handles = computed(() => handlesFor(props.data.nodeType, props.data.config));
const labelled = computed(() => handles.value.length > 1 || (handles.value.length === 1 && handles.value[0] !== null));

function handleLabel(handle: string | null): string {
    if (handle === null) {
        return t('automation.handles.next');
    }

    if (props.data.nodeType === 'branch' && handle !== 'default') {
        const found = branchCases(props.data.config).find((item) => item.id === handle);

        return found?.label !== undefined && found.label !== '' ? found.label : handle;
    }

    return t(`automation.handles.${handle}`);
}

function left(index: number): string {
    return `${((index + 1) / (handles.value.length + 1)) * 100}%`;
}

const STATUS_RING: Record<string, string> = {
    succeeded: 'border-ok',
    failed: 'border-danger',
    running: 'border-info',
    waiting: 'border-info',
    pending: 'border-line-strong',
    skipped: 'border-line',
    cancelled: 'border-line',
};

const ring = computed(() => {
    if (info.value?.status) {
        return STATUS_RING[info.value.status] ?? 'border-line-strong';
    }

    if (props.selected) {
        return 'border-brand';
    }

    return (info.value?.errors ?? 0) > 0 ? 'border-danger' : 'border-line-strong';
});
</script>

<template>
    <div
        :class="['rounded-card border-2 bg-glass-strong text-ink shadow-glass', ring]"
        :style="{ minWidth: `${Math.max(200, handles.length * 96)}px` }"
        :data-node-type="data.nodeType"
    >
        <Handle v-if="data.nodeType !== 'trigger'" type="target" :position="Position.Top" />

        <div class="flex items-start gap-2 px-3 pt-2.5" :class="labelled ? 'pb-1' : 'pb-2.5'">
            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-chip bg-brand-soft text-brand-text">
                <component :is="ICONS[data.nodeType]" class="size-4" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="eyebrow block">{{ t(`automation.nodes.${data.nodeType}`) }}</span>
                <span class="block truncate text-[0.8125rem] font-medium">{{ title }}</span>
                <span v-if="info?.summary" class="block truncate text-xs text-ink-muted">{{ info.summary }}</span>
            </span>
            <span
                v-if="info && info.errors + info.warnings > 0"
                :class="['flex shrink-0 items-center gap-1 rounded-chip px-1.5 py-0.5 text-xs font-medium', info.errors > 0 ? 'bg-danger-soft text-danger' : 'bg-warn-soft text-warn']"
            >
                <TriangleAlert class="size-3" aria-hidden="true" />
                <span class="tabular">{{ info.errors + info.warnings }}</span>
                <span class="sr-only">{{ t('automation.editor.node.problems') }}</span>
            </span>
        </div>

        <div v-if="labelled" class="relative h-6" aria-hidden="true">
            <span
                v-for="(handle, index) in handles"
                :key="handle ?? '__out'"
                class="absolute top-0.5 max-w-[88px] -translate-x-1/2 truncate text-[0.6875rem] leading-4 text-ink-subtle"
                :style="{ left: left(index) }"
            >
                {{ handleLabel(handle) }}
            </span>
        </div>

        <Handle
            v-for="(handle, index) in handles"
            :id="toHandleId(handle)"
            :key="toHandleId(handle)"
            type="source"
            :position="Position.Bottom"
            :style="{ left: left(index) }"
        />
    </div>
</template>
