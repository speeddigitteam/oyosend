<script setup lang="ts">
import { CalendarPlus, Pencil, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import CourseDialog from '../../Components/CourseDialog.vue';
import EnrolDialog from '../../Components/EnrolDialog.vue';
import SessionDialog from '../../Components/SessionDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { Course, Enrolment, PersonOption, SessionStatus, TrainingSession } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    course: Course;
    sessions: TrainingSession[];
    enrolments: Enrolment[];
    sessionStatuses: SessionStatus[];
    timezone: string;
    abilities: { manage: boolean; enrol: boolean };
    employees: PersonOption[];
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const editing = ref(false);
const sessionOpen = ref(false);
const editingSession = ref<TrainingSession | null>(null);
const enrolling = ref(false);

const hasActions = computed(() => props.abilities.manage || props.abilities.enrol);

function openSession(session: TrainingSession | null): void {
    editingSession.value = session;
    sessionOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.courses.title')" :title="course.title" :description="course.provider ?? undefined">
        <template v-if="hasActions" #actions>
            <Button v-if="abilities.enrol" variant="primary" @click="enrolling = true">
                <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.enrolments.enrol') }}
            </Button>
            <Button v-if="abilities.manage" variant="secondary" @click="openSession(null)">
                <template #icon><CalendarPlus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.courses.addSession') }}
            </Button>
            <Button v-if="abilities.manage" variant="secondary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('talent.common.edit') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-8">
            <Card flush :title="t('talent.courses.sessions')" :description="t('talent.interviews.timezoneHint', { timezone })">
                <EmptyState v-if="sessions.length === 0" :title="t('talent.courses.noSessions')" compact class="p-5" />
                <ul v-else class="divide-y divide-line" data-testid="course-sessions">
                    <li v-for="session in sessions" :key="session.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="tabular text-sm text-ink">{{ format.date(session.date) }} {{ talent.clock(session.time) }}</p>
                            <p class="text-xs text-ink-subtle">
                                <template v-if="session.location">{{ session.location }}</template>
                                <template v-if="session.trainer"> - {{ session.trainer }}</template>
                                <template v-if="session.capacity !== null">
                                    - {{ t('talent.courses.seats', { enrolled: format.number(session.enrolled ?? 0), capacity: format.number(session.capacity) }) }}
                                </template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <TalentBadge kind="session" :value="session.status" />
                            <Button v-if="abilities.manage" size="sm" variant="ghost" @click="openSession(session)">{{ t('talent.common.edit') }}</Button>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card flush :title="t('talent.courses.enrolments')">
                <EmptyState v-if="enrolments.length === 0" :title="t('talent.courses.noEnrolments')" compact class="p-5" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="enrolment in enrolments" :key="enrolment.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ enrolment.employee?.name ?? t('talent.common.dash') }}</p>
                            <p class="text-xs text-ink-subtle">
                                {{ t('talent.enrolments.enrolledOn', { date: format.date(enrolment.enrolledOn) }) }}
                                <template v-if="enrolment.expiresOn"> - {{ t('talent.enrolments.expiresOn', { date: format.date(enrolment.expiresOn) }) }}</template>
                            </p>
                        </div>
                        <TalentBadge kind="enrolment" :value="enrolment.status" />
                    </li>
                </ul>
            </Card>
        </div>

        <Card class="min-w-0 lg:col-span-4" :title="t('talent.common.details')">
            <dl class="grid gap-3 text-sm">
                <div v-if="course.code">
                    <dt class="eyebrow">{{ t('talent.courses.code') }}</dt>
                    <dd class="mt-1 font-mono text-ink">{{ course.code }}</dd>
                </div>
                <div v-if="course.durationMinutes !== null">
                    <dt class="eyebrow">{{ t('talent.courses.duration') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ talent.duration(course.durationMinutes) }}</dd>
                </div>
                <div v-if="course.validityMonths !== null">
                    <dt class="eyebrow">{{ t('talent.courses.validity') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ t('talent.courses.months', { count: format.number(course.validityMonths) }) }}</dd>
                </div>
                <div class="flex flex-wrap gap-1">
                    <Badge v-if="course.isMandatory" tone="warn">{{ t('talent.courses.mandatoryBadge') }}</Badge>
                    <Badge v-if="course.validityMonths !== null" tone="info">{{ t('talent.courses.certification') }}</Badge>
                    <Badge v-if="!course.isActive" tone="neutral">{{ t('talent.common.inactive') }}</Badge>
                </div>
            </dl>
            <p v-if="course.description" class="mt-4 border-t border-line pt-3 text-sm whitespace-pre-line text-ink">{{ course.description }}</p>
        </Card>
    </div>

    <CourseDialog v-if="abilities.manage" v-model:open="editing" :course="course" />
    <SessionDialog
        v-if="abilities.manage"
        v-model:open="sessionOpen"
        :course-id="course.id"
        :session="editingSession"
        :timezone="timezone"
        :statuses="sessionStatuses"
        :default-duration="course.durationMinutes"
    />
    <EnrolDialog
        v-if="abilities.enrol"
        v-model:open="enrolling"
        :course-id="course.id"
        :courses="[]"
        :sessions="sessions"
        :employees="employees"
        :own-employee-id="null"
    />
</template>
