<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { monthGrid, useHrmFormat } from './hrmFormat';
import type { AttendanceDay } from './types';

/**
 * A month as a grid of days, each coloured by its attendance status. Days that
 * have no stored record fall back to what the calendar says: a holiday, a weekly
 * off, approved leave or nothing yet. A selectable day is a button.
 */
const props = defineProps<{
    month: string;
    days: AttendanceDay[];
    today: string;
    holidays?: Record<string, string>;
    weeklyOff?: string[];
    leave?: Record<string, number>;
    /** Dates that can be chosen (and are announced as buttons). */
    selectable?: (date: string) => boolean;
    /** Show worked time in each day. */
    detailed?: boolean;
}>();

const emit = defineEmits<{ select: [date: string] }>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const TONES: Record<string, string> = {
    present: 'bg-ok-soft text-ok',
    half_day: 'bg-info-soft text-info',
    absent: 'bg-danger-soft text-danger',
    on_leave: 'bg-brand-soft text-brand-text',
    holiday: 'bg-hover text-ink-muted',
    weekly_off: 'bg-hover text-ink-muted',
    incomplete: 'bg-warn-soft text-warn',
    none: 'border border-line text-ink-muted',
};

const grid = computed(() => monthGrid(props.month));
const byDate = computed(() => new Map(props.days.map((day) => [day.date, day])));
const off = computed(() => new Set(props.weeklyOff ?? []));
const headers = computed(() => [1, 2, 3, 4, 5, 6, 7].map((day) => ({ day, label: hrm.weekday(day, 'short') })));

interface Cell {
    date: string;
    number: string;
    status: string;
    label: string;
    worked: string | null;
    clickable: boolean;
    isToday: boolean;
}

const cells = computed<Cell[]>(() => grid.value.dates.map((date) => {
    const record = byDate.value.get(date);
    let status = record?.status ?? 'none';
    let label = '';

    if (record === undefined) {
        if (props.holidays?.[date] !== undefined) {
            status = 'holiday';
            label = props.holidays[date] as string;
        } else if (off.value.has(date)) {
            status = 'weekly_off';
        } else if ((props.leave?.[date] ?? 0) > 0) {
            status = 'on_leave';
        }
    } else if (status === 'holiday') {
        label = props.holidays?.[date] ?? '';
    }

    const worked = props.detailed && record !== undefined && record.workedMinutes > 0 ? hrm.duration(record.workedMinutes) : null;

    return {
        date,
        number: format.number(Number(date.slice(8)), { useGrouping: false }),
        status,
        label,
        worked,
        clickable: props.selectable?.(date) === true,
        isToday: date === props.today,
    };
}));

function statusText(cell: Cell): string {
    const base = cell.status === 'none'
        ? (cell.date > props.today ? t('hrm.calendar.upcoming') : t('hrm.calendar.noRecord'))
        : t(`hrm.status.day.${cell.status}`);

    return cell.label ? `${base}: ${cell.label}` : base;
}
</script>

<template>
    <div data-testid="attendance-calendar">
        <div class="grid grid-cols-7 gap-1 text-center" aria-hidden="true">
            <span v-for="header in headers" :key="header.day" class="eyebrow py-1">{{ header.label }}</span>
        </div>
        <ul class="grid grid-cols-7 gap-1" :aria-label="t('hrm.calendar.label')">
            <li v-for="blank in grid.leading" :key="`blank-${blank}`" aria-hidden="true" />
            <li v-for="cell in cells" :key="cell.date" :data-date="cell.date" :data-status="cell.status">
                <component
                    :is="cell.clickable ? 'button' : 'div'"
                    :type="cell.clickable ? 'button' : undefined"
                    :class="[
                        'flex w-full flex-col items-center justify-center rounded-control px-1 py-1.5 text-sm',
                        detailed ? 'min-h-16' : 'min-h-11',
                        TONES[cell.status] ?? TONES.none,
                        cell.isToday ? 'ring-2 ring-brand' : '',
                        cell.clickable ? 'cursor-pointer transition-colors hover:brightness-95 focus-visible:outline-2 focus-visible:outline-brand' : '',
                    ]"
                    :title="statusText(cell)"
                    @click="cell.clickable && emit('select', cell.date)"
                >
                    <span class="tabular font-medium">{{ cell.number }}</span>
                    <span v-if="cell.worked" class="tabular mt-0.5 text-[0.6875rem] leading-tight">{{ cell.worked }}</span>
                    <span class="sr-only">{{ statusText(cell) }}</span>
                </component>
            </li>
        </ul>
        <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted" :aria-label="t('hrm.calendar.legend')">
            <li v-for="status in ['present', 'half_day', 'absent', 'on_leave', 'incomplete', 'holiday']" :key="status" class="flex items-center gap-1.5">
                <span :class="['inline-block size-3 rounded-sm', TONES[status]]" aria-hidden="true" />
                {{ t(`hrm.status.day.${status}`) }}
            </li>
        </ul>
    </div>
</template>
