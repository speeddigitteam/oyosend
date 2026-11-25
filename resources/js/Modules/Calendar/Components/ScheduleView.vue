<script setup lang="ts">
import { CalendarCheck, MapPin, Repeat } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { datesIn, minuteOfDay, type DateRange } from '../lib/dates';
import { colorVar, useCalendarFormat } from '../lib/format';
import { eventsOn, isBanner } from '../lib/layout';
import type { CalendarEvent } from '../types';

/** The schedule (agenda) view: every day in the range that has something, with its events in order. */
const props = defineProps<{
    range: DateRange;
    events: CalendarEvent[];
    today: string;
}>();

const emit = defineEmits<{
    open: [event: CalendarEvent, anchor: HTMLElement];
    pickDate: [date: string];
}>();

const { t } = useI18n();
const format = useCalendarFormat();

const days = computed(() =>
    datesIn(props.range)
        .map((date) => ({ date, events: eventsOn(props.events, date) }))
        .filter((day) => day.events.length > 0),
);

const title = (event: CalendarEvent): string => (event.busy ? t('calendar.grid.busy') : event.title || t('calendar.grid.untitled'));

function when(event: CalendarEvent, date: string): string {
    if (isBanner(event)) {
        return t('calendar.grid.allDay');
    }

    const starts = event.start.slice(0, 10) === date ? minuteOfDay(event.start) : 0;
    const endsToday = event.end.slice(0, 10) === date;

    return t('calendar.grid.timeRange', { start: format.value.time(starts), end: endsToday ? format.value.time(minuteOfDay(event.end)) : format.value.time(0) });
}
</script>

<template>
    <div class="min-h-0 flex-1 overflow-y-auto">
        <div v-if="days.length === 0" class="mx-auto mt-16 max-w-sm px-4 text-center">
            <CalendarCheck class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
            <p class="mt-3 text-sm text-ink-muted">{{ t('calendar.schedule.empty') }}</p>
        </div>

        <ol v-else class="divide-y divide-line">
            <li v-for="day in days" :key="day.date" class="flex gap-3 px-3 py-2.5 sm:gap-6 sm:px-5">
                <button
                    type="button"
                    class="flex w-24 shrink-0 items-start gap-2 rounded-control text-left focus-visible:ring-2 focus-visible:ring-ring sm:w-32"
                    :aria-label="t('calendar.grid.openDay', { date: format.date(day.date, { weekday: 'long', day: 'numeric', month: 'long' }) })"
                    @click="emit('pickDate', day.date)"
                >
                    <span :class="['flex size-9 shrink-0 items-center justify-center rounded-full font-display text-lg', day.date === today ? 'bg-brand text-brand-ink' : 'text-ink']">
                        {{ format.date(day.date, { day: 'numeric' }) }}
                    </span>
                    <span :class="['pt-2 text-xs font-medium uppercase', day.date === today ? 'text-brand-text' : 'text-ink-subtle']">
                        {{ format.date(day.date, { month: 'short', weekday: 'short' }) }}
                    </span>
                </button>

                <ul class="min-w-0 flex-1 space-y-0.5 pt-1">
                    <li v-for="event in day.events" :key="event.key">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-control px-2 py-1.5 text-left text-sm transition hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring"
                            :style="colorVar(event.color)"
                            @click="emit('open', event, $event.currentTarget as HTMLElement)"
                        >
                            <span :class="['size-2.5 shrink-0 rounded-full', event.myResponse === 'needs_action' ? 'border-2 border-(--ev)' : 'bg-(--ev)']" aria-hidden="true" />
                            <span class="w-28 shrink-0 text-ink-muted sm:w-36">{{ when(event, day.date) }}</span>
                            <span :class="['min-w-0 flex-1 truncate font-medium text-ink', event.myResponse === 'declined' ? 'line-through' : '']">{{ title(event) }}</span>
                            <Repeat v-if="event.recurring" class="size-3.5 shrink-0 text-ink-subtle" :aria-label="t('calendar.event.repeats')" />
                            <span v-if="event.location" class="hidden max-w-48 items-center gap-1 truncate text-xs text-ink-subtle md:flex">
                                <MapPin class="size-3.5 shrink-0" aria-hidden="true" />
                                <span class="truncate">{{ event.location }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </li>
        </ol>
    </div>
</template>
