<script setup lang="ts">
import { CircleAlert, CircleCheck, CircleDashed, Lock } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import type { ReadinessItem, ReadinessState } from './types';

defineProps<{ items: ReadinessItem[] }>();

const { t } = useI18n();

const TONES: Record<ReadinessState, BadgeTone> = {
    done: 'ok',
    todo: 'brand',
    attention: 'warn',
    blocked: 'neutral',
    optional: 'info',
    unavailable: 'neutral',
};
</script>

<template>
    <ol class="grid gap-2" data-testid="readiness">
        <li
            v-for="(item, index) in items"
            :key="item.key"
            class="flex items-start gap-3 rounded-card border border-line bg-hover/40 px-4 py-3"
            :data-state="item.state"
        >
            <span class="mt-0.5 shrink-0" :class="item.state === 'done' ? 'text-ok' : item.state === 'attention' ? 'text-warn' : 'text-ink-subtle'" aria-hidden="true">
                <CircleCheck v-if="item.state === 'done'" class="size-5" />
                <CircleAlert v-else-if="item.state === 'attention'" class="size-5" />
                <Lock v-else-if="item.state === 'blocked'" class="size-5" />
                <CircleDashed v-else class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-ink">
                    <span class="text-ink-subtle">{{ index + 1 }}.</span> {{ t(`metaAds.readiness.${item.key}.title`) }}
                </p>
                <p class="mt-0.5 text-[0.8125rem] text-ink-muted">{{ t(`metaAds.readiness.${item.key}.${item.state}`) }}</p>
            </div>
            <Badge :tone="TONES[item.state]">{{ t(`metaAds.readiness.state.${item.state}`) }}</Badge>
        </li>
    </ol>
</template>
