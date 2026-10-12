<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import type { Issue } from '../lib/graph';

/**
 * Problems found by the server's validator. The messages are written and
 * translated by the server; they are shown as plain text.
 */
withDefaults(
    defineProps<{
        issues: Issue[];
        /** Resolves a step id to the name shown next to the message. */
        nameOf?: (nodeId: string) => string;
        selectable?: boolean;
    }>(),
    { nameOf: undefined },
);

const emit = defineEmits<{ select: [nodeId: string] }>();
const { t } = useI18n();
</script>

<template>
    <ul class="space-y-2">
        <li v-for="(issue, index) in issues" :key="`${issue.code}-${issue.node_id}-${issue.edge_id}-${index}`" class="flex items-start gap-2 text-[0.8125rem]">
            <Badge :tone="issue.severity === 'error' ? 'danger' : 'warn'" class="shrink-0">{{ t(`automation.status.severity.${issue.severity}`) }}</Badge>
            <span class="min-w-0 text-ink">
                {{ issue.message }}
                <template v-if="issue.node_id !== null">
                    <button
                        v-if="selectable"
                        type="button"
                        class="ml-1 text-brand-text underline-offset-2 hover:underline"
                        @click="emit('select', issue.node_id as string)"
                    >
                        {{ nameOf ? nameOf(issue.node_id) : issue.node_id }}
                    </button>
                    <span v-else class="ml-1 text-ink-subtle">{{ nameOf ? nameOf(issue.node_id) : issue.node_id }}</span>
                </template>
            </span>
        </li>
    </ul>
</template>
