<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Plus, UserRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AttendanceCalendar from '../../Components/AttendanceCalendar.vue';
import ClockCard from '../../Components/ClockCard.vue';
import CorrectionDialog from '../../Components/CorrectionDialog.vue';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import LeaveCancelDialog from '../../Components/LeaveCancelDialog.vue';
import LeavePeriod from '../../Components/LeavePeriod.vue';
import LeaveRequestDialog from '../../Components/LeaveRequestDialog.vue';
import MonthSwitcher from '../../Components/MonthSwitcher.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import type { AttendanceDay, ClockState, Correction, EmployeeRow, Holiday, LeaveRequest, LeaveType } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    hasProfile: boolean;
    employee: (EmployeeRow & { confirmedOn: string | null; probationEndsOn: string | null }) | null;
    today?: string;
    clock?: ClockState;
    month?: string;
    days?: AttendanceDay[];
    leaveYear?: number;
    balances?: { leaveType: LeaveType; units: number }[];
    leaveTypes?: LeaveType[];
    sessions?: string[];
    requests?: LeaveRequest[];
    corrections?: Correction[];
    upcomingHolidays?: Holiday[];
    abilities?: { clock: boolean; requestLeave: boolean; requestCorrection: boolean; viewLeave: boolean; viewAttendance: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();
const { can } = useAccess();

const leaveOpen = ref(false);
const correctionOpen = ref(false);
const correctionDate = ref<string | null>(null);
const cancelling = ref<LeaveRequest | null>(null);

const today = computed(() => props.today ?? '');
const canCorrect = computed(() => props.abilities?.requestCorrection === true);

function selectable(date: string): boolean {
    return canCorrect.value && date <= today.value;
}

function correct(date: string): void {
    correctionDate.value = date;
    correctionOpen.value = true;
}

function openCorrection(): void {
    correctionDate.value = null;
    correctionOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.me.title')" :description="t('hrm.me.description')" />

    <Card v-if="!hasProfile || employee === null || !clock || !abilities">
        <EmptyState :title="t('hrm.me.noProfile')" :description="t('hrm.me.noProfileBody')">
            <template #icon><UserRound /></template>
        </EmptyState>
    </Card>

    <div v-else class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-7">
            <ClockCard :clock="clock" :can-clock="abilities.clock" />

            <Card :title="t('hrm.me.attendance')">
                <template #actions>
                    <MonthSwitcher :month="month ?? ''" name="hrm.me" />
                </template>
                <AttendanceCalendar :month="month ?? ''" :days="days ?? []" :today="today" :selectable="selectable" @select="correct" />
                <p v-if="canCorrect" class="mt-3 text-xs text-ink-subtle">{{ t('hrm.me.correctionHint') }}</p>
                <div v-if="canCorrect" class="mt-3">
                    <Button size="sm" variant="secondary" @click="openCorrection">{{ t('hrm.correction.request.action') }}</Button>
                </div>
            </Card>

            <Card v-if="(corrections ?? []).length > 0" :title="t('hrm.me.pendingCorrections')">
                <ul class="divide-y divide-line">
                    <li v-for="item in corrections" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ format.date(item.date) }}</p>
                            <p class="text-xs text-ink-subtle">{{ format.date(item.clockInAt, 'time') }} - {{ format.date(item.clockOutAt, 'time') }}</p>
                        </div>
                        <HrmStatusBadge kind="correction" :value="item.status" />
                    </li>
                </ul>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-5">
            <Card :title="t('hrm.me.balances')" :description="t('hrm.me.balancesYear', { year: format.number(leaveYear ?? 0, { useGrouping: false }) })">
                <template v-if="abilities.requestLeave" #actions>
                    <Button size="sm" variant="primary" @click="leaveOpen = true">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.leave.request.action') }}
                    </Button>
                </template>
                <EmptyState v-if="(balances ?? []).length === 0" :title="t('hrm.me.noBalances')" compact>
                    <template #icon><CalendarDays /></template>
                </EmptyState>
                <ul v-else class="divide-y divide-line" data-testid="balances">
                    <li v-for="item in balances" :key="item.leaveType.id" class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                        <span class="text-sm text-ink">{{ item.leaveType.name }}</span>
                        <span class="figure tabular text-lg text-ink" data-testid="balance-value">{{ hrm.days(item.units) }}</span>
                    </li>
                </ul>
            </Card>

            <Card v-if="abilities.viewLeave" :title="t('hrm.me.requests')">
                <template v-if="can('hrm.leave.view')" #actions>
                    <Button size="sm" variant="ghost" :href="route('hrm.leave.index')">{{ t('hrm.common.viewAll') }}</Button>
                </template>
                <EmptyState v-if="(requests ?? []).length === 0" :title="t('hrm.me.noRequests')" compact>
                    <template #icon><CalendarDays /></template>
                </EmptyState>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in requests" :key="item.id" class="grid gap-1 py-2.5 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Link :href="route('hrm.leave.requests.show', { leaveRequest: item.id })" class="text-sm text-ink hover:text-brand-text">
                                {{ item.leaveType.name }}
                            </Link>
                            <HrmStatusBadge kind="leave" :value="item.status" />
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-subtle">
                            <span>
                                <LeavePeriod :starts-on="item.startsOn" :ends-on="item.endsOn" :start-session="item.startSession" :end-session="item.endSession" />
                                <span class="mx-1" aria-hidden="true">-</span>
                                <span class="tabular">{{ hrm.days(item.units) }}</span>
                            </span>
                            <Button v-if="item.abilities?.cancel" size="sm" variant="ghost" @click="cancelling = item">{{ t('hrm.leave.cancel.action') }}</Button>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card :title="t('hrm.me.holidays')">
                <p v-if="(upcomingHolidays ?? []).length === 0" class="text-sm text-ink-muted">{{ t('hrm.me.noHolidays') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="holiday in upcomingHolidays" :key="holiday.id" class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                        <span class="text-sm text-ink">{{ holiday.name }}</span>
                        <span class="tabular text-sm text-ink-muted">{{ format.date(holiday.date) }}</span>
                    </li>
                </ul>
            </Card>

            <Card :title="t('hrm.me.employment')">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.employeeNumber') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ employee.number }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.status') }}</dt>
                        <dd class="mt-1"><HrmStatusBadge kind="employee" :value="employee.status" /></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.designation') }}</dt>
                        <dd class="mt-1 text-ink">{{ employee.designation?.name ?? t('hrm.common.notSet') }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.manager') }}</dt>
                        <dd class="mt-1 text-ink">{{ employee.managerName ?? t('hrm.common.notSet') }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.joinedOn') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.date(employee.joinedOn) }}</dd>
                    </div>
                    <div v-if="employee.status === 'probation' && employee.probationEndsOn">
                        <dt class="eyebrow">{{ t('hrm.fields.probationEndsOn') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.date(employee.probationEndsOn) }}</dd>
                    </div>
                </dl>
                <div v-if="can('hrm.employees.view')" class="mt-4">
                    <Button size="sm" variant="ghost" :href="route('hrm.employees.show', { employee: employee.id })">{{ t('hrm.me.openProfile') }}</Button>
                </div>
            </Card>
        </div>
    </div>

    <template v-if="hasProfile && abilities">
        <LeaveRequestDialog v-if="abilities.requestLeave" v-model:open="leaveOpen" :types="leaveTypes ?? []" :today="today" />
        <CorrectionDialog v-if="canCorrect" v-model:open="correctionOpen" :today="today" :window-days="31" :date="correctionDate" />
        <LeaveCancelDialog :open="cancelling !== null" :request="cancelling" @update:open="!$event && (cancelling = null)" />
    </template>
</template>
