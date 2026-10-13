<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, Pencil, Plus, Timer, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SupportNav from '../../Components/SupportNav.vue';
import { WEEKDAYS, clockMinutes } from '../../Components/supportFormat';
import type { TicketPriority } from '../../Components/types';
import { useSupportFormat } from '../../Components/useSupportFormat';

defineOptions({ layout: TenantLayout });

type Targets = Partial<Record<TicketPriority, { first_response?: number | null; resolution?: number | null }>>;
type Hours = Record<string, [string, string][]>;

interface PolicyRow {
    id: string;
    name: string;
    calendarId: string | null;
    targets: Targets;
    pauseOnWaiting: boolean;
    isActive: boolean;
}

interface CalendarRow {
    id: string;
    name: string;
    timezone: string | null;
    hours: Hours;
    holidays: string[];
    isActive: boolean;
}

const props = defineProps<{
    policies: PolicyRow[];
    calendars: CalendarRow[];
    workspaceTimezone: string;
    maxMinutes: number;
    priorities: TicketPriority[];
}>();

const { t } = useI18n();
const format = useFormat();
const { duration } = useSupportFormat();

// ---- Policies ------------------------------------------------------------
const policyOpen = ref(false);
const editingPolicy = ref<PolicyRow | null>(null);

type TargetFields = Record<string, string>;
const policyForm = useForm({
    name: '',
    calendar_id: '',
    pause_on_waiting: true,
    is_active: true,
    targets: {} as TargetFields,
});

const calendarOptions = computed(() => [
    { value: '', label: t('support.sla.aroundTheClock') },
    ...props.calendars.filter((calendar) => calendar.isActive).map((calendar) => ({ value: calendar.id, label: calendar.name })),
]);

function targetKey(priority: string, kind: 'first_response' | 'resolution'): string {
    return `${priority}.${kind}`;
}

function openPolicy(policy: PolicyRow | null): void {
    editingPolicy.value = policy;
    const targets: TargetFields = {};

    for (const priority of props.priorities) {
        for (const kind of ['first_response', 'resolution'] as const) {
            const value = policy?.targets?.[priority]?.[kind];
            targets[targetKey(priority, kind)] = value === null || value === undefined ? '' : String(value);
        }
    }

    policyForm.defaults({
        name: policy?.name ?? '',
        calendar_id: policy?.calendarId ?? '',
        pause_on_waiting: policy?.pauseOnWaiting ?? true,
        is_active: policy?.isActive ?? true,
        targets,
    }).reset();
    policyForm.clearErrors();
    policyOpen.value = true;
}

function savePolicy(): void {
    policyForm.transform((data) => {
        const targets: Record<string, Record<string, number | null>> = {};

        for (const priority of props.priorities) {
            const read = (kind: 'first_response' | 'resolution'): number | null => {
                // A number input may hand back a number, not a string.
                const raw = String(data.targets[targetKey(priority, kind)] ?? '').trim();

                return raw === '' ? null : Number(raw);
            };
            targets[priority] = { first_response: read('first_response'), resolution: read('resolution') };
        }

        return { ...data, calendar_id: data.calendar_id === '' ? null : data.calendar_id, targets };
    });
    const options = { preserveScroll: true, onSuccess: () => { policyOpen.value = false; } };

    if (editingPolicy.value) {
        policyForm.put(route('support.sla.policies.update', { policy: editingPolicy.value.id }), options);
    } else {
        policyForm.post(route('support.sla.policies.store'), options);
    }
}

function policyError(priority: string, kind: 'first_response' | 'resolution'): string | undefined {
    return (policyForm.errors as Record<string, string | undefined>)[`targets.${priority}.${kind}`];
}

const policyGeneralError = computed(() => (policyForm.errors as Record<string, string | undefined>).targets);

// ---- Calendars -----------------------------------------------------------
const calendarOpen = ref(false);
const editingCalendar = ref<CalendarRow | null>(null);

interface Window { start: string; end: string }
const calendarForm = useForm({
    name: '',
    timezone: '',
    is_active: true,
    holidays: '',
    hours: {} as Record<string, Window[]>,
});

function openCalendar(calendar: CalendarRow | null): void {
    editingCalendar.value = calendar;
    const hours: Record<string, Window[]> = {};

    for (const day of WEEKDAYS) {
        hours[day] = (calendar?.hours?.[day] ?? []).map(([start, end]) => ({ start, end }));
    }

    if (calendar === null) {
        for (const day of ['1', '2', '3', '4', '5']) {
            hours[day] = [{ start: '09:00', end: '17:00' }];
        }
    }

    calendarForm.defaults({
        name: calendar?.name ?? '',
        timezone: calendar?.timezone ?? '',
        is_active: calendar?.isActive ?? true,
        holidays: (calendar?.holidays ?? []).join('\n'),
        hours,
    }).reset();
    calendarForm.clearErrors();
    calendarOpen.value = true;
}

function addWindow(day: string): void {
    if ((calendarForm.hours[day]?.length ?? 0) < 6) {
        calendarForm.hours[day] = [...(calendarForm.hours[day] ?? []), { start: '09:00', end: '17:00' }];
    }
}

function removeWindow(day: string, index: number): void {
    calendarForm.hours[day] = (calendarForm.hours[day] ?? []).filter((_, position) => position !== index);
}

const invalidWindow = computed(() =>
    WEEKDAYS.some((day) =>
        (calendarForm.hours[day] ?? []).some((window) => {
            const start = clockMinutes(window.start);
            const end = clockMinutes(window.end);

            return start === null || end === null || end <= start;
        }),
    ),
);

function saveCalendar(): void {
    calendarForm.transform((data) => {
        const hours: Record<string, [string, string][]> = {};

        for (const day of WEEKDAYS) {
            const windows = data.hours[day] ?? [];

            if (windows.length > 0) {
                hours[day] = windows.map((window) => [window.start.trim(), window.end.trim()]);
            }
        }

        return {
            name: data.name,
            timezone: data.timezone.trim() === '' ? null : data.timezone.trim(),
            is_active: data.is_active,
            hours,
            holidays: data.holidays.split(/\s+/).filter((line) => line !== ''),
        };
    });
    const options = { preserveScroll: true, onSuccess: () => { calendarOpen.value = false; } };

    if (editingCalendar.value) {
        calendarForm.put(route('support.sla.calendars.update', { calendar: editingCalendar.value.id }), options);
    } else {
        calendarForm.post(route('support.sla.calendars.store'), options);
    }
}

const calendarErrors = computed(() =>
    Object.entries(calendarForm.errors)
        .filter(([key]) => key === 'hours' || key.startsWith('hours.') || key === 'holidays' || key.startsWith('holidays.'))
        .map(([, message]) => message),
);

function summariseHours(calendar: CalendarRow): string[] {
    return WEEKDAYS.filter((day) => (calendar.hours?.[day] ?? []).length > 0).map(
        (day) => `${t(`support.sla.weekdayShort.${day}`)} ${(calendar.hours[day] ?? []).map(([start, end]) => `${start}-${end}`).join(', ')}`,
    );
}

function summariseTargets(policy: PolicyRow, priority: TicketPriority): string {
    const target = policy.targets?.[priority];
    const first = target?.first_response;
    const resolution = target?.resolution;

    return t('support.sla.targetLine', {
        first: first === null || first === undefined ? t('support.common.none') : duration(first),
        resolution: resolution === null || resolution === undefined ? t('support.common.none') : duration(resolution),
    });
}

const calendarName = (id: string | null): string => props.calendars.find((calendar) => calendar.id === id)?.name ?? t('support.sla.aroundTheClock');
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.sla.pageTitle')" :description="t('support.sla.pageDescription')" />
    <SupportNav current="sla" />

    <div class="grid gap-3 xl:grid-cols-2">
        <Card :title="t('support.sla.policies')" :description="t('support.sla.policiesHint')" flush>
            <template #actions>
                <Button size="sm" variant="primary" @click="openPolicy(null)">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('support.sla.addPolicy') }}
                </Button>
            </template>
            <EmptyState v-if="policies.length === 0" :title="t('support.sla.noPolicies')" :description="t('support.sla.noPoliciesBody')" compact>
                <template #icon><Timer /></template>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="policy in policies" :key="policy.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                {{ policy.name }}
                                <Badge v-if="!policy.isActive" tone="neutral">{{ t('support.common.inactive') }}</Badge>
                            </p>
                            <p class="text-xs text-ink-subtle">
                                {{ calendarName(policy.calendarId) }}
                                <span aria-hidden="true"> · </span>
                                {{ t(policy.pauseOnWaiting ? 'support.sla.pausesOnWaiting' : 'support.sla.keepsRunning') }}
                            </p>
                        </div>
                        <Button icon size="sm" variant="ghost" :aria-label="t('support.queues.editFor', { name: policy.name })" @click="openPolicy(policy)">
                            <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        </Button>
                    </div>
                    <dl class="mt-3 grid gap-1 text-sm">
                        <div v-for="priority in priorities" :key="priority" class="flex flex-wrap justify-between gap-2">
                            <dt class="text-ink-muted">{{ t(`support.priority.${priority}`) }}</dt>
                            <dd class="tabular text-ink">{{ summariseTargets(policy, priority) }}</dd>
                        </div>
                    </dl>
                </li>
            </ul>
        </Card>

        <Card :title="t('support.sla.calendars')" :description="t('support.sla.calendarsHint', { timezone: workspaceTimezone })" flush>
            <template #actions>
                <Button size="sm" variant="primary" @click="openCalendar(null)">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('support.sla.addCalendar') }}
                </Button>
            </template>
            <EmptyState v-if="calendars.length === 0" :title="t('support.sla.noCalendars')" :description="t('support.sla.noCalendarsBody')" compact>
                <template #icon><CalendarClock /></template>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="calendar in calendars" :key="calendar.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                {{ calendar.name }}
                                <Badge v-if="!calendar.isActive" tone="neutral">{{ t('support.common.inactive') }}</Badge>
                            </p>
                            <p class="text-xs text-ink-subtle">{{ calendar.timezone ?? workspaceTimezone }}</p>
                        </div>
                        <Button icon size="sm" variant="ghost" :aria-label="t('support.queues.editFor', { name: calendar.name })" @click="openCalendar(calendar)">
                            <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        </Button>
                    </div>
                    <ul class="tabular mt-3 grid gap-1 text-sm text-ink">
                        <li v-for="line in summariseHours(calendar)" :key="line">{{ line }}</li>
                    </ul>
                    <p v-if="calendar.holidays.length" class="mt-2 text-xs text-ink-subtle">
                        {{ t('support.sla.holidayCount', { count: format.number(calendar.holidays.length) }) }}
                    </p>
                </li>
            </ul>
        </Card>
    </div>

    <Modal v-model:open="policyOpen" size="lg" :title="t(editingPolicy ? 'support.sla.editPolicy' : 'support.sla.addPolicy')" :persistent="policyForm.processing">
        <form id="support-policy-form" class="grid gap-4" @submit.prevent="savePolicy">
            <Input v-model="policyForm.name" :label="t('support.fields.name')" :error="policyForm.errors.name" required maxlength="120" />
            <Select v-model="policyForm.calendar_id" :options="calendarOptions" :label="t('support.sla.calendar')" :error="policyForm.errors.calendar_id" />
            <fieldset class="grid gap-3">
                <legend class="text-sm font-medium text-ink">{{ t('support.sla.targetsTitle') }}</legend>
                <p class="text-xs text-ink-subtle">{{ t('support.sla.targetsHint', { max: format.number(maxMinutes) }) }}</p>
                <div v-for="priority in priorities" :key="priority" class="grid gap-3 sm:grid-cols-[6rem_1fr_1fr] sm:items-end">
                    <p class="text-sm font-medium text-ink">{{ t(`support.priority.${priority}`) }}</p>
                    <Input
                        v-model="policyForm.targets[targetKey(priority, 'first_response')]"
                        type="number"
                        min="0"
                        :max="maxMinutes"
                        :label="t('support.sla.firstResponseMinutes')"
                        :error="policyError(priority, 'first_response')"
                        optional
                    />
                    <Input
                        v-model="policyForm.targets[targetKey(priority, 'resolution')]"
                        type="number"
                        min="0"
                        :max="maxMinutes"
                        :label="t('support.sla.resolutionMinutes')"
                        :error="policyError(priority, 'resolution')"
                        optional
                    />
                </div>
                <p v-if="policyGeneralError" class="text-sm text-danger" role="alert">{{ policyGeneralError }}</p>
            </fieldset>
            <Checkbox v-model="policyForm.pause_on_waiting" :label="t('support.sla.pauseOnWaiting')" :description="t('support.sla.pauseOnWaitingHint')" />
            <Checkbox v-model="policyForm.is_active" :label="t('support.sla.policyActive')" />
            <p class="text-xs text-ink-subtle">{{ t('support.sla.appliesLater') }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="policyForm.processing" @click="policyOpen = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-policy-form" type="submit" variant="primary" :loading="policyForm.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="calendarOpen" size="lg" :title="t(editingCalendar ? 'support.sla.editCalendar' : 'support.sla.addCalendar')" :persistent="calendarForm.processing">
        <form id="support-calendar-form" class="grid gap-4" @submit.prevent="saveCalendar">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="calendarForm.name" :label="t('support.fields.name')" :error="calendarForm.errors.name" required maxlength="120" />
                <Input v-model="calendarForm.timezone" :label="t('support.sla.timezone')" :hint="t('support.sla.timezoneHint', { timezone: workspaceTimezone })" :error="calendarForm.errors.timezone" optional maxlength="64" mono />
            </div>
            <fieldset class="grid gap-3">
                <legend class="text-sm font-medium text-ink">{{ t('support.sla.hoursTitle') }}</legend>
                <p class="text-xs text-ink-subtle">{{ t('support.sla.hoursHint') }}</p>
                <div v-for="day in WEEKDAYS" :key="day" class="grid gap-2 rounded-control bg-hover px-3 py-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-ink">{{ t(`support.sla.weekday.${day}`) }}</span>
                        <Button size="sm" variant="ghost" :disabled="(calendarForm.hours[day]?.length ?? 0) >= 6" @click="addWindow(day)">
                            <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                            {{ t('support.sla.addWindow') }}
                        </Button>
                    </div>
                    <p v-if="(calendarForm.hours[day]?.length ?? 0) === 0" class="text-xs text-ink-subtle">{{ t('support.sla.closed') }}</p>
                    <div v-for="(window, index) in calendarForm.hours[day]" :key="index" class="flex flex-wrap items-end gap-2">
                        <Input v-model="window.start" class="w-28" :label="t('support.sla.from')" placeholder="09:00" maxlength="5" mono />
                        <Input v-model="window.end" class="w-28" :label="t('support.sla.to')" placeholder="17:00" maxlength="5" mono />
                        <Button icon size="sm" variant="ghost" :aria-label="t('support.sla.removeWindow', { day: t(`support.sla.weekday.${day}`) })" @click="removeWindow(day, index)">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        </Button>
                    </div>
                </div>
                <p v-if="invalidWindow" class="text-sm text-danger" role="alert">{{ t('support.sla.invalidWindow') }}</p>
            </fieldset>
            <Textarea v-model="calendarForm.holidays" :rows="4" :label="t('support.sla.holidays')" :hint="t('support.sla.holidaysHint')" optional />
            <ul v-if="calendarErrors.length" class="grid gap-1 text-sm text-danger" role="alert">
                <li v-for="message in calendarErrors" :key="message">{{ message }}</li>
            </ul>
            <Checkbox v-model="calendarForm.is_active" :label="t('support.sla.calendarActive')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="calendarForm.processing" @click="calendarOpen = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-calendar-form" type="submit" variant="primary" :loading="calendarForm.processing" :disabled="invalidWindow">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>
</template>
