<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

export type BadgeKind = 'campaign' | 'delivery' | 'subscription' | 'sequence' | 'list';

const props = defineProps<{ kind: BadgeKind; value: string }>();

const { t } = useI18n();

const TONES: Record<BadgeKind, Record<string, BadgeTone>> = {
    campaign: {
        draft: 'neutral',
        scheduled: 'brand',
        preparing: 'info',
        sending: 'info',
        awaiting_winner: 'accent',
        paused: 'warn',
        sent: 'ok',
        cancelled: 'neutral',
    },
    delivery: {
        pending: 'neutral',
        held: 'neutral',
        sending: 'info',
        queued: 'info',
        sent: 'ok',
        delivered: 'ok',
        failed: 'danger',
        bounced: 'danger',
        blocked: 'warn',
        skipped: 'neutral',
        cancelled: 'neutral',
    },
    subscription: { pending: 'warn', subscribed: 'ok', unsubscribed: 'neutral', bounced: 'danger', complained: 'danger' },
    sequence: { draft: 'neutral', active: 'ok', paused: 'warn' },
    list: { active: 'ok', archived: 'neutral' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind][props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`emailMarketing.status.${kind}.${value}`) }}</Badge>
</template>
