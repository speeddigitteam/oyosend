<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

export type BadgeKind = 'post' | 'target' | 'account' | 'attempt' | 'campaign';

const props = defineProps<{ kind: BadgeKind; value: string }>();

const { t } = useI18n();

const TONES: Record<BadgeKind, Record<string, BadgeTone>> = {
    post: {
        draft: 'neutral',
        pending_approval: 'warn',
        approved: 'info',
        scheduled: 'brand',
        publishing: 'info',
        published: 'ok',
        partially_failed: 'warn',
        failed: 'danger',
    },
    target: { pending: 'info', publishing: 'info', published: 'ok', failed: 'danger' },
    account: { connected: 'ok', needs_reauthorization: 'warn', disconnected: 'neutral' },
    attempt: { success: 'ok', retry: 'warn', failed: 'danger', deferred: 'neutral' },
    campaign: { active: 'ok', archived: 'neutral' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind][props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`social.status.${kind}.${value}`) }}</Badge>
</template>
