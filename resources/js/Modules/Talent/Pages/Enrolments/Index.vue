<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { BookOpenCheck, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CompleteEnrolmentDialog from '../../Components/CompleteEnrolmentDialog.vue';
import EnrolDialog from '../../Components/EnrolDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import type { Enrolment, Paginated, PersonOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    enrolments: Paginated<Enrolment>;
    filters: { status: string; course_id: string; employee_id: string; expiring: boolean };
    courses: { id: string; title: string }[];
    employees: PersonOption[];
    ownEmployeeId: string | null;
    today: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status);
const course = ref(props.filters.course_id);
const expiring = ref(props.filters.expiring);
const enrolling = ref(false);
const completing = ref<Enrolment | null>(null);
const completeOpen = ref(false);
const closing = ref<{ enrolment: Enrolment; action: 'fail' | 'cancel' } | null>(null);
const closeOpen = ref(false);

const statusOptions = computed(() => [
    { value: 'all', label: t('talent.common.all') },
    ...(['enrolled', 'completed', 'failed', 'cancelled'] as const).map((value) => ({ value, label: t(`talent.status.enrolment.${value}`) })),
]);
const courseOptions = computed(() => [{ value: '', label: t('talent.common.all') }, ...props.courses.map((item) => ({ value: item.id, label: item.title }))]);
const filtered = computed(() => status.value !== 'all' || course.value !== '' || expiring.value);

const columns = computed<Column[]>(() => [
    { key: 'course', label: t('talent.fields.course') },
    { key: 'employee', label: t('talent.fields.employee') },
    { key: 'enrolledOn', label: t('talent.enrolments.enrolledLabel'), hideable: true },
    { key: 'completion', label: t('talent.enrolments.completedLabel'), hideable: true },
    { key: 'status', label: t('talent.fields.status') },
    { key: 'actions', label: t('talent.common.actions'), srOnly: true, align: 'right' },
]);

function isExpired(enrolment: Enrolment): boolean {
    return enrolment.expiresOn !== null && enrolment.expiresOn < props.today;
}

function apply(): void {
    router.get(route('talent.enrolments.index'), {
        status: status.value === 'all' ? undefined : status.value,
        course_id: course.value || undefined,
        expiring: expiring.value ? 1 : undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'all';
    course.value = '';
    expiring.value = false;
    apply();
}

function askComplete(enrolment: Enrolment): void {
    completing.value = enrolment;
    completeOpen.value = true;
}

function askClose(enrolment: Enrolment, action: 'fail' | 'cancel'): void {
    closing.value = { enrolment, action };
    closeOpen.value = true;
}

function close(): void {
    if (closing.value === null) {
        return;
    }

    const { enrolment, action } = closing.value;

    router.post(route(action === 'fail' ? 'talent.enrolments.fail' : 'talent.enrolments.cancel', { enrolment: enrolment.id }), {}, {
        preserveScroll: true,
        onFinish: () => { closeOpen.value = false; closing.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.enrolments.title')" :description="t('talent.enrolments.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="enrolling = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.enrolments.enrol') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="status" :options="statusOptions" :label="t('talent.fields.status')" class="w-full sm:w-44" />
            <Select v-model="course" :options="courseOptions" :label="t('talent.fields.course')" class="w-full sm:w-56" />
            <Checkbox v-model="expiring" :label="t('talent.enrolments.expiringOnly')" class="pb-2" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="enrolments.data" row-key="id" :caption="t('talent.enrolments.caption')" storage-key="talent-enrolments">
            <template #cell-course="{ row }">
                <Link :href="route('talent.courses.show', { course: row.course.id })" class="text-ink hover:text-brand-text">
                    {{ row.course.title }}
                </Link>
            </template>
            <template #cell-employee="{ row }"><span class="text-ink-muted">{{ row.employee?.name ?? t('talent.common.dash') }}</span></template>
            <template #cell-enrolledOn="{ row }"><span class="text-ink-muted">{{ format.date(row.enrolledOn) }}</span></template>
            <template #cell-completion="{ row }">
                <span v-if="row.completedOn" class="text-ink-muted">{{ format.date(row.completedOn) }}</span>
                <span v-else class="text-ink-subtle">{{ t('talent.common.dash') }}</span>
                <span v-if="row.expiresOn" class="block text-xs" :class="isExpired(row) ? 'text-danger' : 'text-ink-subtle'">
                    {{ isExpired(row) ? t('talent.enrolments.expiredOn', { date: format.date(row.expiresOn) }) : t('talent.enrolments.expiresOn', { date: format.date(row.expiresOn) }) }}
                </span>
            </template>
            <template #cell-status="{ row }">
                <TalentBadge kind="enrolment" :value="row.status" />
                <Badge v-if="row.status === 'completed' && isExpired(row)" tone="danger" class="ml-1">{{ t('talent.enrolments.expired') }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canManage && row.status === 'enrolled'" class="flex justify-end gap-1">
                    <Button size="sm" variant="secondary" @click="askComplete(row)">{{ t('talent.enrolments.complete') }}</Button>
                    <Button size="sm" variant="ghost" @click="askClose(row, 'fail')">{{ t('talent.enrolments.fail') }}</Button>
                    <Button size="sm" variant="ghost" @click="askClose(row, 'cancel')">{{ t('talent.common.cancel') }}</Button>
                </div>
            </template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.enrolments.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.enrolments.emptyBody')"
                    compact
                >
                    <template #icon><BookOpenCheck /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="enrolments" />
    </Card>

    <EnrolDialog
        v-if="canManage"
        v-model:open="enrolling"
        :course-id="null"
        :courses="courses"
        :sessions="[]"
        :employees="employees"
        :own-employee-id="ownEmployeeId"
    />
    <CompleteEnrolmentDialog
        v-if="completing"
        v-model:open="completeOpen"
        :enrolment-id="completing.id"
        :today="today"
        :validity-months="completing.course.validityMonths ?? null"
    />
    <ConfirmDialog
        v-model:open="closeOpen"
        :title="closing ? t(`talent.enrolments.confirm.${closing.action}.title`) : ''"
        :description="closing ? t(`talent.enrolments.confirm.${closing.action}.body`, { name: closing.enrolment.employee?.name ?? '' }) : ''"
        :confirm-label="closing ? t(`talent.enrolments.confirm.${closing.action}.action`) : ''"
        @confirm="close"
    />
</template>
