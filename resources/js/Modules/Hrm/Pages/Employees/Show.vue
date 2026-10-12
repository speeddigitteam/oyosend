<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Pencil, Repeat } from 'lucide-vue-next';
import { TabsContent, TabsRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import EmployeeAssetsPanel from '../../Components/EmployeeAssetsPanel.vue';
import EmployeeContactsPanel from '../../Components/EmployeeContactsPanel.vue';
import EmployeeDocumentsPanel from '../../Components/EmployeeDocumentsPanel.vue';
import EmployeeForm from '../../Components/EmployeeForm.vue';
import EmployeeHistoryPanel from '../../Components/EmployeeHistoryPanel.vue';
import HrmStatusBadge from '../../Components/HrmStatusBadge.vue';
import HrmTabList from '../../Components/HrmTabList.vue';
import { useHrmFormat } from '../../Components/hrmFormat';
import StatusChangeDialog from '../../Components/StatusChangeDialog.vue';
import type {
    EmergencyContact,
    EmployeeAsset,
    EmployeeDetail,
    EmployeeDocument,
    EmployeeFormOptions,
    EmployeeStatus,
    HistoryEntry,
    PersonOption,
    TerminationType,
} from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    employee: EmployeeDetail;
    reports: PersonOption[];
    history: HistoryEntry[];
    emergencyContacts: EmergencyContact[];
    documents: EmployeeDocument[];
    assets: EmployeeAsset[];
    abilities: { edit: boolean; editEmployment: boolean; changeStatus: boolean; viewPersonal: boolean; manageRecords: boolean; isSelf: boolean };
    statusOptions: EmployeeStatus[];
    terminationTypes: TerminationType[];
    form: EmployeeFormOptions | null;
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();
const { can } = useAccess();

const tab = ref('overview');
const editing = ref(false);
const statusOpen = ref(false);

/** Today in the workspace time zone, for form defaults. */
const today = computed(() => hrm.today());

const tabs = computed(() => {
    const items = [
        { value: 'overview', label: t('hrm.employee.tabs.overview') },
        { value: 'history', label: t('hrm.employee.tabs.history') },
    ];

    if (props.abilities.viewPersonal) {
        items.push(
            { value: 'contacts', label: t('hrm.employee.tabs.contacts') },
            { value: 'documents', label: t('hrm.employee.tabs.documents') },
        );
    }

    items.push({ value: 'assets', label: t('hrm.employee.tabs.assets') });

    return items;
});

const initials = computed(() => `${props.employee.firstName.charAt(0)}${props.employee.lastName?.charAt(0) ?? ''}`.toUpperCase());
const canChangeStatus = computed(() => props.abilities.changeStatus && props.statusOptions.length > 0);
const ended = computed(() => props.employee.status === 'terminated');

function dash(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? t('hrm.common.notSet') : value;
}

function date(value: string | null): string {
    return value === null ? t('hrm.common.notSet') : format.date(value);
}
</script>

<template>
    <PageHeader
        :eyebrow="employee.number"
        :title="employee.name"
        :description="[employee.designation?.name, employee.department?.name].filter(Boolean).join(' - ') || undefined"
    >
        <template #actions>
            <Button v-if="can('hrm.attendance.view')" variant="secondary" :href="route('hrm.attendance.employee', { employee: employee.id })">
                <template #icon><CalendarDays class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.employee.attendance') }}
            </Button>
            <Button v-if="canChangeStatus" variant="secondary" @click="statusOpen = true">
                <template #icon><Repeat class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.status.change.action') }}
            </Button>
            <Button v-if="abilities.edit && form" variant="primary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.common.edit') }}
            </Button>
        </template>
    </PageHeader>

    <Card class="mb-3">
        <div class="flex flex-wrap items-center gap-4">
            <Avatar :initials="initials" :name="employee.name" size="lg" />
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-6 gap-y-2">
                <HrmStatusBadge kind="employee" :value="employee.status" />
                <span class="text-sm text-ink-muted">{{ t(`hrm.employmentType.${employee.employmentType}`) }}</span>
                <span v-if="employee.managerName" class="text-sm text-ink-muted">{{ t('hrm.employee.reportsTo', { name: employee.managerName }) }}</span>
                <span class="tabular text-sm text-ink-muted">{{ t('hrm.employee.joined', { date: format.date(employee.joinedOn) }) }}</span>
            </div>
        </div>
        <p v-if="ended && employee.terminationType" class="mt-3 text-sm text-ink-muted" data-testid="termination-note">
            {{ t('hrm.employee.endedNote', { type: t(`hrm.termination.${employee.terminationType}`), date: date(employee.lastWorkingOn) }) }}
        </p>
        <p v-if="abilities.isSelf" class="mt-3 text-xs text-ink-subtle">{{ t('hrm.employee.selfNote') }}</p>
    </Card>

    <TabsRoot v-model="tab" class="min-w-0">
        <HrmTabList :label="t('hrm.employee.tabsLabel')" :items="tabs" />

        <TabsContent value="overview" class="grid gap-3 lg:grid-cols-2">
            <Card :title="t('hrm.employee.organisation')">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div><dt class="eyebrow">{{ t('hrm.fields.designation') }}</dt><dd class="mt-1 text-ink">{{ dash(employee.designation?.name) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.department') }}</dt><dd class="mt-1 text-ink">{{ dash(employee.department?.name) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.branch') }}</dt><dd class="mt-1 text-ink">{{ dash(employee.branch?.name) }}</dd></div>
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.manager') }}</dt>
                        <dd class="mt-1 text-ink">
                            <Link v-if="employee.managerId && can('hrm.employees.view')" :href="route('hrm.employees.show', { employee: employee.managerId })" class="hover:text-brand-text">{{ employee.managerName }}</Link>
                            <template v-else>{{ dash(employee.managerName) }}</template>
                        </dd>
                    </div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.workEmail') }}</dt><dd class="mt-1 break-words text-ink">{{ dash(employee.workEmail) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.phone') }}</dt><dd class="tabular mt-1 text-ink">{{ dash(employee.phone) }}</dd></div>
                </dl>
                <div v-if="reports.length > 0" class="mt-4 border-t border-line pt-3">
                    <p class="eyebrow mb-2">{{ t('hrm.employee.directReports') }}</p>
                    <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <li v-for="report in reports" :key="report.id">
                            <Link :href="route('hrm.employees.show', { employee: report.id })" class="text-ink hover:text-brand-text">{{ report.name }}</Link>
                        </li>
                    </ul>
                </div>
            </Card>

            <Card :title="t('hrm.employee.employment')">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div><dt class="eyebrow">{{ t('hrm.fields.joinedOn') }}</dt><dd class="tabular mt-1 text-ink">{{ format.date(employee.joinedOn) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.employmentType') }}</dt><dd class="mt-1 text-ink">{{ t(`hrm.employmentType.${employee.employmentType}`) }}</dd></div>
                    <div v-if="employee.probationEndsOn"><dt class="eyebrow">{{ t('hrm.fields.probationEndsOn') }}</dt><dd class="tabular mt-1 text-ink">{{ format.date(employee.probationEndsOn) }}</dd></div>
                    <div v-if="employee.confirmedOn"><dt class="eyebrow">{{ t('hrm.fields.confirmedOn') }}</dt><dd class="tabular mt-1 text-ink">{{ format.date(employee.confirmedOn) }}</dd></div>
                    <div v-if="employee.statusChangedOn"><dt class="eyebrow">{{ t('hrm.fields.statusChangedOn') }}</dt><dd class="tabular mt-1 text-ink">{{ format.date(employee.statusChangedOn) }}</dd></div>
                    <div v-if="employee.statusReason" class="col-span-2"><dt class="eyebrow">{{ t('hrm.fields.reason') }}</dt><dd class="mt-1 text-ink">{{ employee.statusReason }}</dd></div>
                    <div v-if="ended && employee.terminationReason" class="col-span-2"><dt class="eyebrow">{{ t('hrm.fields.terminationReason') }}</dt><dd class="mt-1 text-ink">{{ employee.terminationReason }}</dd></div>
                    <div>
                        <dt class="eyebrow">{{ t('hrm.fields.account') }}</dt>
                        <dd class="mt-1 text-ink">
                            <template v-if="employee.membership">{{ employee.membership.name }}<span class="block text-xs text-ink-subtle">{{ employee.membership.email }}</span></template>
                            <template v-else>{{ t('hrm.employee.noAccount') }}</template>
                        </dd>
                    </div>
                    <div v-if="employee.costRateAmount !== null && employee.costCurrency">
                        <dt class="eyebrow">{{ t('hrm.fields.costRateShort') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ t('hrm.employee.perHour', { amount: format.money(employee.costRateAmount, employee.costCurrency) }) }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="employee.personal" :title="t('hrm.employee.personal')" :description="t('hrm.employee.personalHint')" data-testid="personal-block">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div><dt class="eyebrow">{{ t('hrm.fields.personalEmail') }}</dt><dd class="mt-1 break-words text-ink">{{ dash(employee.personal.personalEmail) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.dateOfBirth') }}</dt><dd class="tabular mt-1 text-ink">{{ date(employee.personal.dateOfBirth) }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.gender') }}</dt><dd class="mt-1 text-ink">{{ employee.personal.gender ? t(`hrm.gender.${employee.personal.gender}`) : t('hrm.common.notSet') }}</dd></div>
                    <div><dt class="eyebrow">{{ t('hrm.fields.nationalId') }}</dt><dd class="mt-1 font-mono text-ink">{{ dash(employee.personal.nationalId) }}</dd></div>
                    <div class="col-span-2"><dt class="eyebrow">{{ t('hrm.fields.address') }}</dt><dd class="mt-1 text-ink">{{ [employee.personal.address, employee.personal.city, employee.personal.country].filter(Boolean).join(', ') || t('hrm.common.notSet') }}</dd></div>
                </dl>
            </Card>

            <Card v-if="employee.notes" :title="t('hrm.fields.notes')">
                <p class="text-sm whitespace-pre-line text-ink">{{ employee.notes }}</p>
            </Card>
        </TabsContent>

        <TabsContent value="history">
            <Card :title="t('hrm.employee.tabs.history')" :description="t('hrm.history.hint')">
                <EmployeeHistoryPanel :history="history" :options="form" :show-cost="abilities.viewPersonal" />
            </Card>
        </TabsContent>

        <TabsContent v-if="abilities.viewPersonal" value="contacts">
            <Card :title="t('hrm.employee.tabs.contacts')">
                <EmployeeContactsPanel :employee-id="employee.id" :contacts="emergencyContacts" :can-manage="abilities.manageRecords" />
            </Card>
        </TabsContent>

        <TabsContent v-if="abilities.viewPersonal" value="documents">
            <Card :title="t('hrm.employee.tabs.documents')">
                <EmployeeDocumentsPanel :employee-id="employee.id" :documents="documents" :can-manage="abilities.manageRecords" />
            </Card>
        </TabsContent>

        <TabsContent value="assets">
            <Card :title="t('hrm.employee.tabs.assets')">
                <EmployeeAssetsPanel :employee-id="employee.id" :assets="assets" :can-manage="abilities.manageRecords" :today="today" />
            </Card>
        </TabsContent>
    </TabsRoot>

    <Modal v-if="abilities.edit && form" v-model:open="editing" size="lg" :title="t('hrm.employee.editTitle')">
        <EmployeeForm :options="form" :employee="employee" :can-edit-employment="abilities.editEmployment" @cancel="editing = false" @saved="editing = false" />
    </Modal>

    <StatusChangeDialog
        v-if="canChangeStatus"
        v-model:open="statusOpen"
        :employee-id="employee.id"
        :options="statusOptions"
        :termination-types="terminationTypes"
        :today="today"
    />
</template>
