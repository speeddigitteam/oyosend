<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { addDays, addMonths, monthMatrix, startOfMonth, type DateRange } from '../lib/dates';
import { useCalendarFormat } from '../lib/format';
import { weekdayName } from '../lib/recurrence';

/**
 * A small month to pick a date from: the sidebar's navigator and each month of the year view. Arrow keys move the
 * focused day (a roving tab stop), Enter picks it, and Page Up / Page Down change the month.
 */
const props = withDefaults(
    defineProps<{
        /** Any date in the month shown first. */
        month: string;
        today: string;
        weekStartsOn: number;
        /** The dates the main view shows, drawn as a band. */
        selected?: DateRange | null;
        /** Let the month be changed from its own header (the sidebar's); the year view's months stay put. */
        navigable?: boolean;
        /** Dates that have events, marked with a dot. */
        marked?: Set<string>;
    }>(),
    { selected: null, navigable: true, marked: undefined },
);

const emit = defineEmits<{ pick: [date: string] }>();

const { t } = useI18n();
const format = useCalendarFormat();

const shown = ref(startOfMonth(props.month));
watch(() => props.month, (month) => {
    shown.value = startOfMonth(month);
});

const weeks = computed(() => monthMatrix(shown.value, props.weekStartsOn));
const heading = computed(() => format.value.date(shown.value, { month: 'long', year: 'numeric' }));
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) => (props.weekStartsOn + i) % 7));

// The focusable day: the selected one when it is in this month, else the 1st.
const focusDate = ref<string>(props.selected && props.selected.start.slice(0, 7) === shown.value.slice(0, 7) ? props.selected.start : shown.value);
watch(shown, (month) => {
    if (focusDate.value.slice(0, 7) !== month.slice(0, 7)) {
        focusDate.value = month;
    }
});

const grid = ref<HTMLElement | null>(null);

function move(date: string): void {
    focusDate.value = date;

    if (date.slice(0, 7) !== shown.value.slice(0, 7)) {
        shown.value = startOfMonth(date);
    }

    requestAnimationFrame(() => grid.value?.querySelector<HTMLElement>(`[data-date="${date}"]`)?.focus());
}

function onKey(event: KeyboardEvent, date: string): void {
    const steps: Record<string, () => string> = {
        ArrowLeft: () => addDays(date, -1),
        ArrowRight: () => addDays(date, 1),
        ArrowUp: () => addDays(date, -7),
        ArrowDown: () => addDays(date, 7),
        PageUp: () => addMonths(date, -1),
        PageDown: () => addMonths(date, 1),
        Home: () => addDays(date, -((new Date(`${date}T00:00:00Z`).getUTCDay() - props.weekStartsOn + 7) % 7)),
        End: () => addDays(date, 6 - ((new Date(`${date}T00:00:00Z`).getUTCDay() - props.weekStartsOn + 7) % 7)),
    };
    const step = steps[event.key];

    if (step) {
        event.preventDefault();
        move(step());
    }
}

const inSelection = (date: string): boolean => props.selected !== null && date >= props.selected.start && date < props.selected.end;
</script>

<template>
    <div class="select-none">
        <div class="mb-1 flex items-center justify-between gap-1 px-1">
            <p class="text-sm font-medium text-ink" aria-live="polite">{{ heading }}</p>
            <div v-if="navigable" class="flex items-center">
                <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.miniMonth.previous')" @click="shown = addMonths(shown, -1)">
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </button>
                <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.miniMonth.next')" @click="shown = addMonths(shown, 1)">
                    <ChevronRight class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>

        <table ref="grid" class="w-full table-fixed border-collapse text-center" role="grid" :aria-label="heading">
            <thead>
                <tr>
                    <th v-for="day in weekdays" :key="day" scope="col" class="pb-1 text-[0.6875rem] font-medium text-ink-subtle">
                        <abbr :title="weekdayName(day, format)" class="no-underline">{{ weekdayName(day, format, 'narrow') }}</abbr>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="week in weeks" :key="week[0]">
                    <td v-for="date in week" :key="date" class="p-0" :class="inSelection(date) ? 'bg-brand-soft first:rounded-l-full last:rounded-r-full' : ''">
                        <button
                            type="button"
                            :data-date="date"
                            :tabindex="date === focusDate ? 0 : -1"
                            :aria-current="date === today ? 'date' : undefined"
                            :aria-label="format.date(date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })"
                            :class="[
                                'relative mx-auto flex size-7 items-center justify-center rounded-full text-xs transition focus-visible:ring-2 focus-visible:ring-ring',
                                date === today ? 'bg-brand font-semibold text-brand-ink' : 'hover:bg-hover',
                                date !== today && date.slice(0, 7) !== shown.slice(0, 7) ? 'text-ink-subtle' : '',
                                date !== today && date.slice(0, 7) === shown.slice(0, 7) ? 'text-ink' : '',
                            ]"
                            @click="emit('pick', date)"
                            @keydown="onKey($event, date)"
                            @focus="focusDate = date"
                        >
                            {{ format.date(date, { day: 'numeric' }) }}
                            <span v-if="marked?.has(date) && date !== today" class="absolute bottom-0.5 size-1 rounded-full bg-brand" aria-hidden="true" />
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
