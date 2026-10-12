<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

export type BadgeKind = 'campaign' | 'recipient' | 'registration' | 'purpose';

const props = defineProps<{ kind: BadgeKind; value: string }>();

const { t } = useI18n();

const TONES: Record<BadgeKind, Record<string, BadgeTone>> = {
    campaign: { draft: 'neutral', scheduled: 'brand', sending: 'info', completed: 'ok', cancelled: 'neutral' },
    recipient: { pending: 'neutral', queued: 'info', sent: 'brand', delivered: 'ok', failed: 'danger', blocked: 'warn', skipped: 'neutral' },
    // Registration is the workspace's own record; the tone never says "verified".
    registration: { not_registered: 'neutral', pending: 'warn', registered: 'info', rejected: 'danger' },
    purpose: { marketing: 'brand', transactional: 'accent' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind][props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`smsMarketing.status.${kind}.${value}`) }}</Badge>
</template>
