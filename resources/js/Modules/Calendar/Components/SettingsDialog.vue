<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import { offsetLabel, timezones, VIEWS } from '../lib/dates';
import { describeReminder } from '../lib/describe';
import { useCalendarFormat } from '../lib/format';
import { weekdayName } from '../lib/recurrence';
import type { CalendarPreferences } from '../types';

/** A member's own calendar settings: time zone, week, clock, default view and length, notifications. */
const props = defineProps<{
    preferences: CalendarPreferences;
    /** The zone in effect (theirs, or the workspace's). */
    timezone: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const format = useCalendarFormat();

const form = useForm({
    timezone: '' as string,
    week_starts_on: 0,
    time_format: '12h' as '12h' | '24h',
    default_view: 'week' as CalendarPreferences['defaultView'],
    default_duration: 60,
    default_reminder: '' as string | number,
    show_declined: true,
});

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.timezone = props.preferences.timezone ?? '';
    form.week_starts_on = props.preferences.weekStartsOn;
    form.time_format = props.preferences.timeFormat;
    form.default_view = props.preferences.defaultView;
    form.default_duration = props.preferences.defaultDuration;
    form.default_reminder = props.preferences.defaultReminder ?? '';
    form.show_declined = props.preferences.showDeclined;
});

const zoneOptions = computed(() => [
    { value: '', label: t('calendar.settingsForm.workspaceZone') },
    ...timezones([props.timezone]).map((zone) => ({ value: zone, label: `${zone.replaceAll('_', ' ')} (${offsetLabel(zone)})` })),
]);
const weekOptions = computed(() => [6, 0, 1].map((day) => ({ value: day, label: weekdayName(day, format.value) })));
const clockOptions = computed(() => [
    { value: '12h', label: t('calendar.settingsForm.clock12', { example: '1:00 PM' }) },
    { value: '24h', label: t('calendar.settingsForm.clock24', { example: '13:00' }) },
]);
const viewOptions = computed(() => VIEWS.map((view) => ({ value: view, label: t(`calendar.views.${view}`) })));
const durationOptions = computed(() => [15, 30, 45, 60, 90, 120].map((minutes) => ({ value: minutes, label: t('calendar.settingsForm.minutes', { count: format.value.number(minutes) }) })));
const reminderOptions = computed(() => [
    { value: '', label: t('calendar.settingsForm.noReminder') },
    ...[0, 5, 10, 15, 30, 60, 1440].map((minutes) => ({ value: minutes, label: describeReminder(minutes, false, t, format.value) })),
]);

function save(): void {
    form.transform((data) => ({
        ...data,
        timezone: data.timezone === '' ? null : data.timezone,
        default_reminder: data.default_reminder === '' ? null : Number(data.default_reminder),
        week_starts_on: Number(data.week_starts_on),
        default_duration: Number(data.default_duration),
    })).put(route('calendar.preferences.update'), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('calendar.settingsForm.heading')" :persistent="form.processing">
        <form id="calendar-settings-form" class="grid gap-4" @submit.prevent="save">
            <Select v-model="form.timezone" :options="zoneOptions" :label="t('calendar.settingsForm.timezone')" :hint="t('calendar.settingsForm.timezoneHint')" :error="form.errors.timezone" />
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.week_starts_on" :options="weekOptions" :label="t('calendar.settingsForm.weekStart')" :error="form.errors.week_starts_on" />
                <Select v-model="form.time_format" :options="clockOptions" :label="t('calendar.settingsForm.clock')" :error="form.errors.time_format" />
                <Select v-model="form.default_view" :options="viewOptions" :label="t('calendar.settingsForm.defaultView')" :error="form.errors.default_view" />
                <Select v-model="form.default_duration" :options="durationOptions" :label="t('calendar.settingsForm.defaultDuration')" :error="form.errors.default_duration" />
                <Select v-model="form.default_reminder" :options="reminderOptions" :label="t('calendar.settingsForm.defaultReminder')" :error="form.errors.default_reminder" />
            </div>
            <Checkbox v-model="form.show_declined" :label="t('calendar.settingsForm.showDeclined')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('calendar.common.cancel') }}</Button>
            <Button type="submit" form="calendar-settings-form" variant="primary" :loading="form.processing">{{ t('calendar.common.save') }}</Button>
        </template>
    </Modal>
</template>
