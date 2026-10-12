<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import HrmSubNav from '../../Components/HrmSubNav.vue';
import MonthSwitcher from '../../Components/MonthSwitcher.vue';
import { monthGrid, useHrmFormat, weekday } from '../../Components/hrmFormat';
import type { LeaveStatus, PersonRef } from '../../Components/types';

defineOptions({ layout: TenantLayout });

interface CalendarRequest {
    id: string;
    employee: PersonRef;
    leaveType: { id: string; name: string; code: string };
    startsOn: string;
    endsOn: string;
    units: number;
    days: Record<string, number>;
    status: LeaveStatus;
}

const props = defineProps<{
    month: string;
    from: string;
    to: string;
    requests: CalendarRequest[];
    holidays: Record<string, string>;
    weeklyOffDays: number[];
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const grid = computed(() => monthGrid(props.month));
const off = computed(() => new Set(props.weeklyOffDays));
const headers = computed(() => [1, 2, 3, 4, 5, 6, 7].map((day) => ({ day, label: hrm.weekday(day, 'short') })));

interface Item { request: CalendarRequest; units: number }

function itemsOn(date: string): Item[] {
    return props.requests
        .filter((request) => (request.days[date] ?? 0) > 0 || (Object.keys(request.days).length === 0 && request.startsOn <= date && date <= request.endsOn))
        .map((request) => ({ request, units: request.days[date] ?? 0 }));
}

const days = computed(() => grid.value.dates.map((date) => ({
    date,
    number: format.number(Number(date.slice(8)), { useGrouping: false }),
    holiday: props.holidays[date] ?? null,
    weeklyOff: off.value.has(weekday(date)),
    items: itemsOn(date),
})));

const agenda = computed(() => days.value.filter((day) => day.items.length > 0 || day.holiday !== null));

function tone(status: LeaveStatus): string {
    return status === 'approved' ? 'bg-brand-soft text-brand-text' : 'bg-warn-soft text-warn';
}

function label(item: Item): string {
    const half = item.units === 1 ? ` (${t('hrm.session.half')})` : '';

    return `${item.request.employee.name} - ${item.request.leaveType.code}${half}`;
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.calendar.title')" :description="t('hrm.calendar.description')">
        <template #actions>
            <MonthSwitcher :month="month" name="hrm.leave.calendar" />
        </template>
    </PageHeader>
    <HrmSubNav area="leave" current="calendar" />

    <Card class="hidden sm:block" data-testid="leave-calendar">
        <div class="overflow-x-auto">
            <div class="min-w-[44rem]">
                <div class="grid grid-cols-7 gap-1 text-center" aria-hidden="true">
                    <span v-for="header in headers" :key="header.day" class="eyebrow py-1">{{ header.label }}</span>
                </div>
                <ul class="grid grid-cols-7 gap-1">
                    <li v-for="blank in grid.leading" :key="`blank-${blank}`" aria-hidden="true" />
                    <li
                        v-for="day in days"
                        :key="day.date"
                        :data-date="day.date"
                        :class="['min-h-24 min-w-0 rounded-control border border-line p-1.5', day.weeklyOff || day.holiday ? 'bg-hover' : '']"
                    >
                        <p class="tabular text-xs font-medium text-ink-muted">{{ day.number }}</p>
                        <p v-if="day.holiday" class="mt-0.5 truncate text-[0.6875rem] text-ink-muted" :title="day.holiday">{{ day.holiday }}</p>
                        <ul class="mt-1 grid gap-0.5">
                            <li v-for="item in day.items.slice(0, 3)" :key="item.request.id">
                                <Link
                                    :href="route('hrm.leave.requests.show', { leaveRequest: item.request.id })"
                                    :class="['block truncate rounded-chip px-1.5 py-0.5 text-[0.6875rem]', tone(item.request.status)]"
                                    :title="label(item)"
                                >
                                    {{ label(item) }}
                                </Link>
                            </li>
                            <li v-if="day.items.length > 3" class="text-[0.6875rem] text-ink-subtle">
                                {{ t('hrm.calendar.more', { count: format.number(day.items.length - 3) }) }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
        <p class="mt-3 text-xs text-ink-subtle">{{ t('hrm.calendar.legendLeave') }}</p>
    </Card>

    <Card class="sm:hidden" :title="t('hrm.calendar.agenda')">
        <p v-if="agenda.length === 0" class="text-sm text-ink-muted">{{ t('hrm.calendar.nobodyAway') }}</p>
        <ul v-else class="divide-y divide-line">
            <li v-for="day in agenda" :key="day.date" class="grid gap-1 py-2.5 first:pt-0 last:pb-0">
                <p class="tabular text-sm font-medium text-ink">{{ format.date(day.date) }}</p>
                <p v-if="day.holiday" class="text-xs text-ink-muted">{{ day.holiday }}</p>
                <Link
                    v-for="item in day.items"
                    :key="item.request.id"
                    :href="route('hrm.leave.requests.show', { leaveRequest: item.request.id })"
                    :class="['block rounded-chip px-2 py-1 text-xs', tone(item.request.status)]"
                >
                    {{ label(item) }}
                </Link>
            </li>
        </ul>
    </Card>
</template>
