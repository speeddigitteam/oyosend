<script setup lang="ts">
import { ArrowUpRight, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { stateTone, usedPercent } from './aiFormat';
import type { AiStateKey } from './types';

const props = defineProps<{
    data: { limit: number | null; used: number; remaining: number | null; state: AiStateKey };
}>();

const { t, te } = useI18n();
const { can } = useAccess();
const format = useFormat();

const percent = computed(() => usedPercent(props.data));
const stateLabel = computed(() => (te(`ai.state.${props.data.state}.title`) ? t(`ai.state.${props.data.state}.title`) : props.data.state));
</script>

<template>
    <Card :title="t('ai.widget.title')" class="h-full">
        <template v-if="can('ai.usage.view')" #actions>
            <Button size="sm" variant="ghost" :href="route('ai.usage.index')">
                {{ t('ai.widget.view') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>
        <div class="flex items-center gap-2">
            <Sparkles class="size-4 text-ink-subtle" aria-hidden="true" />
            <Badge :tone="stateTone(data.state)" dot>{{ stateLabel }}</Badge>
        </div>
        <p class="eyebrow mt-4">{{ t('ai.widget.used') }}</p>
        <p class="figure mt-2 text-3xl text-ink">{{ format.number(data.used) }}</p>
        <template v-if="data.limit !== null">
            <p class="mt-1 text-[0.8125rem] text-ink-subtle">{{ t('ai.widget.limit', { limit: format.number(data.limit) }) }}</p>
            <div
                v-if="percent !== null"
                class="mt-3 h-1.5 overflow-hidden rounded-full bg-hover"
                role="progressbar"
                :aria-valuenow="percent"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="t('ai.usage.percentUsed', { percent: `${format.number(percent)}%` })"
            >
                <div class="h-full rounded-full bg-brand" :style="{ width: `${percent}%` }" />
            </div>
        </template>
        <p v-else class="mt-1 text-[0.8125rem] text-ink-subtle">{{ t('ai.widget.unlimited') }}</p>
    </Card>
</template>
