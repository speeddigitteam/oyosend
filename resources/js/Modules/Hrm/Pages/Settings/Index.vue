<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';
import { TabsContent, TabsRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import DesignationDialog from '../../Components/DesignationDialog.vue';
import HrmTabList from '../../Components/HrmTabList.vue';
import LeavePolicyDialog from '../../Components/LeavePolicyDialog.vue';
import LeaveTypeDialog from '../../Components/LeaveTypeDialog.vue';
import ShiftDialog from '../../Components/ShiftDialog.vue';
import { WEEKDAYS, useHrmFormat } from '../../Components/hrmFormat';
import type {
    Accrual,
    ApprovalFlow,
    Designation,
    EmploymentType,
    HrmSettings,
    LeavePolicy,
    LeaveType,
    Shift,
} from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: HrmSettings;
    designations: Designation[];
    shifts: Shift[];
    leaveTypes: LeaveType[];
    leavePolicies: LeavePolicy[];
    options: { employmentTypes: EmploymentType[]; accruals: Accrual[]; approvalFlows: ApprovalFlow[] };
}>();

const { t } = useI18n();
const hrm = useHrmFormat();

const tab = ref('general');
const tabs = computed(() => ['general', 'designations', 'shifts', 'leaveTypes', 'policies'].map((value) => ({ value, label: t(`hrm.settings.tabs.${value}`) })));

const form = useForm({
    employee_prefix: props.settings.employeePrefix,
    employee_padding: String(props.settings.employeePadding),
    weekly_off_days: [...props.settings.weeklyOffDays],
    leave_year_start_month: String(props.settings.leaveYearStartMonth),
    work_day_minutes: String(props.settings.workDayMinutes),
});

const days = computed(() => WEEKDAYS.map((day) => ({ day, label: hrm.weekday(day, 'long') })));
const months = computed(() => Array.from({ length: 12 }, (_, index) => ({ value: String(index + 1), label: hrm.monthName(index + 1) })));
const example = computed(() => `${form.employee_prefix}${'1'.padStart(Number(form.employee_padding) || 1, '0')}`);

function toggleDay(day: number, on: boolean): void {
    form.weekly_off_days = on ? [...form.weekly_off_days, day].sort((a, b) => a - b) : form.weekly_off_days.filter((value) => value !== day);
}

function save(): void {
    form.transform((data) => ({
        ...data,
        employee_padding: Number(data.employee_padding),
        leave_year_start_month: Number(data.leave_year_start_month),
        work_day_minutes: Number(data.work_day_minutes),
    }) as never).put(route('hrm.settings.update'), { preserveScroll: true });
}

const designationOpen = ref(false);
const designation = ref<Designation | null>(null);
const shiftOpen = ref(false);
const shift = ref<Shift | null>(null);
const typeOpen = ref(false);
const leaveType = ref<LeaveType | null>(null);
const policyOpen = ref(false);
const policy = ref<LeavePolicy | null>(null);

const typeNames = computed(() => new Map(props.leaveTypes.map((type) => [type.id, type.name])));

const designationColumns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.name') },
    { key: 'code', label: t('hrm.fields.code'), hideable: true },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);
const shiftColumns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.name') },
    { key: 'time', label: t('hrm.fields.hours') },
    { key: 'break', label: t('hrm.fields.breakMinutes'), hideable: true },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);
const typeColumns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.name') },
    { key: 'code', label: t('hrm.fields.code'), hideable: true },
    { key: 'paid', label: t('hrm.settings.leaveTypes.paidColumn') },
    { key: 'flow', label: t('hrm.fields.approvalFlow'), hideable: true },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);
const policyColumns = computed<Column[]>(() => [
    { key: 'name', label: t('hrm.fields.name') },
    { key: 'type', label: t('hrm.fields.leaveType') },
    { key: 'annual', label: t('hrm.fields.annualDays'), align: 'right' },
    { key: 'accrual', label: t('hrm.fields.accrual'), hideable: true },
    { key: 'status', label: t('hrm.fields.status') },
    { key: 'actions', label: t('hrm.common.actions'), srOnly: true, align: 'right' },
]);

function openDesignation(item: Designation | null): void {
    designation.value = item;
    designationOpen.value = true;
}

function openShift(item: Shift | null): void {
    shift.value = item;
    shiftOpen.value = true;
}

function openType(item: LeaveType | null): void {
    leaveType.value = item;
    typeOpen.value = true;
}

function openPolicy(item: LeavePolicy | null): void {
    policy.value = item;
    policyOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.settings.title')" :description="t('hrm.settings.description')" />

    <TabsRoot v-model="tab" class="min-w-0">
        <HrmTabList :label="t('hrm.settings.tabsLabel')" :items="tabs" />

        <TabsContent value="general">
            <Card :title="t('hrm.settings.general.title')" :description="t('hrm.settings.general.description')">
                <form class="grid max-w-2xl gap-4" novalidate @submit.prevent="save">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.employee_prefix"
                            :label="t('hrm.settings.general.prefix')"
                            :hint="t('hrm.settings.general.prefixHint', { example })"
                            maxlength="20"
                            mono
                            :error="form.errors.employee_prefix"
                        />
                        <Input
                            v-model="form.employee_padding"
                            type="number"
                            min="1"
                            max="10"
                            :label="t('hrm.settings.general.padding')"
                            :error="form.errors.employee_padding"
                        />
                        <Select v-model="form.leave_year_start_month" :label="t('hrm.settings.general.leaveYearStart')" :options="months" :error="form.errors.leave_year_start_month" />
                        <Input
                            v-model="form.work_day_minutes"
                            type="number"
                            min="60"
                            max="1440"
                            :label="t('hrm.settings.general.workDayMinutes')"
                            :hint="t('hrm.settings.general.workDayHint')"
                            :error="form.errors.work_day_minutes"
                        />
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium text-ink">{{ t('hrm.fields.weeklyOff') }}</legend>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <Checkbox
                                v-for="item in days"
                                :key="item.day"
                                :model-value="form.weekly_off_days.includes(item.day)"
                                :label="item.label"
                                @update:model-value="toggleDay(item.day, $event)"
                            />
                        </div>
                        <p v-if="form.errors.weekly_off_days" class="text-sm text-danger" role="alert">{{ form.errors.weekly_off_days }}</p>
                    </fieldset>
                    <div>
                        <Button type="submit" variant="primary" :loading="form.processing">{{ t('hrm.common.saveChanges') }}</Button>
                    </div>
                </form>
            </Card>
        </TabsContent>

        <TabsContent value="designations">
            <Card flush :title="t('hrm.settings.designations.title')">
                <template #actions>
                    <Button size="sm" variant="primary" @click="openDesignation(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.settings.designations.add') }}
                    </Button>
                </template>
                <DataTable :columns="designationColumns" :rows="designations" row-key="id" :caption="t('hrm.settings.designations.title')">
                    <template #cell-name="{ row }">
                        <span class="block text-ink">{{ row.name }}</span>
                        <span v-if="row.description" class="block max-w-80 truncate text-xs text-ink-subtle">{{ row.description }}</span>
                    </template>
                    <template #cell-code="{ row }"><span class="font-mono text-ink-muted">{{ row.code ?? t('hrm.common.dash') }}</span></template>
                    <template #cell-status="{ row }"><Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('hrm.common.active') : t('hrm.common.inactive') }}</Badge></template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-end">
                            <Button size="sm" variant="ghost" @click="openDesignation(row)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                                {{ t('hrm.common.edit') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty><EmptyState :title="t('hrm.settings.designations.empty')" :description="t('hrm.settings.designations.emptyBody')" /></template>
                </DataTable>
            </Card>
        </TabsContent>

        <TabsContent value="shifts">
            <Card flush :title="t('hrm.settings.shifts.title')" :description="t('hrm.settings.shifts.description')">
                <template #actions>
                    <Button size="sm" variant="primary" @click="openShift(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.settings.shifts.add') }}
                    </Button>
                </template>
                <DataTable :columns="shiftColumns" :rows="shifts" row-key="id" :caption="t('hrm.settings.shifts.title')">
                    <template #cell-name="{ row }">
                        <span class="block text-ink">{{ row.name }}</span>
                        <Badge v-if="row.isDefault" tone="brand">{{ t('hrm.settings.shifts.default') }}</Badge>
                    </template>
                    <template #cell-time="{ row }">
                        <span class="tabular text-ink-muted">{{ hrm.clock(row.startsAt) }} - {{ hrm.clock(row.endsAt) }}</span>
                        <span v-if="row.crossesMidnight" class="block text-xs text-ink-subtle">{{ t('hrm.settings.shifts.nextDay') }}</span>
                    </template>
                    <template #cell-break="{ row }"><span class="tabular text-ink-muted">{{ hrm.duration(row.breakMinutes) }}</span></template>
                    <template #cell-status="{ row }"><Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('hrm.common.active') : t('hrm.common.inactive') }}</Badge></template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-end">
                            <Button size="sm" variant="ghost" @click="openShift(row)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                                {{ t('hrm.common.edit') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty><EmptyState :title="t('hrm.settings.shifts.empty')" :description="t('hrm.settings.shifts.emptyBody')" /></template>
                </DataTable>
            </Card>
        </TabsContent>

        <TabsContent value="leaveTypes">
            <Card flush :title="t('hrm.settings.leaveTypes.title')">
                <template #actions>
                    <Button size="sm" variant="primary" @click="openType(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.settings.leaveTypes.add') }}
                    </Button>
                </template>
                <DataTable :columns="typeColumns" :rows="leaveTypes" row-key="id" :caption="t('hrm.settings.leaveTypes.title')">
                    <template #cell-name="{ row }"><span class="text-ink">{{ row.name }}</span></template>
                    <template #cell-code="{ row }"><span class="font-mono text-ink-muted">{{ row.code }}</span></template>
                    <template #cell-paid="{ row }"><span class="text-ink-muted">{{ row.isPaid ? t('hrm.leave.paid') : t('hrm.leave.unpaid') }}</span></template>
                    <template #cell-flow="{ row }"><span class="text-ink-muted">{{ t(`hrm.flow.${row.approvalFlow}`) }}</span></template>
                    <template #cell-status="{ row }"><Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('hrm.common.active') : t('hrm.common.inactive') }}</Badge></template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-end">
                            <Button size="sm" variant="ghost" @click="openType(row)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                                {{ t('hrm.common.edit') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty><EmptyState :title="t('hrm.settings.leaveTypes.empty')" :description="t('hrm.settings.leaveTypes.emptyBody')" /></template>
                </DataTable>
            </Card>
        </TabsContent>

        <TabsContent value="policies">
            <Card flush :title="t('hrm.settings.policies.title')" :description="t('hrm.settings.policies.description')">
                <template v-if="leaveTypes.length > 0" #actions>
                    <Button size="sm" variant="primary" @click="openPolicy(null)">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.settings.policies.add') }}
                    </Button>
                </template>
                <DataTable :columns="policyColumns" :rows="leavePolicies" row-key="id" :caption="t('hrm.settings.policies.title')">
                    <template #cell-name="{ row }">
                        <span class="block text-ink">{{ row.name }}</span>
                        <span class="block text-xs text-ink-subtle">
                            {{ row.employmentTypes && row.employmentTypes.length > 0
                                ? row.employmentTypes.map((value) => t(`hrm.employmentType.${value}`)).join(', ')
                                : t('hrm.settings.policies.everyone') }}
                        </span>
                    </template>
                    <template #cell-type="{ row }"><span class="text-ink-muted">{{ typeNames.get(row.leaveTypeId) ?? t('hrm.common.dash') }}</span></template>
                    <template #cell-annual="{ row }"><span class="tabular text-ink">{{ hrm.days(row.annualUnits) }}</span></template>
                    <template #cell-accrual="{ row }"><span class="text-ink-muted">{{ t(`hrm.accrual.${row.accrual}`) }}</span></template>
                    <template #cell-status="{ row }"><Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('hrm.common.active') : t('hrm.common.inactive') }}</Badge></template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-end">
                            <Button size="sm" variant="ghost" @click="openPolicy(row)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                                {{ t('hrm.common.edit') }}
                            </Button>
                        </div>
                    </template>
                    <template #empty>
                        <EmptyState :title="t('hrm.settings.policies.empty')" :description="leaveTypes.length > 0 ? t('hrm.settings.policies.emptyBody') : t('hrm.settings.policies.needTypes')" />
                    </template>
                </DataTable>
            </Card>
        </TabsContent>
    </TabsRoot>

    <DesignationDialog v-model:open="designationOpen" :designation="designation" />
    <ShiftDialog v-model:open="shiftOpen" :shift="shift" />
    <LeaveTypeDialog v-model:open="typeOpen" :type="leaveType" :flows="options.approvalFlows" />
    <LeavePolicyDialog
        v-model:open="policyOpen"
        :policy="policy"
        :types="leaveTypes"
        :accruals="options.accruals"
        :employment-types="options.employmentTypes"
    />
</template>
