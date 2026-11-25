<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, ChevronLeft, ChevronRight, Keyboard, Menu, Search, Settings, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import Kbd from '@/Core/Components/Kbd.vue';
import Modal from '@/Core/Components/Modal.vue';
import { useFullHeightPage } from '@/Core/composables/useFullHeightPage';
import { usePageTitle } from '@/Core/composables/usePageTitle';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CalendarDialog from '../Components/CalendarDialog.vue';
import CalendarSidebar from '../Components/CalendarSidebar.vue';
import EventEditor, { type EventPayload } from '../Components/EventEditor.vue';
import EventPopover from '../Components/EventPopover.vue';
import MonthGrid from '../Components/MonthGrid.vue';
import ScheduleView from '../Components/ScheduleView.vue';
import ScopeDialog from '../Components/ScopeDialog.vue';
import SettingsDialog from '../Components/SettingsDialog.vue';
import TimeGrid from '../Components/TimeGrid.vue';
import YearView from '../Components/YearView.vue';
import { addDays, addMinutes, datePart, datesIn, minuteOfDay, shiftAnchor, VIEWS, wallNow, wallTime, type CalendarView, type DateRange } from '../lib/dates';
import { describeWhen } from '../lib/describe';
import { colorVar, useCalendarFormat } from '../lib/format';
import { eventsOn } from '../lib/layout';
import type { CalendarEvent, CalendarInfo, CalendarPreferences, EditScope, EventColor, EventDraft, GuestResponse, Person, SearchResult } from '../types';

const props = defineProps<{
    view: CalendarView;
    date: string;
    today: string;
    now: string;
    range: DateRange;
    timezone: string;
    preferences: CalendarPreferences;
    calendars: CalendarInfo[];
    events: CalendarEvent[];
    members: Person[];
    followed: string[];
    search: string | null;
    results: SearchResult[] | null;
    colors: EventColor[];
    can: { create: boolean; createShared: boolean };
}>();

defineOptions({ layout: TenantLayout });

const { t } = useI18n();
const format = useCalendarFormat();
useFullHeightPage(true);
usePageTitle().setPageTitle(t('calendar.title'));

// ---- the clock -------------------------------------------------------------------------------------------------

const nowWall = ref(props.now);
let ticker: number | undefined;
onMounted(() => {
    ticker = window.setInterval(() => {
        nowWall.value = wallNow(props.timezone);
    }, 30_000);
});
onBeforeUnmount(() => window.clearInterval(ticker));
watch(() => props.now, (now) => {
    nowWall.value = now;
});
const today = computed(() => datePart(nowWall.value));

// ---- navigation ------------------------------------------------------------------------------------------------

const RELOAD = ['view', 'date', 'today', 'now', 'range', 'events', 'search', 'results'];
const loading = ref(false);

function visit(view: CalendarView, date: string, search: string | null = null): void {
    router.get(route('calendar.index'), { view, date, search: search ?? undefined }, {
        preserveState: true,
        preserveScroll: true,
        only: RELOAD,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

const goToday = (): void => visit(props.view, today.value);
const step = (direction: 1 | -1): void => visit(props.view, shiftAnchor(props.view, props.date, direction));
const setView = (view: CalendarView): void => visit(view, props.date);
const openDay = (date: string): void => visit('day', date);

const title = computed(() => {
    const fmt = format.value;
    const last = addDays(props.range.end, -1);

    switch (props.view) {
        case 'day':
            return fmt.date(props.date, { day: 'numeric', month: 'long', year: 'numeric' });
        case 'month':
            return fmt.date(props.date, { month: 'long', year: 'numeric' });
        case 'year':
            return fmt.date(props.date, { year: 'numeric' });
        default: {
            if (props.range.start.slice(0, 7) === last.slice(0, 7)) {
                return fmt.date(props.range.start, { month: 'long', year: 'numeric' });
            }

            return t('calendar.grid.timeRange', {
                start: fmt.date(props.range.start, props.range.start.slice(0, 4) === last.slice(0, 4) ? { month: 'short' } : { month: 'short', year: 'numeric' }),
                end: fmt.date(last, { month: 'short', year: 'numeric' }),
            });
        }
    }
});

// ---- what is shown ---------------------------------------------------------------------------------------------

const toggled = ref<Record<string, boolean>>({});
const moved = ref<Record<string, { start: string; end: string }>>({});
const calendarsById = computed(() => new Map(props.calendars.map((calendar) => [calendar.id, calendar])));
const shown = computed(() =>
    props.events
        .filter((event) => {
            const calendar = calendarsById.value.get(event.calendarId);

            return calendar === undefined || (toggled.value[calendar.id] ?? calendar.visible);
        })
        .map((event) => (moved.value[event.key] ? { ...event, ...moved.value[event.key] } : event)),
);
const visibleDates = computed(() => datesIn(props.range));
const weeks = computed(() => Array.from({ length: Math.ceil(visibleDates.value.length / 7) }, (_, week) => visibleDates.value.slice(week * 7, week * 7 + 7)));
const writable = computed(() => props.calendars.filter((calendar) => calendar.editable));
const primary = computed(() => props.calendars.find((calendar) => calendar.mine && calendar.isPrimary) ?? writable.value[0] ?? null);
const canCreate = computed(() => props.can.create && writable.value.length > 0);

function toggleCalendar(calendar: CalendarInfo, visible: boolean): void {
    toggled.value = { ...toggled.value, [calendar.id]: visible };
    router.patch(route('calendar.calendars.toggle', { calendar: calendar.id }), { visible }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            const { [calendar.id]: _saved, ...rest } = toggled.value;
            toggled.value = rest;
        },
    });
}

// ---- side panel ------------------------------------------------------------------------------------------------

const wide = ref(true);
const sidebarOpen = ref(true);
const measure = (): void => {
    const next = window.matchMedia('(min-width: 1024px)').matches;

    if (next !== wide.value) {
        sidebarOpen.value = next;
    }

    wide.value = next;
};
onMounted(() => {
    wide.value = window.matchMedia('(min-width: 1024px)').matches;
    sidebarOpen.value = wide.value;
    window.addEventListener('resize', measure);
});
onBeforeUnmount(() => window.removeEventListener('resize', measure));

function pickDate(date: string): void {
    if (!wide.value) {
        sidebarOpen.value = false;
    }

    visit(props.view === 'year' ? 'day' : props.view, date);
}

// ---- the event card --------------------------------------------------------------------------------------------

const selectedKey = ref<string | null>(null);
const selectedAnchor = ref<HTMLElement | null>(null);
const selected = computed(() => (selectedKey.value === null ? null : shown.value.find((event) => event.key === selectedKey.value) ?? null));

function openEvent(event: CalendarEvent, anchor: HTMLElement | null): void {
    selectedAnchor.value = anchor;
    selectedKey.value = event.key;
}

function respond(response: Exclude<GuestResponse, 'needs_action'>): void {
    const event = selected.value;

    if (event) {
        router.post(route('calendar.events.respond', { event: event.id }), { response }, { preserveScroll: true, preserveState: true });
    }
}

// The "+ more" list of a day in the month view.
const moreDate = ref<string | null>(null);
const moreEvents = computed(() => (moreDate.value ? eventsOn(shown.value, moreDate.value) : []));

// ---- creating and editing --------------------------------------------------------------------------------------

const editorOpen = ref(false);
const editing = ref<CalendarEvent | null>(null);
const draft = ref<EventDraft | null>(null);
const errors = ref<Record<string, string>>({});
const processing = ref(false);

function startCreate(from?: EventDraft): void {
    if (!canCreate.value) {
        return;
    }

    if (from) {
        draft.value = from;
    } else {
        const date = props.view === 'month' || props.view === 'year' || props.date === today.value ? (props.date.slice(0, 7) === today.value.slice(0, 7) ? today.value : props.date) : props.date;
        const minute = date === today.value ? Math.ceil((minuteOfDay(nowWall.value) + 1) / 60) * 60 : 9 * 60;
        const start = wallTime(date, Math.min(minute, 23 * 60));
        draft.value = { start, end: addMinutes(start, props.preferences.defaultDuration), allDay: false };
    }

    editing.value = null;
    errors.value = {};
    selectedKey.value = null;
    editorOpen.value = true;
}

function startEdit(): void {
    if (!selected.value) {
        return;
    }

    editing.value = selected.value;
    draft.value = null;
    errors.value = {};
    selectedKey.value = null;
    editorOpen.value = true;
}

// A change to a repeating event asks which occurrences it is for.
const scopeOpen = ref(false);
const scopeMode = ref<'edit' | 'delete'>('edit');
const scopeAllowsThis = ref(true);
let afterScope: ((scope: EditScope) => void) | null = null;

function askScope(mode: 'edit' | 'delete', allowThis: boolean, then: (scope: EditScope) => void): void {
    scopeMode.value = mode;
    scopeAllowsThis.value = allowThis;
    afterScope = then;
    scopeOpen.value = true;
}

function onScope(scope: EditScope): void {
    afterScope?.(scope);
    afterScope = null;
}

function saveEvent(payload: EventPayload, recurrenceChanged: boolean): void {
    const event = editing.value;
    const send = (scope?: EditScope): void => {
        const data = { ...payload, scope, occurrence: event?.occurrence ?? undefined };
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => { processing.value = true; },
            onFinish: () => { processing.value = false; },
            onError: (bag: Record<string, string>) => { errors.value = bag; },
            onSuccess: () => { editorOpen.value = false; },
        };

        if (event) {
            router.put(route('calendar.events.update', { event: event.id }), data, options);
        } else {
            router.post(route('calendar.events.store'), data, options);
        }
    };

    if (event?.seriesId || (event?.recurring && event.occurrence)) {
        askScope('edit', !recurrenceChanged, send);
    } else {
        send();
    }
}

function moveEvent(event: CalendarEvent, start: string, end: string, allDay: boolean): void {
    const send = (scope?: EditScope): void => {
        moved.value = { ...moved.value, [event.key]: { start, end } };
        router.patch(route('calendar.events.move', { event: event.id }), {
            start,
            end: allDay ? addDays(end, -1) : end,
            all_day: allDay,
            timezone: props.timezone,
            scope,
            occurrence: event.occurrence ?? undefined,
        }, {
            preserveScroll: true,
            preserveState: true,
            only: RELOAD,
            onFinish: () => {
                const { [event.key]: _done, ...rest } = moved.value;
                moved.value = rest;
            },
        });
    };

    if (event.recurring) {
        askScope('edit', true, send);
    } else {
        send();
    }
}

const confirmingDelete = ref<CalendarEvent | null>(null);
const deleting = ref(false);

function deleteEvent(event: CalendarEvent | null): void {
    if (!event) {
        return;
    }

    selectedKey.value = null;

    if (event.recurring) {
        askScope('delete', true, (scope) => destroy(event, scope));
    } else {
        confirmingDelete.value = event;
    }
}

function destroy(event: CalendarEvent, scope?: EditScope): void {
    router.delete(route('calendar.events.destroy', { event: event.id }), {
        data: { scope, occurrence: event.occurrence ?? undefined },
        preserveScroll: true,
        preserveState: true,
        onStart: () => { deleting.value = true; },
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = null;
            editorOpen.value = false;
        },
    });
}

// ---- calendars and settings ------------------------------------------------------------------------------------

const calendarDialogOpen = ref(false);
const calendarEditing = ref<CalendarInfo | null>(null);
const calendarShared = ref(false);

function createCalendar(shared: boolean): void {
    calendarEditing.value = null;
    calendarShared.value = shared;
    calendarDialogOpen.value = true;
}

function editCalendar(calendar: CalendarInfo): void {
    calendarEditing.value = calendar;
    calendarShared.value = calendar.shared;
    calendarDialogOpen.value = true;
}

const settingsOpen = ref(false);
const shortcutsOpen = ref(false);

// ---- search ----------------------------------------------------------------------------------------------------

const searching = ref(props.search !== null);
const query = ref(props.search ?? '');
const searchInput = ref<HTMLInputElement | null>(null);

function openSearch(): void {
    searching.value = true;
    requestAnimationFrame(() => searchInput.value?.focus());
}

function runSearch(): void {
    const term = query.value.trim();

    if (term !== '') {
        visit(props.view, props.date, term);
    }
}

function closeSearch(): void {
    searching.value = false;
    query.value = '';

    if (props.search !== null) {
        visit(props.view, props.date);
    }
}

// ---- keyboard --------------------------------------------------------------------------------------------------

const SHORTCUTS: Array<[string, string]> = [
    ['t', 'today'], ['j', 'next'], ['k', 'previous'], ['d', 'day'], ['x', 'four_days'], ['w', 'week'], ['m', 'month'],
    ['y', 'year'], ['a', 'schedule'], ['c', 'create'], ['/', 'search'], ['?', 'help'],
];

const anyDialog = computed(() => editorOpen.value || scopeOpen.value || calendarDialogOpen.value || settingsOpen.value || shortcutsOpen.value || confirmingDelete.value !== null || moreDate.value !== null);

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (event.metaKey || event.ctrlKey || event.altKey || anyDialog.value || selected.value) {
        return;
    }

    if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
        return;
    }

    const views: Record<string, CalendarView> = { d: 'day', x: 'four_days', w: 'week', m: 'month', y: 'year', a: 'schedule' };
    const key = event.key.toLowerCase();

    if (views[key]) {
        setView(views[key]);
    } else if (key === 't') {
        goToday();
    } else if (key === 'j' || key === 'n') {
        step(1);
    } else if (key === 'k' || key === 'p') {
        step(-1);
    } else if (key === 'c') {
        startCreate();
    } else if (key === '/') {
        openSearch();
    } else if (event.key === '?') {
        shortcutsOpen.value = true;
    } else {
        return;
    }

    event.preventDefault();
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const resultWhen = (result: SearchResult): string => describeWhen(result, t, format.value);
</script>

<template>
    <div class="glass flex min-h-0 flex-1 flex-col overflow-hidden rounded-panel">
        <!-- Toolbar -->
        <header class="flex flex-wrap items-center gap-x-2 gap-y-2 border-b border-line px-2 py-2 sm:px-3">
            <button
                type="button"
                class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="t('calendar.toggleSidebar')"
                :aria-expanded="sidebarOpen"
                aria-controls="calendar-sidebar"
                @click="sidebarOpen = !sidebarOpen"
            >
                <Menu class="size-5" aria-hidden="true" />
            </button>

            <template v-if="!searching">
                <Button size="sm" class="rounded-full" :title="t('calendar.shortcutHint', { key: 'T' })" @click="goToday">{{ t('calendar.today') }}</Button>
                <div class="flex items-center">
                    <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t(`calendar.previous.${view}`)" :title="t(`calendar.previous.${view}`)" @click="step(-1)">
                        <ChevronLeft class="size-5" aria-hidden="true" />
                    </button>
                    <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t(`calendar.next.${view}`)" :title="t(`calendar.next.${view}`)" @click="step(1)">
                        <ChevronRight class="size-5" aria-hidden="true" />
                    </button>
                </div>
                <h1 class="order-last w-full truncate px-1 font-display text-lg text-ink sm:order-none sm:w-auto sm:flex-1 sm:text-xl" aria-live="polite">{{ title }}</h1>
            </template>

            <form v-else class="flex min-w-0 flex-1 items-center gap-2" role="search" @submit.prevent="runSearch">
                <button type="button" class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.search.close')" @click="closeSearch">
                    <ArrowLeft class="size-5" aria-hidden="true" />
                </button>
                <input ref="searchInput" v-model="query" type="search" maxlength="100" class="control min-w-0 flex-1" :placeholder="t('calendar.search.placeholder')" :aria-label="t('calendar.search.placeholder')" @keydown.escape="closeSearch" />
                <Button type="submit" size="sm" variant="primary">{{ t('calendar.search.submit') }}</Button>
            </form>

            <div class="ml-auto flex items-center gap-1">
                <button v-if="!searching" type="button" class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.search.open')" :title="t('calendar.search.open')" @click="openSearch">
                    <Search class="size-5" aria-hidden="true" />
                </button>
                <button type="button" class="hidden rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring sm:block" :aria-label="t('calendar.shortcuts.title')" :title="t('calendar.shortcuts.title')" @click="shortcutsOpen = true">
                    <Keyboard class="size-5" aria-hidden="true" />
                </button>
                <button type="button" class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.settings')" :title="t('calendar.settings')" @click="settingsOpen = true">
                    <Settings class="size-5" aria-hidden="true" />
                </button>
                <Dropdown v-if="!searching" align="end" width="w-48">
                    <template #trigger>
                        <Button size="sm" class="rounded-full" :aria-label="t('calendar.chooseView')">
                            {{ t(`calendar.views.${view}`) }}
                            <ChevronDown class="size-4" aria-hidden="true" />
                        </Button>
                    </template>
                    <DropdownItem v-for="item in VIEWS" :key="item" :active="item === view" @select="setView(item)">
                        <span class="flex-1">{{ t(`calendar.views.${item}`) }}</span>
                        <Kbd>{{ SHORTCUTS.find(([, action]) => action === item)?.[0].toUpperCase() }}</Kbd>
                    </DropdownItem>
                </Dropdown>
            </div>
        </header>

        <div class="relative flex min-h-0 flex-1">
            <!-- Side panel: a column on a wide screen, a drawer on a narrow one -->
            <div v-if="sidebarOpen && !wide" class="fixed inset-0 z-40 bg-[rgb(8_14_12/0.42)] lg:hidden" aria-hidden="true" @click="sidebarOpen = false" />
            <aside
                v-show="sidebarOpen"
                id="calendar-sidebar"
                :class="[
                    'w-72 shrink-0 pt-3',
                    wide ? 'border-r border-line' : 'glass-solid fixed inset-y-2 left-2 z-50 rounded-panel shadow-float',
                ]"
            >
                <div v-if="!wide" class="flex justify-end px-3">
                    <button type="button" class="rounded-full p-1.5 text-ink-muted hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.common.close')" @click="sidebarOpen = false">
                        <X class="size-5" aria-hidden="true" />
                    </button>
                </div>
                <CalendarSidebar
                    :date="date"
                    :today="today"
                    :range="range"
                    :week-starts-on="preferences.weekStartsOn"
                    :calendars="calendars"
                    :members="members"
                    :followed="followed"
                    :can-create="canCreate"
                    :can-create-shared="can.createShared"
                    :overrides="toggled"
                    @pick="pickDate"
                    @create-event="startCreate()"
                    @create-calendar="createCalendar"
                    @edit-calendar="editCalendar"
                    @toggle="toggleCalendar"
                />
            </aside>

            <!-- The view -->
            <main :class="['flex min-h-0 min-w-0 flex-1 flex-col transition-opacity', loading ? 'opacity-70' : '']" :aria-busy="loading">
                <section v-if="search !== null && results !== null" class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">
                    <h2 class="mb-3 text-sm font-medium text-ink-muted">{{ t('calendar.search.heading', { query: search }) }}</h2>
                    <p v-if="results.length === 0" class="mt-10 text-center text-sm text-ink-muted">{{ t('calendar.search.none') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="result in results" :key="result.id">
                            <button type="button" class="flex w-full items-center gap-3 rounded-control px-2 py-2.5 text-left transition hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring" :style="colorVar(result.color)" @click="searching = false; visit('day', result.start.slice(0, 10))">
                                <span class="size-2.5 shrink-0 rounded-full bg-(--ev)" aria-hidden="true" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-ink">{{ result.title || t('calendar.grid.untitled') }}</span>
                                    <span class="block truncate text-xs text-ink-muted">{{ resultWhen(result) }}<template v-if="result.location"> · {{ result.location }}</template></span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>

                <TimeGrid
                    v-else-if="view === 'day' || view === 'week' || view === 'four_days'"
                    :dates="visibleDates"
                    :events="shown"
                    :today="today"
                    :now="nowWall"
                    :timezone="timezone"
                    :can-create="canCreate"
                    :default-duration="preferences.defaultDuration"
                    @open="openEvent"
                    @create="startCreate"
                    @move="moveEvent"
                    @pick-date="openDay"
                />
                <MonthGrid
                    v-else-if="view === 'month'"
                    :weeks="weeks"
                    :month="date.slice(0, 7)"
                    :events="shown"
                    :today="today"
                    :can-create="canCreate"
                    :week-starts-on="preferences.weekStartsOn"
                    @open="openEvent"
                    @create="startCreate"
                    @move="moveEvent"
                    @pick-date="openDay"
                    @more="(day) => (moreDate = day)"
                />
                <YearView v-else-if="view === 'year'" :year="date.slice(0, 4)" :today="today" :week-starts-on="preferences.weekStartsOn" @pick-date="openDay" />
                <ScheduleView v-else :range="range" :events="shown" :today="today" @open="openEvent" @pick-date="openDay" />

                <p v-if="!can.create" class="border-t border-line px-4 py-2 text-xs text-ink-subtle">{{ t('calendar.viewOnly') }}</p>
            </main>
        </div>
    </div>

    <EventPopover
        v-if="selected"
        :key="selected.key"
        :event="selected"
        :calendar="calendarsById.get(selected.calendarId)"
        :anchor="selectedAnchor"
        @close="selectedKey = null"
        @edit="startEdit"
        @delete="deleteEvent(selected)"
        @respond="respond"
    />

    <EventEditor
        v-if="canCreate"
        v-model:open="editorOpen"
        :event="editing"
        :draft="draft"
        :calendars="writable"
        :default-calendar-id="primary?.id ?? null"
        :members="members"
        :timezone="timezone"
        :week-starts-on="preferences.weekStartsOn"
        :default-reminder="preferences.defaultReminder"
        :errors="errors"
        :processing="processing"
        :colors="colors"
        @save="saveEvent"
        @remove="deleteEvent(editing)"
    />

    <ScopeDialog v-model:open="scopeOpen" :mode="scopeMode" :allow-this="scopeAllowsThis" @choose="onScope" />

    <ConfirmDialog
        :open="confirmingDelete !== null"
        :title="t('calendar.remove.heading')"
        :description="t('calendar.remove.body')"
        :confirm-label="t('calendar.event.delete')"
        :loading="deleting"
        @update:open="(value) => { if (!value) confirmingDelete = null; }"
        @confirm="confirmingDelete && destroy(confirmingDelete)"
    />

    <CalendarDialog v-model:open="calendarDialogOpen" :calendar="calendarEditing" :shared="calendarShared" :colors="colors" />
    <SettingsDialog v-model:open="settingsOpen" :preferences="preferences" :timezone="timezone" />

    <Modal :open="moreDate !== null" size="sm" :title="moreDate ? format.date(moreDate, { weekday: 'long', day: 'numeric', month: 'long' }) : ''" @update:open="(value) => { if (!value) moreDate = null; }">
        <ul class="space-y-1">
            <li v-for="event in moreEvents" :key="event.key">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-left text-sm text-ink hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring"
                    :style="colorVar(event.color)"
                    @click="moreDate = null; openEvent(event, null)"
                >
                    <span class="size-2.5 shrink-0 rounded-full bg-(--ev)" aria-hidden="true" />
                    <span v-if="!event.allDay" class="shrink-0 text-ink-muted">{{ format.time(minuteOfDay(event.start)) }}</span>
                    <span class="truncate">{{ event.busy ? t('calendar.grid.busy') : event.title || t('calendar.grid.untitled') }}</span>
                </button>
            </li>
        </ul>
    </Modal>

    <Modal v-model:open="shortcutsOpen" size="sm" :title="t('calendar.shortcuts.title')">
        <dl class="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 text-sm">
            <template v-for="[key, action] in SHORTCUTS" :key="key">
                <dt><Kbd>{{ key.toUpperCase() }}</Kbd></dt>
                <dd class="text-ink">{{ t(`calendar.shortcuts.${action}`) }}</dd>
            </template>
        </dl>
    </Modal>
</template>
