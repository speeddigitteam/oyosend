<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AttendanceCalendar from '../../Components/AttendanceCalendar.vue';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import ManualEntryDialog from '../../Components/ManualEntryDialog.vue';
import MonthSwitcher from '../../Components/MonthSwitcher.vue';
import ShiftAssignmentDialog from '../../Components/ShiftAssignmentDialog.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { AttendanceDay, AttendanceEntry, AttendanceTotals, EmployeeStatus, Shift, ShiftAssignment } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    employee: { id: string; number: string; name: string; status: EmployeeStatus };
    month: string;
    days: AttendanceDay[];
    entries: AttendanceEntry[];
    holidays: Record<string, string>;
    leave: Record<string, number>;
    weeklyOff: string[];
    assignments: ShiftAssignment[];
    totals: AttendanceTotals;
    shifts: Shift[];
    abilities: { manage: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();
const { can } = useAccess();

const today = computed(() => hrm.today());
const entryOpen = ref(false);
const entryDate = ref('');
const assignOpen = ref(false);
const removing = ref<AttendanceEntry | null>(null);
const busy = ref(false);

const tiles = computed(() => [
    { key: 'present', label: t('hrm.status.day.present'), value: format.number(props.totals.present) },
    { key: 'halfDay', label: t('hrm.status.day.half_day'), value: format.number(props.totals.halfDay) },
    { key: 'absent', label: t('hrm.status.day.absent'), value: format.number(props.totals.absent), accent: props.totals.absent > 0 },
    { key: 'onLeave', label: t('hrm.status.day.on_leave'), value: format.number(props.totals.onLeave) },
    { key: 'worked', label: t('hrm.fields.worked'), value: hrm.duration(props.totals.workedMinutes) },
    { key: 'late', label: t('hrm.fields.late'), value: hrm.duration(props.totals.lateMinutes) },
    { key: 'overtime', label: t('hrm.fields.overtime'), value: hrm.duration(props.totals.overtimeMinutes) },
]);

function selectable(date: string): boolean {
    return props.abilities.manage && date <= today.value;
}

function addEntry(date: string): void {
    entryDate.value = date;
    entryOpen.value = true;
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('hrm.attendance.entries.destroy', { entry: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; removing.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="employee.number" :title="employee.name" :description="t('hrm.attendance.employeeDescription')">
        <template #actions>
            <Button v-if="can('hrm.attendance.view')" variant="secondary" :href="route('hrm.attendance.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.attendance.backToDay') }}
            </Button>
            <Button v-if="abilities.manage && shifts.length > 0" variant="secondary" @click="assignOpen = true">{{ t('hrm.shiftAssignment.action') }}</Button>
            <Button v-if="abilities.manage" variant="primary" @click="addEntry(today)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.entry.add') }}
            </Button>
        </template>
    </PageHeader>

    <div class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        <StatTile v-for="tile in tiles" :key="tile.key" :label="tile.label" :value="tile.value" :accent="tile.accent" />
    </div>

    <div class="grid gap-3 lg:grid-cols-12">
        <Card class="min-w-0 lg:col-span-7" :title="t('hrm.attendance.month')">
            <template #actions>
                <MonthSwitcher :month="month" name="hrm.attendance.employee" :params="{ employee: employee.id }" />
            </template>
            <AttendanceCalendar
                :month="month"
                :days="days"
                :today="today"
                :holidays="holidays"
                :weekly-off="weeklyOff"
                :leave="leave"
                :selectable="selectable"
                detailed
                @select="addEntry"
            />
            <p v-if="abilities.manage" class="mt-3 text-xs text-ink-subtle">{{ t('hrm.attendance.selectHint') }}</p>
        </Card>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-5">
            <Card :title="t('hrm.attendance.entries')">
                <EmptyState v-if="entries.length === 0" :title="t('hrm.attendance.noEntries')" compact />
                <ul v-else class="divide-y divide-line" data-testid="entries">
                    <li v-for="entry in entries" :key="entry.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="tabular text-sm text-ink">{{ format.date(entry.date) }}</p>
                            <p class="tabular text-xs text-ink-muted">
                                {{ format.date(entry.clockInAt, 'time') }} - {{ entry.clockOutAt ? format.date(entry.clockOutAt, 'time') : t('hrm.attendance.open') }}
                            </p>
                            <p v-if="entry.note" class="text-xs text-ink-subtle">{{ entry.note }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <Badge>{{ t(`hrm.attendance.sources.${entry.source}`) }}</Badge>
                            <Button v-if="abilities.manage" size="sm" variant="ghost" @click="removing = entry">
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                                {{ t('hrm.common.remove') }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card :title="t('hrm.attendance.shifts')">
                <p v-if="assignments.length === 0" class="text-sm text-ink-muted">{{ t('hrm.attendance.defaultShift') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="assignment in assignments" :key="assignment.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <span class="text-sm text-ink">{{ assignment.shiftName }}</span>
                        <span class="tabular text-xs text-ink-muted">
                            {{ format.date(assignment.startsOn) }} -
                            {{ assignment.endsOn ? format.date(assignment.endsOn) : t('hrm.attendance.ongoing') }}
                        </span>
                    </li>
                </ul>
            </Card>
        </div>
    </div>

    <template v-if="abilities.manage">
        <ManualEntryDialog v-model:open="entryOpen" :employee="employee" :date="entryDate" />
        <ShiftAssignmentDialog v-model:open="assignOpen" :employee-id="employee.id" :shifts="shifts" :today="today" />
        <ConfirmDialog
            :open="removing !== null"
            :title="t('hrm.entry.removeTitle')"
            :description="t('hrm.entry.removeBody')"
            :confirm-label="t('hrm.common.remove')"
            :loading="busy"
            @update:open="!$event && (removing = null)"
            @confirm="remove"
        />
    </template>
</template>
