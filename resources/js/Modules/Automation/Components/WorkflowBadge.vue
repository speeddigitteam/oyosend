<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

export type BadgeKind = 'workflow' | 'run' | 'step' | 'approval' | 'version';

const props = defineProps<{ kind: BadgeKind; value: string }>();

const { t, te } = useI18n();

const TONES: Record<BadgeKind, Record<string, BadgeTone>> = {
    workflow: { draft: 'neutral', active: 'ok', paused: 'warn', archived: 'neutral' },
    run: { queued: 'neutral', running: 'info', waiting: 'info', awaiting_approval: 'warn', succeeded: 'ok', failed: 'danger', cancelled: 'neutral' },
    step: { pending: 'neutral', running: 'info', waiting: 'info', succeeded: 'ok', failed: 'danger', skipped: 'neutral', cancelled: 'neutral' },
    approval: { pending: 'warn', approved: 'ok', rejected: 'danger', expired: 'neutral', cancelled: 'neutral' },
    version: { draft: 'neutral', published: 'ok', archived: 'neutral' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind][props.value] ?? 'neutral');
const label = computed(() => {
    const key = `automation.status.${props.kind}.${props.value}`;

    return te(key) ? t(key) : props.value;
});
</script>

<template>
    <Badge :tone="tone" dot>{{ label }}</Badge>
</template>
