<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { addDays, datePart, daysBetween, minuteOfDay } from '../lib/dates';
import { colorVar, useCalendarFormat } from '../lib/format';
import { hiddenPerColumn, isBanner, lastDate, layoutRow, type Segment } from '../lib/layout';
import { weekdayName } from '../lib/recurrence';
import type { CalendarEvent, EventDraft } from '../types';

/**
 * The month view: five or six weeks, each a row of days with its events in lanes. An all-day or multi-day event is a
 * bar; a timed one a dot with its time. When a day has more than fits, a "+ more" button lists them all. Drag an
 * event to another day to move it there (keeping its times); click an empty day to add an all-day event.
 */
const props = defineProps<{
    weeks: string[][];
    /** `YYYY-MM` of the month in view; other days are dimmed. */
    month: string;
    events: CalendarEvent[];
    today: string;
    canCreate: boolean;
    weekStartsOn: number;
}>();

const emit = defineEmits<{
    open: [event: CalendarEvent, anchor: HTMLElement];
    create: [draft: EventDraft];
    move: [event: CalendarEvent, start: string, end: string, allDay: boolean];
    pickDate: [date: string];
    more: [date: string, anchor: HTMLElement];
}>();

const { t } = useI18n();
const format = useCalendarFormat();

const LANE = 22;
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) => (props.weekStartsOn + i) % 7));
const rows = computed(() => props.weeks.map((dates) => ({ dates, segments: layoutRow(props.events, dates) })));

// How many lanes fit in a row, from its measured height (the date takes the top, the "+ more" line the bottom).
const grid = ref<HTMLElement | null>(null);
const visibleLanes = ref(3);
let observer: ResizeObserver | null = null;

onMounted(() => {
    if (!grid.value || typeof ResizeObserver === 'undefined') {
        return;
    }

    observer = new ResizeObserver(() => {
        const row = grid.value?.querySelector<HTMLElement>('[data-week]');

        if (row) {
            visibleLanes.value = Math.max(1, Math.floor((row.clientHeight - 30 - 20) / LANE));
        }
    });
    observer.observe(grid.value);
});

onBeforeUnmount(() => observer?.disconnect());

function lanesShown(segments: Segment[]): number {
    const total = segments.reduce((max, segment) => Math.max(max, segment.lane + 1), 0);

    // When only one lane would be hidden, show it rather than a "+1 more".
    return total <= visibleLanes.value + 1 ? total : visibleLanes.value;
}

const bar = (event: CalendarEvent): boolean => isBanner(event) || datePart(event.start) !== lastDate(event);
const title = (event: CalendarEvent): string => (event.busy ? t('calendar.grid.busy') : event.title || t('calendar.grid.untitled'));
const outlined = (event: CalendarEvent): boolean => event.myResponse === 'needs_action' || event.myResponse === 'declined';
const startTime = (event: CalendarEvent): string => format.value.time(minuteOfDay(event.start));
const label = (event: CalendarEvent): string => (event.allDay ? `${title(event)}, ${t('calendar.grid.allDay')}` : `${title(event)}, ${startTime(event)}`);

// ---- dragging to another day -----------------------------------------------------------------------------------

const drag = ref<{ event: CalendarEvent; from: string; to: string; moved: boolean; x: number; y: number; target: HTMLElement } | null>(null);

function dateUnder(event: PointerEvent): string | null {
    const element = document.elementFromPoint(event.clientX, event.clientY)?.closest<HTMLElement>('[data-date]');

    return element?.dataset.date ?? null;
}

function startDrag(event: PointerEvent, item: CalendarEvent): void {
    event.stopPropagation();

    if (event.button !== 0) {
        return;
    }

    const from = dateUnder(event) ?? datePart(item.start);
    drag.value = { event: item, from, to: from, moved: false, x: event.clientX, y: event.clientY, target: event.currentTarget as HTMLElement };
}

function onPointerMove(event: PointerEvent): void {
    const current = drag.value;

    if (!current || !current.event.editable || event.pointerType === 'touch') {
        return;
    }

    if (!current.moved && Math.hypot(event.clientX - current.x, event.clientY - current.y) < 5) {
        return;
    }

    current.moved = true;
    current.to = dateUnder(event) ?? current.to;
}

function onPointerUp(): void {
    const current = drag.value;
    drag.value = null;

    if (!current) {
        return;
    }

    const shift = daysBetween(current.from, current.to);

    if (!current.moved || shift === 0) {
        emit('open', current.event, current.target);

        return;
    }

    const event = current.event;
    const move = (value: string): string => addDays(datePart(value), shift) + value.slice(10);
    emit('move', event, move(event.start), move(event.end), event.allDay);
}

function onKey(event: KeyboardEvent, item: CalendarEvent): void {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        emit('open', item, event.currentTarget as HTMLElement);
    }
}

function createOn(date: string): void {
    if (props.canCreate && drag.value === null) {
        emit('create', { start: date, end: date, allDay: true });
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col select-none" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="drag = null">
        <div class="grid grid-cols-7 border-b border-line">
            <div v-for="day in weekdays" :key="day" class="py-2 text-center text-[0.6875rem] font-semibold tracking-wide text-ink-subtle uppercase">
                <abbr :title="weekdayName(day, format)" class="no-underline">{{ weekdayName(day, format, 'short') }}</abbr>
            </div>
        </div>

        <div ref="grid" class="flex min-h-0 flex-1 flex-col">
            <div v-for="row in rows" :key="row.dates[0]" data-week class="relative min-h-20 flex-1 border-b border-line last:border-b-0">
                <!-- Days -->
                <div class="absolute inset-0 grid grid-cols-7">
                    <div
                        v-for="date in row.dates"
                        :key="date"
                        :data-date="date"
                        :class="[
                            'border-l border-line first:border-l-0',
                            drag?.moved && drag.to === date ? 'bg-brand-soft' : '',
                            date.slice(0, 7) !== month ? 'bg-hover' : '',
                        ]"
                        @click.self="createOn(date)"
                    >
                        <div class="flex justify-center pt-1">
                            <button
                                type="button"
                                :aria-current="date === today ? 'date' : undefined"
                                :aria-label="t('calendar.grid.openDay', { date: format.date(date, { weekday: 'long', day: 'numeric', month: 'long' }) })"
                                :class="[
                                    'flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs font-medium transition focus-visible:ring-2 focus-visible:ring-ring',
                                    date === today ? 'bg-brand text-brand-ink' : date.slice(0, 7) === month ? 'text-ink hover:bg-hover' : 'text-ink-subtle hover:bg-hover',
                                ]"
                                @click="emit('pickDate', date)"
                            >
                                {{ date.endsWith('-01') ? format.date(date, { day: 'numeric', month: 'short' }) : format.date(date, { day: 'numeric' }) }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Events -->
                <div class="pointer-events-none absolute inset-x-0 top-8 grid grid-cols-7 gap-y-0.5" :style="{ gridTemplateRows: `repeat(${Math.max(1, lanesShown(row.segments))}, ${LANE - 2}px)` }">
                    <template v-for="segment in row.segments" :key="segment.event.key">
                        <button
                            v-if="segment.lane < lanesShown(row.segments)"
                            type="button"
                            :style="{ ...colorVar(segment.event.color), gridColumn: `${segment.from + 1} / ${segment.to + 2}`, gridRow: `${segment.lane + 1}` }"
                            :class="[
                                'pointer-events-auto mx-1 flex min-w-0 items-center gap-1 rounded-chip px-1.5 text-left text-xs transition focus-visible:ring-2 focus-visible:ring-ring',
                                bar(segment.event)
                                    ? outlined(segment.event) ? 'border border-(--ev) bg-glass-strong font-medium text-ink' : 'bg-(--ev) font-medium text-(--event-ink)'
                                    : 'text-ink hover:bg-hover',
                                segment.event.myResponse === 'declined' ? 'line-through' : '',
                                segment.continuesBefore ? 'ml-0 rounded-l-none' : '',
                                segment.continuesAfter ? 'mr-0 rounded-r-none' : '',
                                drag?.moved && drag.event.key === segment.event.key ? 'opacity-40' : '',
                                segment.event.editable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                            ]"
                            :aria-label="label(segment.event)"
                            @pointerdown="startDrag($event, segment.event)"
                            @keydown="onKey($event, segment.event)"
                        >
                            <span v-if="!bar(segment.event)" :class="['size-2 shrink-0 rounded-full', outlined(segment.event) ? 'border border-(--ev)' : 'bg-(--ev)']" aria-hidden="true" />
                            <span v-if="!bar(segment.event) && !segment.event.allDay" class="hidden shrink-0 text-ink-muted sm:inline">{{ startTime(segment.event) }}</span>
                            <span class="truncate">{{ title(segment.event) }}</span>
                        </button>
                    </template>
                </div>

                <!-- "+ more" -->
                <div class="pointer-events-none absolute inset-x-0 bottom-0.5 grid grid-cols-7">
                    <div v-for="(count, column) in hiddenPerColumn(row.segments, 7, lanesShown(row.segments))" :key="column" class="flex">
                        <button
                            v-if="count > 0"
                            type="button"
                            class="pointer-events-auto mx-1 truncate rounded-chip px-1.5 text-left text-xs font-medium text-ink-muted hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring"
                            @click="emit('more', row.dates[column]!, $event.currentTarget as HTMLElement)"
                        >
                            {{ t('calendar.grid.more', { count: format.number(count) }) }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
