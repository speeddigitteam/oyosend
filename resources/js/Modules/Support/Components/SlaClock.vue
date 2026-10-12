<script setup lang="ts">
import { AlarmClockOff, CircleCheck, CircleX, Hourglass, Timer, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import { minutesUntil, type ClockState } from './supportFormat';
import { useSupportFormat } from './useSupportFormat';

/**
 * One SLA clock as an icon, a word and (when it helps) the time left or late.
 * Never colour alone: the state is always written out.
 */
const props = defineProps<{
    state: ClockState;
    dueAt: string | null;
    /** Name of the clock, shown in front of the state ("First response"). */
    label?: string;
    now: number;
}>();

const { t } = useI18n();
const { duration } = useSupportFormat();

const TONES: Record<ClockState, BadgeTone> = {
    met: 'ok',
    missed: 'danger',
    breached: 'danger',
    paused: 'info',
    overdue: 'danger',
    due: 'neutral',
    none: 'neutral',
};

const ICONS = {
    met: CircleCheck,
    missed: CircleX,
    breached: TriangleAlert,
    paused: AlarmClockOff,
    overdue: TriangleAlert,
    due: Timer,
    none: Hourglass,
};

const detail = computed(() => {
    if (props.state !== 'due' && props.state !== 'overdue') {
        return '';
    }

    const minutes = minutesUntil(props.dueAt, props.now);

    if (minutes === null) {
        return '';
    }

    return t(props.state === 'overdue' ? 'support.sla.lateBy' : 'support.sla.leftIn', { time: duration(minutes) });
});
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <span v-if="label" class="text-xs text-ink-subtle">{{ label }}</span>
        <Badge :tone="TONES[state]">
            <component :is="ICONS[state]" class="size-3.5" aria-hidden="true" />
            {{ t(`support.sla.state.${state}`) }}
        </Badge>
        <span v-if="detail" class="tabular text-xs text-ink-muted">{{ detail }}</span>
    </span>
</template>
