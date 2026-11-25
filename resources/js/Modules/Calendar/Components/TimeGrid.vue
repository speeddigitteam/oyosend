<script setup lang="ts">
import { Repeat } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { addDays, addMinutes, datePart, daysBetween, MINUTES_PER_DAY, minuteOfDay, minutesBetween, offsetLabel, snap, wallTime } from '../lib/dates';
import { colorVar, useCalendarFormat } from '../lib/format';
import { isBanner, layoutDay, layoutRow, piecesOn, type PlacedPiece, type Segment } from '../lib/layout';
import type { CalendarEvent, EventDraft } from '../types';

/**
 * Day, 4-day and week views: an all-day strip over a 24-hour grid, one column per date.
 *
 * With a mouse or pen: drag on empty time to draw a new event, drag an event to move it (also across days), drag its
 * lower edge to change its end, drag an all-day bar sideways to move it by days. A click opens. On touch the grid
 * scrolls as usual and a tap opens or creates. Every event is a button, so it is reachable with the keyboard too.
 */
const props = defineProps<{
    dates: string[];
    events: CalendarEvent[];
    today: string;
    /** Wall-clock now in the calendar's zone. */
    now: string;
    timezone: string;
    canCreate: boolean;
    defaultDuration: number;
}>();

const emit = defineEmits<{
    open: [event: CalendarEvent, anchor: HTMLElement];
    create: [draft: EventDraft];
    move: [event: CalendarEvent, start: string, end: string, allDay: boolean];
    pickDate: [date: string];
}>();

const { t } = useI18n();
const format = useCalendarFormat();

const HOUR = 48;
const STEP = 15;
const hours = Array.from({ length: 24 }, (_, hour) => hour);
const px = (minutes: number): number => (minutes / 60) * HOUR;

const banners = computed(() => props.events.filter(isBanner));
const segments = computed(() => layoutRow(banners.value, props.dates));
const lanes = computed(() => segments.value.reduce((max, segment) => Math.max(max, segment.lane + 1), 0));
const columns = computed(() => props.dates.map((date) => ({ date, pieces: layoutDay(piecesOn(props.events, date)) })));
const gridTemplate = computed(() => ({ gridTemplateColumns: `repeat(${props.dates.length}, minmax(0, 1fr))` }));
const offset = computed(() => offsetLabel(props.timezone));
const nowMinute = computed(() => minuteOfDay(props.now));
const nowDate = computed(() => datePart(props.now));

const title = (event: CalendarEvent): string => (event.busy ? t('calendar.grid.busy') : event.title || t('calendar.grid.untitled'));
const timeRange = (event: CalendarEvent): string =>
    t('calendar.grid.timeRange', { start: format.value.time(minuteOfDay(event.start)), end: format.value.time(minuteOfDay(event.end)) });
const outlined = (event: CalendarEvent): boolean => event.myResponse === 'needs_action' || event.myResponse === 'declined';
const label = (event: CalendarEvent): string =>
    event.allDay || isBanner(event)
        ? `${title(event)}, ${t('calendar.grid.allDay')}`
        : `${title(event)}, ${format.value.date(datePart(event.start), { weekday: 'long', day: 'numeric', month: 'long' })}, ${timeRange(event)}`;

// ---- scrolling -------------------------------------------------------------------------------------------------

const scroller = ref<HTMLElement | null>(null);
const body = ref<HTMLElement | null>(null);

function scrollToMorning(): void {
    const minute = props.dates.includes(nowDate.value) ? Math.max(0, nowMinute.value - 90) : 7 * 60;
    scroller.value?.scrollTo({ top: px(minute) });
}

// The grid scrolls under fixed rows; they keep the scrollbar's width free so the columns line up.
const gutter = ref(0);
const measure = (): void => {
    gutter.value = scroller.value ? scroller.value.offsetWidth - scroller.value.clientWidth : 0;
};

onMounted(() => {
    scrollToMorning();
    measure();
    window.addEventListener('resize', measure);
});
onBeforeUnmount(() => window.removeEventListener('resize', measure));
watch(() => props.dates[0], () => nextTick(scrollToMorning));

// ---- pointer interactions --------------------------------------------------------------------------------------

type Drag =
    | { kind: 'create'; date: string; anchor: number; current: number; moved: boolean; touch: boolean }
    | { kind: 'move'; event: CalendarEvent; date: string; start: number; grab: number; duration: number; moved: boolean; x: number; y: number; target: HTMLElement }
    | { kind: 'resize'; event: CalendarEvent; date: string; start: number; end: number; moved: boolean }
    | { kind: 'banner'; event: CalendarEvent; from: number; shift: number; moved: boolean; x: number; target: HTMLElement };

const drag = ref<Drag | null>(null);
const strip = ref<HTMLElement | null>(null);

function pointAt(event: PointerEvent): { date: string; minute: number } | null {
    const rect = body.value?.getBoundingClientRect();

    if (!rect || props.dates.length === 0) {
        return null;
    }

    const column = Math.min(props.dates.length - 1, Math.max(0, Math.floor(((event.clientX - rect.left) / rect.width) * props.dates.length)));
    const minute = Math.min(MINUTES_PER_DAY, Math.max(0, ((event.clientY - rect.top) / HOUR) * 60));

    return { date: props.dates[column]!, minute };
}

function capture(event: PointerEvent): void {
    (event.currentTarget as HTMLElement | null)?.setPointerCapture?.(event.pointerId);
}

function startCreate(event: PointerEvent): void {
    if (!props.canCreate || event.button !== 0) {
        return;
    }

    const point = pointAt(event);

    if (!point) {
        return;
    }

    const anchor = Math.floor(point.minute / STEP) * STEP;
    drag.value = { kind: 'create', date: point.date, anchor, current: anchor, moved: false, touch: event.pointerType === 'touch' };

    if (event.pointerType !== 'touch') {
        capture(event);
    }
}

function startMove(event: PointerEvent, piece: PlacedPiece): void {
    event.stopPropagation();

    if (event.button !== 0) {
        return;
    }

    const target = event.currentTarget as HTMLElement;

    if (!piece.event.editable || event.pointerType === 'touch') {
        drag.value = { kind: 'move', event: piece.event, date: piece.date, start: piece.start, grab: 0, duration: 0, moved: false, x: event.clientX, y: event.clientY, target };

        return;
    }

    const point = pointAt(event);
    const eventStart = minuteOfDay(piece.event.start) + daysBetween(piece.date, datePart(piece.event.start)) * MINUTES_PER_DAY;
    drag.value = {
        kind: 'move',
        event: piece.event,
        date: piece.date,
        start: eventStart,
        grab: (point?.minute ?? piece.start) - eventStart,
        duration: minutesBetween(piece.event.start, piece.event.end),
        moved: false,
        x: event.clientX,
        y: event.clientY,
        target,
    };
    capture(event);
}

function startResize(event: PointerEvent, piece: PlacedPiece): void {
    if (event.button !== 0 || !piece.event.editable || event.pointerType === 'touch') {
        return;
    }

    event.stopPropagation();
    drag.value = { kind: 'resize', event: piece.event, date: datePart(piece.event.start), start: minuteOfDay(piece.event.start), end: minuteOfDay(piece.event.start) + minutesBetween(piece.event.start, piece.event.end), moved: false };
    capture(event);
}

function startBanner(event: PointerEvent, segment: Segment): void {
    if (event.button !== 0) {
        return;
    }

    drag.value = { kind: 'banner', event: segment.event, from: segment.from, shift: 0, moved: false, x: event.clientX, target: event.currentTarget as HTMLElement };

    if (segment.event.editable && event.pointerType !== 'touch') {
        capture(event);
    }
}

function onPointerMove(event: PointerEvent): void {
    const current = drag.value;

    if (!current) {
        return;
    }

    if (current.kind === 'create') {
        if (current.touch) {
            return;
        }

        const point = pointAt(event);

        if (point && point.date === current.date) {
            const minute = snap(point.minute, STEP);
            current.moved ||= minute !== current.anchor;
            current.current = minute;
        }

        return;
    }

    if (current.kind === 'move') {
        if (!current.event.editable || event.pointerType === 'touch') {
            return;
        }

        if (!current.moved && Math.hypot(event.clientX - current.x, event.clientY - current.y) < 4) {
            return;
        }

        const point = pointAt(event);

        if (point) {
            current.moved = true;
            current.date = point.date;
            current.start = Math.min(MINUTES_PER_DAY - STEP, Math.max(-current.duration + STEP, snap(point.minute - current.grab, STEP)));
        }

        return;
    }

    if (current.kind === 'resize') {
        const point = pointAt(event);

        if (point) {
            const dayOffset = daysBetween(current.date, point.date) * MINUTES_PER_DAY;
            current.end = Math.max(current.start + STEP, snap(point.minute + dayOffset, STEP));
            current.moved = true;
        }

        return;
    }

    if (current.kind === 'banner' && current.event.editable && event.pointerType !== 'touch') {
        const width = strip.value?.getBoundingClientRect().width ?? 0;

        if (width > 0) {
            const shift = Math.round(((event.clientX - current.x) / width) * props.dates.length);
            current.moved ||= Math.abs(event.clientX - current.x) > 4;
            current.shift = shift;
        }
    }
}

function onPointerUp(): void {
    const current = drag.value;
    drag.value = null;

    if (!current) {
        return;
    }

    if (current.kind === 'create') {
        if (!current.moved) {
            emit('create', { start: wallTime(current.date, current.anchor), end: wallTime(current.date, current.anchor + props.defaultDuration), allDay: false });

            return;
        }

        const [from, to] = [Math.min(current.anchor, current.current), Math.max(current.anchor, current.current)];
        emit('create', { start: wallTime(current.date, from), end: wallTime(current.date, Math.max(to, from + STEP)), allDay: false });

        return;
    }

    if (current.kind === 'move') {
        if (!current.moved) {
            emit('open', current.event, current.target);

            return;
        }

        const start = wallTime(current.date, current.start);

        if (start !== current.event.start) {
            emit('move', current.event, start, addMinutes(start, current.duration), false);
        }

        return;
    }

    if (current.kind === 'resize') {
        const end = wallTime(current.date, current.end);

        if (current.moved && end !== current.event.end) {
            emit('move', current.event, current.event.start, end, false);
        }

        return;
    }

    if (!current.moved || current.shift === 0) {
        emit('open', current.event, current.target);

        return;
    }

    const event = current.event;
    emit('move', event, shiftDate(event.start, current.shift), shiftDate(event.end, current.shift), event.allDay);
}

function onPointerCancel(): void {
    drag.value = null;
}

const shiftDate = (value: string, days: number): string => addDays(datePart(value), days) + value.slice(10);

function onEventKey(event: KeyboardEvent, item: CalendarEvent): void {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        emit('open', item, event.currentTarget as HTMLElement);
    }
}

function createAllDay(date: string): void {
    if (props.canCreate) {
        emit('create', { start: date, end: date, allDay: true });
    }
}

// ---- what a drag draws -----------------------------------------------------------------------------------------

const ghost = computed(() => {
    const current = drag.value;

    if (!current) {
        return null;
    }

    if (current.kind === 'create' && !current.touch) {
        const [from, to] = [Math.min(current.anchor, current.current), Math.max(current.anchor, current.current, current.anchor + STEP)];

        return { date: current.date, start: from, end: Math.max(to, from + STEP), color: null as CalendarEvent['color'] | null, text: `${format.value.time(from)} – ${format.value.time(Math.max(to, from + STEP) % MINUTES_PER_DAY)}` };
    }

    if (current.kind === 'move' && current.moved) {
        return { date: current.date, start: current.start, end: current.start + current.duration, color: current.event.color, text: title(current.event) };
    }

    if (current.kind === 'resize' && current.moved) {
        return { date: current.date, start: current.start, end: current.end, color: current.event.color, text: title(current.event) };
    }

    return null;
});

const hidden = computed(() => {
    const current = drag.value;

    return current && (current.kind === 'move' || current.kind === 'resize') && current.moved ? current.event.key : null;
});

function pieceStyle(piece: PlacedPiece): Record<string, string> {
    const width = 100 / piece.columns;
    // Like a paper diary, later columns overlap the earlier ones a little; the last one keeps a gap for clicking.
    const spread = piece.column === piece.columns - 1 ? width : Math.min(width * 1.6, 100 - width * piece.column);

    return {
        ...colorVar(piece.event.color),
        top: `${px(piece.start)}px`,
        height: `${Math.max(px(piece.end - piece.start), 20) - 2}px`,
        left: `${width * piece.column}%`,
        width: `calc(${spread}% - 4px)`,
        zIndex: String(10 + piece.column),
    };
}

function ghostStyle(start: number, end: number, color: CalendarEvent['color'] | null, column: number): Record<string, string> {
    const top = Math.max(0, start);
    const bottom = Math.min(MINUTES_PER_DAY, end);

    return {
        ...colorVar(color ?? 'blue'),
        top: `${px(top)}px`,
        height: `${Math.max(px(bottom - top), 18)}px`,
        left: `${(100 / props.dates.length) * column}%`,
        width: `calc(${100 / props.dates.length}% - 6px)`,
    };
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerCancel">
        <!-- Dates -->
        <div class="flex border-b border-line" :style="{ paddingRight: `${gutter}px` }">
            <div class="w-14 shrink-0 sm:w-16" />
            <div class="grid flex-1" :style="gridTemplate">
                <div v-for="date in dates" :key="date" class="flex flex-col items-center gap-0.5 py-2">
                    <span :class="['text-[0.6875rem] font-semibold tracking-wide uppercase', date === today ? 'text-brand-text' : 'text-ink-subtle']">
                        {{ format.date(date, { weekday: 'short' }) }}
                    </span>
                    <button
                        type="button"
                        :aria-current="date === today ? 'date' : undefined"
                        :aria-label="t('calendar.grid.openDay', { date: format.date(date, { weekday: 'long', day: 'numeric', month: 'long' }) })"
                        :class="[
                            'flex size-10 items-center justify-center rounded-full font-display text-xl transition focus-visible:ring-2 focus-visible:ring-ring sm:size-11 sm:text-2xl',
                            date === today ? 'bg-brand text-brand-ink' : 'text-ink hover:bg-hover',
                        ]"
                        @click="emit('pickDate', date)"
                    >
                        {{ format.date(date, { day: 'numeric' }) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- All-day strip -->
        <div class="flex border-b border-line" :style="{ paddingRight: `${gutter}px` }">
            <div class="flex w-14 shrink-0 items-end justify-end pr-2 pb-1 text-[0.625rem] text-ink-subtle sm:w-16">{{ offset }}</div>
            <div ref="strip" class="relative max-h-28 flex-1 overflow-y-auto">
                <div class="absolute inset-0 grid" :style="gridTemplate" aria-hidden="true">
                    <div v-for="date in dates" :key="date" class="border-l border-line" @click="createAllDay(date)" />
                </div>
                <div class="pointer-events-none relative grid gap-y-0.5 py-0.5" :style="{ ...gridTemplate, gridTemplateRows: `repeat(${Math.max(1, lanes)}, 1.375rem)` }">
                    <button
                        v-for="segment in segments"
                        :key="segment.event.key"
                        type="button"
                        :style="{
                            ...colorVar(segment.event.color),
                            gridColumn: `${segment.from + 1} / ${segment.to + 2}`,
                            gridRow: `${segment.lane + 1}`,
                            transform: drag?.kind === 'banner' && drag.event.key === segment.event.key && drag.moved ? `translateX(calc(${drag.shift} * 100% / ${segment.to - segment.from + 1}))` : undefined,
                        }"
                        :class="[
                            'pointer-events-auto mx-0.5 flex min-w-0 items-center gap-1 truncate rounded-chip px-2 text-left text-xs font-medium transition-shadow focus-visible:ring-2 focus-visible:ring-ring',
                            outlined(segment.event) ? 'border border-(--ev) bg-glass-strong text-ink' : 'bg-(--ev) text-(--event-ink)',
                            segment.event.myResponse === 'declined' ? 'line-through' : '',
                            segment.continuesBefore ? 'rounded-l-none' : '',
                            segment.continuesAfter ? 'rounded-r-none' : '',
                            segment.event.editable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                        ]"
                        :aria-label="label(segment.event)"
                        @pointerdown="startBanner($event, segment)"
                        @keydown="onEventKey($event, segment.event)"
                    >
                        <span class="truncate">{{ title(segment.event) }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Time grid -->
        <div ref="scroller" class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <div class="flex" :style="{ height: `${px(MINUTES_PER_DAY)}px` }">
                <div class="relative w-14 shrink-0 sm:w-16" aria-hidden="true">
                    <span v-for="hour in hours.slice(1)" :key="hour" class="absolute right-2 -translate-y-1/2 text-[0.6875rem] whitespace-nowrap text-ink-subtle" :style="{ top: `${px(hour * 60)}px` }">
                        {{ format.hour(hour) }}
                    </span>
                </div>

                <div ref="body" class="relative flex-1 touch-pan-y" @pointerdown="startCreate">
                    <!-- Hour and half-hour lines, and the day columns -->
                    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                        <div v-for="hour in hours" :key="hour" class="absolute inset-x-0 border-t border-line" :style="{ top: `${px(hour * 60)}px` }" />
                    </div>
                    <div class="absolute inset-0 grid" :style="gridTemplate">
                        <div v-for="column in columns" :key="column.date" class="relative border-l border-line" :class="column.date === today ? 'bg-brand-soft/30' : ''">
                            <!-- Events -->
                            <button
                                v-for="piece in column.pieces"
                                :key="`${piece.event.key}-${piece.date}`"
                                type="button"
                                :style="pieceStyle(piece)"
                                :class="[
                                    'group absolute flex flex-col overflow-hidden rounded-chip px-1.5 py-0.5 text-left text-xs leading-tight shadow-sm ring-1 ring-(--canvas) transition-opacity focus-visible:z-50 focus-visible:ring-2 focus-visible:ring-ring',
                                    outlined(piece.event) ? 'border border-(--ev) bg-glass-strong text-ink' : 'bg-(--ev) text-(--event-ink)',
                                    piece.event.myResponse === 'declined' ? 'line-through' : '',
                                    hidden === piece.event.key ? 'opacity-40' : '',
                                    piece.event.editable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                                    piece.continuesBefore ? 'rounded-t-none' : '',
                                    piece.continuesAfter ? 'rounded-b-none' : '',
                                ]"
                                :aria-label="label(piece.event)"
                                @pointerdown="startMove($event, piece)"
                                @keydown="onEventKey($event, piece.event)"
                            >
                                <span class="flex items-center gap-1 font-medium">
                                    <span class="truncate">{{ title(piece.event) }}</span>
                                    <Repeat v-if="piece.event.recurring" class="size-3 shrink-0 opacity-80" aria-hidden="true" />
                                </span>
                                <span v-if="piece.end - piece.start >= 45" class="truncate opacity-90">{{ timeRange(piece.event) }}</span>
                                <span v-if="piece.end - piece.start >= 75 && piece.event.location" class="truncate opacity-90">{{ piece.event.location }}</span>
                                <span
                                    v-if="piece.event.editable && !piece.continuesAfter"
                                    class="absolute inset-x-0 bottom-0 h-2 cursor-ns-resize"
                                    aria-hidden="true"
                                    @pointerdown="startResize($event, piece)"
                                />
                            </button>

                            <!-- Now -->
                            <div v-if="column.date === nowDate" class="pointer-events-none absolute inset-x-0 z-40 border-t-2 border-danger" :style="{ top: `${px(nowMinute)}px` }">
                                <span class="absolute -top-[7px] -left-[6px] size-3 rounded-full bg-danger" />
                                <span class="sr-only">{{ t('calendar.grid.now') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- What a drag draws -->
                    <div
                        v-if="ghost"
                        class="pointer-events-none absolute z-50 flex flex-col rounded-chip border-2 border-(--ev) bg-glass-strong px-1.5 py-0.5 text-xs font-medium text-ink shadow-float"
                        :style="ghostStyle(ghost.start, ghost.end, ghost.color, dates.indexOf(ghost.date))"
                        aria-hidden="true"
                    >
                        <span class="truncate">{{ ghost.text }}</span>
                        <span v-if="ghost.color" class="truncate text-ink-muted">{{ format.time(((Math.max(0, ghost.start) % MINUTES_PER_DAY) + MINUTES_PER_DAY) % MINUTES_PER_DAY) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
