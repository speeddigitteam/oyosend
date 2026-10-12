<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

export type BadgeKind =
    | 'opening' | 'application' | 'stageType' | 'interview' | 'outcome' | 'offer' | 'checklist' | 'task'
    | 'probation' | 'cycle' | 'review' | 'goal' | 'enrolment' | 'session' | 'recommendation';

const props = defineProps<{ kind: BadgeKind; value: string }>();

const { t } = useI18n();

const TONES: Record<BadgeKind, Record<string, BadgeTone>> = {
    opening: { draft: 'neutral', open: 'ok', on_hold: 'warn', closed: 'neutral' },
    application: { open: 'info', hired: 'ok', rejected: 'danger', withdrawn: 'neutral' },
    stageType: { active: 'info', hired: 'ok', rejected: 'danger' },
    interview: { scheduled: 'info', completed: 'ok', cancelled: 'neutral', no_show: 'danger' },
    outcome: { advance: 'ok', hold: 'warn', reject: 'danger' },
    offer: { draft: 'neutral', sent: 'info', accepted: 'ok', declined: 'danger', withdrawn: 'neutral' },
    checklist: { open: 'info', completed: 'ok', cancelled: 'neutral' },
    task: { pending: 'info', done: 'ok', skipped: 'neutral' },
    probation: { in_progress: 'info', confirmed: 'ok', ended: 'neutral' },
    cycle: { draft: 'neutral', active: 'info', calibration: 'warn', closed: 'neutral' },
    review: { pending_self: 'warn', pending_manager: 'warn', pending_calibration: 'info', calibrated: 'brand', acknowledged: 'ok' },
    goal: { active: 'info', achieved: 'ok', missed: 'danger', cancelled: 'neutral' },
    enrolment: { enrolled: 'info', completed: 'ok', failed: 'danger', cancelled: 'neutral' },
    session: { scheduled: 'info', completed: 'ok', cancelled: 'neutral' },
    recommendation: { strong_no: 'danger', no: 'warn', yes: 'info', strong_yes: 'ok' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind][props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`talent.status.${kind}.${value}`) }}</Badge>
</template>
