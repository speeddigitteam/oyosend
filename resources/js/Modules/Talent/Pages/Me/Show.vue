<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CalendarClock, CircleCheck, GraduationCap, Hourglass, Star, Target, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BarMeter from '../../Components/BarMeter.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { ChecklistTask, Enrolment, Goal, InterviewRow, PersonRef, ReviewRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    hasProfile: boolean;
    employee?: PersonRef | null;
    today?: string;
    tasks?: (ChecklistTask & { checklistName: string; checklistKind: string; checklistId: string })[];
    reviews?: ReviewRow[];
    goals?: Goal[];
    enrolments?: Enrolment[];
    probation?: { endsOn: string; daysLeft: number | null } | null;
    interviews?: InterviewRow[];
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();
const { can } = useAccess();

const completing = ref<string | null>(null);

function complete(task: ChecklistTask): void {
    completing.value = task.id;
    router.post(route('talent.tasks.complete', { task: task.id }), {}, {
        preserveScroll: true,
        onFinish: () => { completing.value = null; },
    });
}

function overdue(date: string | null, today: string | undefined): boolean {
    return date !== null && today !== undefined && date < today;
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.me.title')" :description="t('talent.me.description')" />

    <Card v-if="!hasProfile">
        <EmptyState :title="t('talent.me.noProfile')" :description="t('talent.me.noProfileBody')">
            <template #icon><UserRound /></template>
        </EmptyState>
    </Card>

    <div v-else class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-7">
            <Card v-if="probation" :title="t('talent.me.probation')">
                <div class="flex flex-wrap items-center gap-3">
                    <Hourglass class="size-5 text-ink-subtle" aria-hidden="true" />
                    <p class="text-sm text-ink" data-testid="probation-summary">
                        {{ t('talent.me.probationEnds', { date: format.date(probation.endsOn) }) }}
                        <span v-if="probation.daysLeft !== null" class="text-ink-muted">- {{ talent.days(Math.max(probation.daysLeft, 0)) }}</span>
                    </p>
                </div>
            </Card>

            <Card :title="t('talent.me.tasks')" :description="t('talent.me.tasksHint')">
                <template v-if="can('talent.tasks.complete')" #actions>
                    <Button size="sm" variant="ghost" :href="route('talent.tasks.index')">{{ t('talent.common.viewAll') }}</Button>
                </template>
                <EmptyState v-if="(tasks ?? []).length === 0" :title="t('talent.me.noTasks')" compact>
                    <template #icon><CircleCheck /></template>
                </EmptyState>
                <ul v-else class="divide-y divide-line" data-testid="my-tasks">
                    <li v-for="task in tasks" :key="task.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ task.title }}</p>
                            <p class="text-xs text-ink-subtle">
                                {{ task.checklistName }}
                                <template v-if="task.dueOn">
                                    -
                                    <span :class="overdue(task.dueOn, today) ? 'text-danger' : ''">{{ t('talent.checklists.due', { date: format.date(task.dueOn) }) }}</span>
                                </template>
                            </p>
                        </div>
                        <Button v-if="can('talent.tasks.complete')" size="sm" variant="secondary" :loading="completing === task.id" @click="complete(task)">
                            {{ t('talent.checklists.completeTask') }}
                        </Button>
                    </li>
                </ul>
            </Card>

            <Card :title="t('talent.me.reviews')">
                <template v-if="can('talent.reviews.view')" #actions>
                    <Button size="sm" variant="ghost" :href="route('talent.reviews.index')">{{ t('talent.common.viewAll') }}</Button>
                </template>
                <EmptyState v-if="(reviews ?? []).length === 0" :title="t('talent.me.noReviews')" compact>
                    <template #icon><Star /></template>
                </EmptyState>
                <ul v-else class="divide-y divide-line" data-testid="my-reviews">
                    <li v-for="review in reviews" :key="review.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <component
                                :is="can('talent.reviews.view') ? Link : 'span'"
                                v-bind="can('talent.reviews.view') ? { href: route('talent.reviews.show', { review: review.id }) } : {}"
                                class="text-sm text-ink hover:text-brand-text"
                            >
                                {{ review.cycle?.name }}
                            </component>
                            <p class="text-xs text-ink-subtle">
                                {{ review.role === 'subject' ? t('talent.me.reviewOfYou') : t('talent.me.reviewByYou', { name: review.employee?.name ?? '' }) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Badge v-if="review.awaitingMe" tone="accent" dot>{{ t('talent.me.awaitingYou') }}</Badge>
                            <TalentBadge kind="review" :value="review.status" />
                        </div>
                    </li>
                </ul>
            </Card>

            <Card v-if="(interviews ?? []).length > 0" :title="t('talent.me.interviews')">
                <ul class="divide-y divide-line">
                    <li v-for="interview in interviews" :key="interview.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <component
                                :is="can('talent.interviews.view') ? Link : 'span'"
                                v-bind="can('talent.interviews.view') ? { href: route('talent.interviews.show', { interview: interview.id }) } : {}"
                                class="text-sm text-ink hover:text-brand-text"
                            >
                                {{ interview.candidate?.name ?? t('talent.candidates.erased') }} - {{ interview.roundName }}
                            </component>
                            <p class="text-xs text-ink-subtle">{{ interview.opening?.title }}</p>
                        </div>
                        <span class="tabular flex items-center gap-1.5 text-xs text-ink-muted">
                            <CalendarClock class="size-3.5" aria-hidden="true" />
                            {{ format.date(interview.date) }} {{ talent.clock(interview.time) }}
                        </span>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-5">
            <Card :title="t('talent.me.goals')">
                <template v-if="can('talent.goals.view')" #actions>
                    <Button size="sm" variant="ghost" :href="route('talent.goals.index')">{{ t('talent.common.viewAll') }}</Button>
                </template>
                <EmptyState v-if="(goals ?? []).length === 0" :title="t('talent.me.noGoals')" compact>
                    <template #icon><Target /></template>
                </EmptyState>
                <ul v-else class="grid gap-3" data-testid="my-goals">
                    <li v-for="goal in goals" :key="goal.id" class="grid gap-1.5">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <component
                                :is="can('talent.goals.view') ? Link : 'span'"
                                v-bind="can('talent.goals.view') ? { href: route('talent.goals.show', { goal: goal.id }) } : {}"
                                class="text-sm text-ink hover:text-brand-text"
                            >
                                {{ goal.title }}
                            </component>
                            <span v-if="goal.dueOn" class="text-xs text-ink-subtle">{{ t('talent.checklists.due', { date: format.date(goal.dueOn) }) }}</span>
                        </div>
                        <BarMeter :bp="goal.progressBp" :label="t('talent.goals.progressOf', { title: goal.title })" compact />
                    </li>
                </ul>
            </Card>

            <Card :title="t('talent.me.training')">
                <template v-if="can('talent.enrolments.view')" #actions>
                    <Button size="sm" variant="ghost" :href="route('talent.enrolments.index')">{{ t('talent.common.viewAll') }}</Button>
                </template>
                <EmptyState v-if="(enrolments ?? []).length === 0" :title="t('talent.me.noTraining')" compact>
                    <template #icon><GraduationCap /></template>
                </EmptyState>
                <ul v-else class="divide-y divide-line">
                    <li v-for="enrolment in enrolments" :key="enrolment.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ enrolment.course.title }}</p>
                            <p v-if="enrolment.expiresOn" class="text-xs text-ink-subtle">{{ t('talent.enrolments.expiresOn', { date: format.date(enrolment.expiresOn) }) }}</p>
                        </div>
                        <TalentBadge kind="enrolment" :value="enrolment.status" />
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
