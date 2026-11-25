<script setup lang="ts">
import { Bell, Plus, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { addDays, addMinutes, datePart, minutesBetween, timezones, weekday } from '../lib/dates';
import { REMINDER_FACTORS, reminderUnit, type ReminderUnit } from '../lib/describe';
import { colorVar, useCalendarFormat } from '../lib/format';
import { isLastWeekday, presetOf, presetRule, weekdayName, weekOfMonth, workWeekFrom, type RepeatPreset } from '../lib/recurrence';
import { convertWall } from '../lib/zones';
import type { CalendarEvent, CalendarInfo, EventColor, EventDraft, Frequency, MonthlyMode, Person, Recurrence } from '../types';

export interface EventPayload {
    calendar_id: string;
    title: string;
    description: string;
    location: string;
    all_day: boolean;
    start: string;
    end: string;
    timezone: string;
    recurrence: Recurrence | null;
    color: EventColor | null;
    show_as: 'busy' | 'free';
    visibility: 'default' | 'private';
    reminders: number[];
    guests: string[];
}

/**
 * Create or edit an event. Times are typed in the event's own time zone (the viewer's for a new event), so a meeting
 * planned for 9:00 in London shows and saves as 9:00 London even for someone reading it in Dhaka.
 */
const props = defineProps<{
    event: CalendarEvent | null;
    draft: EventDraft | null;
    calendars: CalendarInfo[];
    defaultCalendarId: string | null;
    members: Person[];
    timezone: string;
    weekStartsOn: number;
    defaultReminder: number | null;
    errors: Partial<Record<string, string>>;
    processing: boolean;
    colors: EventColor[];
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ save: [payload: EventPayload, recurrenceChanged: boolean]; remove: [] }>();

const { t } = useI18n();
const format = useCalendarFormat();

const MAX_REMINDERS = 5;
const MAX_GUESTS = 100;

const form = reactive({
    title: '',
    allDay: false,
    startDate: '',
    startTime: '09:00',
    endDate: '',
    endTime: '10:00',
    timezone: props.timezone,
    preset: 'none' as RepeatPreset,
    frequency: 'weekly' as Frequency,
    interval: 1,
    weekdays: [] as number[],
    monthly: 'day' as MonthlyMode,
    ends: 'never' as 'never' | 'until' | 'count',
    until: '',
    count: 10,
    calendarId: '',
    location: '',
    description: '',
    color: null as EventColor | null,
    showAs: 'busy' as 'busy' | 'free',
    visibility: 'default' as 'default' | 'private',
    reminders: [] as Array<{ amount: number; unit: ReminderUnit }>,
    guests: [] as string[],
});

let initialRecurrence = '';
const workWeek = computed(() => workWeekFrom(props.weekStartsOn));

function load(): void {
    const event = props.event;
    const draft = props.draft;
    const zone = event?.timezone && !event.allDay ? event.timezone : props.timezone;
    const allDay = event?.allDay ?? draft?.allDay ?? false;
    let start = event?.start ?? draft?.start ?? '';
    let end = event?.end ?? draft?.end ?? '';

    if (!allDay && event && zone !== props.timezone) {
        start = convertWall(start, props.timezone, zone);
        end = convertWall(end, props.timezone, zone);
    }

    form.title = event?.title ?? '';
    form.allDay = allDay;
    form.timezone = zone;
    form.startDate = datePart(start);
    form.endDate = allDay ? (event ? addDays(end, -1) : datePart(end)) : datePart(end);
    form.startTime = allDay ? '09:00' : start.slice(11, 16);
    form.endTime = allDay ? '10:00' : end.slice(11, 16);
    form.calendarId = event?.calendarId ?? props.defaultCalendarId ?? props.calendars[0]?.id ?? '';
    form.location = event?.location ?? '';
    form.description = event?.description ?? '';
    form.color = event?.eventColor ?? null;
    form.showAs = event?.showAs ?? 'busy';
    form.visibility = event?.visibility ?? 'default';
    const reminders = event ? (event.reminders ?? []) : props.defaultReminder !== null ? [allDay ? Math.max(props.defaultReminder, 0) : props.defaultReminder] : [];
    form.reminders = reminders.map((minutes) => {
        const [unit, amount] = reminderUnit(minutes);

        return { unit, amount };
    });
    form.guests = (event?.guests ?? []).map((guest) => guest.id);

    const rule = event?.recurrence ?? null;
    form.preset = presetOf(rule, form.startDate, workWeek.value);
    form.frequency = rule?.frequency ?? 'weekly';
    form.interval = rule?.interval ?? 1;
    form.weekdays = rule?.weekdays?.length ? [...rule.weekdays] : [weekday(form.startDate)];
    form.monthly = rule?.monthly ?? 'day';
    form.ends = rule?.until ? 'until' : rule?.count ? 'count' : 'never';
    form.until = rule?.until ?? addDays(form.startDate, 90);
    form.count = rule?.count ?? 10;
    initialRecurrence = JSON.stringify(recurrence());
}

watch(open, (value) => {
    if (value) {
        load();
    }
});

// Moving the start keeps the length.
let lastStart = '';
watch(
    () => [form.startDate, form.startTime, form.allDay] as const,
    () => {
        const start = form.allDay ? form.startDate : `${form.startDate}T${form.startTime}`;

        if (lastStart && form.startDate) {
            if (form.allDay && lastStart.length === 10) {
                const days = Math.max(0, minutesBetween(`${lastStart}T00:00`, `${form.endDate}T00:00`) / 1440);
                form.endDate = addDays(form.startDate, days);
            } else if (!form.allDay && lastStart.length > 10) {
                const length = Math.max(0, minutesBetween(lastStart, `${form.endDate}T${form.endTime}`));
                const end = addMinutes(start, length);
                form.endDate = datePart(end);
                form.endTime = end.slice(11, 16);
            }
        }

        lastStart = start;
    },
);
watch(open, () => {
    lastStart = '';
});

const presets = computed<Array<{ value: RepeatPreset; label: string }>>(() => {
    if (!form.startDate) {
        return [];
    }

    const day = weekdayName(weekday(form.startDate), format.value);
    const list: Array<{ value: RepeatPreset; label: string }> = [
        { value: 'none', label: t('calendar.repeat.none') },
        { value: 'daily', label: t('calendar.repeat.summary.daily.one') },
        { value: 'weekly', label: t('calendar.repeat.summary.weekly.one', { days: day }) },
        {
            value: 'weekdays',
            label: t('calendar.repeat.weekdays', {
                days: new Intl.ListFormat(format.value.tag, { style: 'narrow', type: 'unit' }).format(workWeek.value.map((d) => weekdayName(d, format.value, 'short'))),
            }),
        },
        { value: 'monthly_day', label: t('calendar.repeat.summary.monthlyDay.one', { day: format.value.number(Number(form.startDate.slice(8, 10))) }) },
    ];

    if (weekOfMonth(form.startDate) <= 4) {
        list.push({ value: 'monthly_weekday', label: t('calendar.repeat.summary.monthlyWeekday.one', { nth: t(`calendar.repeat.nth.n${weekOfMonth(form.startDate)}`), weekday: day }) });
    }

    if (isLastWeekday(form.startDate)) {
        list.push({ value: 'monthly_last', label: t('calendar.repeat.summary.monthlyLast.one', { weekday: day }) });
    }

    list.push(
        { value: 'yearly', label: t('calendar.repeat.summary.yearly.one', { date: format.value.date(form.startDate, { day: 'numeric', month: 'long' }) }) },
        { value: 'custom', label: t('calendar.repeat.custom') },
    );

    return list;
});

function recurrence(): Recurrence | null {
    if (form.preset === 'none') {
        return null;
    }

    if (form.preset !== 'custom') {
        return presetRule(form.preset, form.startDate, workWeek.value);
    }

    return {
        frequency: form.frequency,
        interval: Math.min(99, Math.max(1, Math.round(form.interval) || 1)),
        weekdays: form.frequency === 'weekly' ? [...new Set([...form.weekdays, weekday(form.startDate)])].sort() : [],
        monthly: form.frequency === 'monthly' ? form.monthly : 'day',
        count: form.ends === 'count' ? Math.min(730, Math.max(1, Math.round(form.count) || 1)) : null,
        until: form.ends === 'until' && form.until ? form.until : null,
    };
}

const frequencyOptions = computed(() =>
    (['daily', 'weekly', 'monthly', 'yearly'] as Frequency[]).map((value) => ({ value, label: t(`calendar.repeat.units.${value}.${form.interval === 1 ? 'one' : 'other'}`) })),
);
const monthlyOptions = computed(() => {
    if (!form.startDate) {
        return [];
    }

    const day = weekdayName(weekday(form.startDate), format.value);
    const options = [{ value: 'day', label: t('calendar.repeat.summary.monthlyDay.one', { day: format.value.number(Number(form.startDate.slice(8, 10))) }) }];

    if (weekOfMonth(form.startDate) <= 4) {
        options.push({ value: 'weekday', label: t('calendar.repeat.summary.monthlyWeekday.one', { nth: t(`calendar.repeat.nth.n${weekOfMonth(form.startDate)}`), weekday: day }) });
    }

    if (isLastWeekday(form.startDate)) {
        options.push({ value: 'last_weekday', label: t('calendar.repeat.summary.monthlyLast.one', { weekday: day }) });
    }

    return options;
});
const weekdayOrder = computed(() => Array.from({ length: 7 }, (_, i) => (props.weekStartsOn + i) % 7));

function toggleWeekday(day: number): void {
    if (day === weekday(form.startDate)) {
        return;
    }

    form.weekdays = form.weekdays.includes(day) ? form.weekdays.filter((item) => item !== day) : [...form.weekdays, day];
}

const calendarOptions = computed(() => props.calendars.map((calendar) => ({ value: calendar.id, label: calendar.name })));
const zoneOptions = computed(() => timezones([props.timezone, form.timezone]).map((zone) => ({ value: zone, label: zone.replaceAll('_', ' ') })));
const unitOptions = computed(() =>
    (['minutes', 'hours', 'days', 'weeks'] as ReminderUnit[]).map((unit) => ({ value: unit, label: t(`calendar.reminders.units.${unit}`) })),
);
const selectedCalendar = computed(() => props.calendars.find((calendar) => calendar.id === form.calendarId));
const memberName = (id: string): string => props.members.find((member) => member.id === id)?.name ?? props.event?.guests?.find((guest) => guest.id === id)?.name ?? '';
const guestChoices = computed(() => [
    { value: '', label: t('calendar.editor.addGuest') },
    ...props.members.filter((member) => !form.guests.includes(member.id)).map((member) => ({ value: member.id, label: member.name })),
]);
const pickedGuest = ref<string | number | null>('');
watch(pickedGuest, (id) => {
    if (typeof id === 'string' && id !== '' && form.guests.length < MAX_GUESTS) {
        form.guests = [...form.guests, id];
    }

    if (id !== '') {
        pickedGuest.value = '';
    }
});

function addReminder(): void {
    if (form.reminders.length < MAX_REMINDERS) {
        form.reminders = [...form.reminders, form.allDay ? { amount: 1, unit: 'days' } : { amount: 10, unit: 'minutes' }];
    }
}

const reminderMinutes = computed(() =>
    [...new Set(form.reminders.map((item) => Math.min(40_320, Math.max(0, Math.round(item.amount) || 0) * REMINDER_FACTORS[item.unit])))].sort((a, b) => a - b),
);

const timesInvalid = computed(() => {
    if (!form.startDate || !form.endDate) {
        return false;
    }

    return form.allDay ? form.endDate < form.startDate : `${form.endDate}T${form.endTime}` < `${form.startDate}T${form.startTime}`;
});

function submit(): void {
    if (timesInvalid.value) {
        return;
    }

    const rule = recurrence();
    emit(
        'save',
        {
            calendar_id: form.calendarId,
            title: form.title.trim(),
            description: form.description,
            location: form.location,
            all_day: form.allDay,
            start: form.allDay ? form.startDate : `${form.startDate}T${form.startTime}`,
            end: form.allDay ? form.endDate : `${form.endDate}T${form.endTime}`,
            timezone: form.timezone,
            recurrence: rule,
            color: form.color,
            show_as: form.showAs,
            visibility: form.visibility,
            reminders: reminderMinutes.value,
            guests: form.guests,
        },
        JSON.stringify(rule) !== initialRecurrence,
    );
}

const errorFor = (key: string): string | undefined => props.errors[key] ?? undefined;
const recurrenceError = computed(() => Object.entries(props.errors).find(([key]) => key.startsWith('recurrence'))?.[1]);
</script>

<template>
    <Modal v-model:open="open" size="lg" :title="event ? t('calendar.editor.editHeading') : t('calendar.editor.createHeading')" :persistent="processing">
        <form id="calendar-event-form" class="grid gap-4" @submit.prevent="submit">
            <Input v-model="form.title" :label="t('calendar.editor.titleLabel')" :placeholder="t('calendar.editor.titlePlaceholder')" :error="errorFor('title')" maxlength="200" control-class="text-lg" autofocus />

            <fieldset class="grid gap-3">
                <legend class="sr-only">{{ t('calendar.editor.when') }}</legend>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_auto_1fr_auto]">
                    <Input v-model="form.startDate" type="date" :label="t('calendar.editor.startDate')" :error="errorFor('start')" required />
                    <Input v-if="!form.allDay" v-model="form.startTime" type="time" step="900" :label="t('calendar.editor.startTime')" required />
                    <Input v-model="form.endDate" type="date" :min="form.startDate" :label="t('calendar.editor.endDate')" :error="errorFor('end') ?? (timesInvalid ? t('calendar.editor.endBeforeStart') : undefined)" required />
                    <Input v-if="!form.allDay" v-model="form.endTime" type="time" step="900" :label="t('calendar.editor.endTime')" required />
                </div>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                    <Checkbox v-model="form.allDay" :label="t('calendar.editor.allDay')" />
                    <Select v-if="!form.allDay" v-model="form.timezone" :options="zoneOptions" :aria-label="t('calendar.editor.timezone')" class="min-w-0 flex-1 sm:max-w-xs" />
                </div>
            </fieldset>

            <Select v-model="form.preset" :options="presets" :label="t('calendar.editor.repeat')" :error="recurrenceError" />

            <fieldset v-if="form.preset === 'custom'" class="grid gap-3 rounded-card border border-line p-3">
                <legend class="px-1 text-xs font-medium text-ink-subtle">{{ t('calendar.repeat.customHeading') }}</legend>
                <div class="flex flex-wrap items-end gap-2">
                    <Input v-model.number="form.interval" type="number" min="1" max="99" :label="t('calendar.repeat.every')" class="w-24" />
                    <Select v-model="form.frequency" :options="frequencyOptions" :aria-label="t('calendar.repeat.unit')" class="w-40" />
                </div>

                <div v-if="form.frequency === 'weekly'" role="group" :aria-label="t('calendar.repeat.on')" class="flex flex-wrap gap-1.5">
                    <button
                        v-for="day in weekdayOrder"
                        :key="day"
                        type="button"
                        :aria-pressed="form.weekdays.includes(day) || day === weekday(form.startDate)"
                        :aria-label="weekdayName(day, format)"
                        :class="[
                            'flex size-8 items-center justify-center rounded-full text-xs font-medium transition focus-visible:ring-2 focus-visible:ring-ring',
                            form.weekdays.includes(day) || day === weekday(form.startDate) ? 'bg-brand text-brand-ink' : 'bg-control text-ink-muted hover:text-ink',
                        ]"
                        @click="toggleWeekday(day)"
                    >
                        {{ weekdayName(day, format, 'narrow') }}
                    </button>
                </div>

                <Select v-if="form.frequency === 'monthly'" v-model="form.monthly" :options="monthlyOptions" :aria-label="t('calendar.repeat.on')" />

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium text-ink">{{ t('calendar.repeat.ends') }}</legend>
                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.ends" type="radio" value="never" class="accent-[var(--brand)]" />{{ t('calendar.repeat.never') }}</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.ends" type="radio" value="until" class="accent-[var(--brand)]" />{{ t('calendar.repeat.onDate') }}</label>
                        <Input v-model="form.until" type="date" :min="form.startDate" :disabled="form.ends !== 'until'" :aria-label="t('calendar.repeat.onDate')" class="w-44" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 text-sm text-ink"><input v-model="form.ends" type="radio" value="count" class="accent-[var(--brand)]" />{{ t('calendar.repeat.after') }}</label>
                        <Input v-model.number="form.count" type="number" min="1" max="730" :disabled="form.ends !== 'count'" :aria-label="t('calendar.repeat.occurrences')" class="w-24" />
                        <span class="text-sm text-ink-muted">{{ t('calendar.repeat.occurrences') }}</span>
                    </div>
                </fieldset>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.calendarId" :options="calendarOptions" :label="t('calendar.editor.calendar')" :error="errorFor('calendar_id')" />
                <Input v-model="form.location" :label="t('calendar.editor.location')" :error="errorFor('location')" maxlength="255" optional />
            </div>

            <Field :label="t('calendar.editor.color')">
                <div role="radiogroup" :aria-label="t('calendar.editor.color')" class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="form.color === null"
                        :style="colorVar(selectedCalendar?.color)"
                        :class="['flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs transition focus-visible:ring-2 focus-visible:ring-ring', form.color === null ? 'border-line-strong bg-active text-ink' : 'border-line text-ink-muted']"
                        @click="form.color = null"
                    >
                        <span class="size-3 rounded-full bg-(--ev)" aria-hidden="true" />
                        {{ t('calendar.editor.colorDefault') }}
                    </button>
                    <button
                        v-for="color in colors"
                        :key="color"
                        type="button"
                        role="radio"
                        :aria-checked="form.color === color"
                        :aria-label="t(`calendar.colors.${color}`)"
                        :title="t(`calendar.colors.${color}`)"
                        :style="colorVar(color)"
                        :class="['size-7 rounded-full bg-(--ev) transition focus-visible:ring-2 focus-visible:ring-ring', form.color === color ? 'ring-2 ring-ink ring-offset-2 ring-offset-(--canvas)' : 'hover:scale-110']"
                        @click="form.color = color"
                    />
                </div>
            </Field>

            <Field :label="t('calendar.editor.guests')" :hint="t('calendar.editor.guestsHint')">
                <div class="grid gap-2">
                    <ul v-if="form.guests.length" class="flex flex-wrap gap-1.5">
                        <li v-for="id in form.guests" :key="id" class="flex items-center gap-1 rounded-full bg-control py-0.5 pr-1 pl-2.5 text-sm text-ink">
                            {{ memberName(id) }}
                            <button type="button" class="rounded-full p-0.5 text-ink-subtle hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.editor.removeGuest', { name: memberName(id) })" @click="form.guests = form.guests.filter((guest) => guest !== id)">
                                <X class="size-3.5" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <Select v-if="guestChoices.length > 1" v-model="pickedGuest" :options="guestChoices" :aria-label="t('calendar.editor.addGuest')" :error="errorFor('guests')" />
                </div>
            </Field>

            <Field :label="t('calendar.editor.reminders')">
                <div class="grid gap-2">
                    <div v-for="(reminder, index) in form.reminders" :key="index" class="flex flex-wrap items-center gap-2">
                        <Bell class="size-4 text-ink-subtle" aria-hidden="true" />
                        <Input v-model.number="reminder.amount" type="number" min="0" :max="Math.floor(40320 / REMINDER_FACTORS[reminder.unit])" :aria-label="t('calendar.reminders.amount')" class="w-20" />
                        <Select v-model="reminder.unit" :options="unitOptions" :aria-label="t('calendar.reminders.unit')" class="w-32" />
                        <span class="text-sm text-ink-muted">{{ form.allDay ? t('calendar.reminders.beforeAt', { time: format.time(9 * 60) }) : t('calendar.reminders.beforeStart') }}</span>
                        <button type="button" class="ml-auto rounded-full p-1.5 text-ink-subtle hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.reminders.remove')" @click="form.reminders = form.reminders.filter((_, i) => i !== index)">
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                    <div>
                        <Button v-if="form.reminders.length < MAX_REMINDERS" size="sm" variant="ghost" @click="addReminder">
                            <Plus class="size-4" aria-hidden="true" />
                            {{ t('calendar.reminders.add') }}
                        </Button>
                    </div>
                    <p v-if="errorFor('reminders')" class="text-xs text-danger">{{ errorFor('reminders') }}</p>
                </div>
            </Field>

            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.showAs" :options="[{ value: 'busy', label: t('calendar.editor.busy') }, { value: 'free', label: t('calendar.editor.free') }]" :label="t('calendar.editor.showAs')" />
                <Select v-model="form.visibility" :options="[{ value: 'default', label: t('calendar.editor.visibilityDefault') }, { value: 'private', label: t('calendar.editor.visibilityPrivate') }]" :label="t('calendar.editor.visibility')" />
            </div>

            <Textarea v-model="form.description" :label="t('calendar.editor.description')" :error="errorFor('description')" :rows="4" maxlength="5000" optional />
        </form>

        <template #footer>
            <Button v-if="event?.editable" variant="ghost" class="mr-auto text-danger" :disabled="processing" @click="emit('remove')">{{ t('calendar.event.delete') }}</Button>
            <Button variant="ghost" :disabled="processing" @click="open = false">{{ t('calendar.common.cancel') }}</Button>
            <Button type="submit" form="calendar-event-form" variant="primary" :loading="processing" :disabled="timesInvalid || !form.calendarId">{{ t('calendar.common.save') }}</Button>
        </template>
    </Modal>
</template>
