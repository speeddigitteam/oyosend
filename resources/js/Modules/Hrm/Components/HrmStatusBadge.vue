<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

type Kind = 'employee' | 'day' | 'leave' | 'correction' | 'holiday';

const props = defineProps<{ kind: Kind; value: string }>();

const { t } = useI18n();

const TONES: Record<Kind, Record<string, BadgeTone>> = {
    employee: { probation: 'info', active: 'ok', on_leave: 'brand', suspended: 'warn', terminated: 'neutral' },
    day: { present: 'ok', half_day: 'info', absent: 'danger', on_leave: 'brand', holiday: 'neutral', weekly_off: 'neutral', incomplete: 'warn', not_recorded: 'neutral' },
    leave: { pending_manager: 'warn', pending_hr: 'warn', approved: 'ok', rejected: 'danger', cancelled: 'neutral' },
    correction: { pending: 'warn', approved: 'ok', rejected: 'danger', cancelled: 'neutral' },
    holiday: { public: 'brand', optional: 'neutral' },
};

const tone = computed<BadgeTone>(() => TONES[props.kind][props.value] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`hrm.status.${kind}.${value}`) }}</Badge>
</template>
