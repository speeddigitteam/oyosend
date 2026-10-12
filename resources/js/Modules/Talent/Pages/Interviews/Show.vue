<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarClock, ClipboardCheck, Pencil } from 'lucide-vue-next';
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
import CompleteInterviewDialog from '../../Components/CompleteInterviewDialog.vue';
import InterviewDialog from '../../Components/InterviewDialog.vue';
import NoteDialog from '../../Components/NoteDialog.vue';
import ScorecardDialog from '../../Components/ScorecardDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { InterviewMode, InterviewOutcome, InterviewRow, PersonOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    interview: InterviewRow;
    application: { id: string; status: string };
    candidate: { id: string; name: string | null };
    opening: { id: string; title: string };
    modes: InterviewMode[];
    outcomes: InterviewOutcome[];
    employees: PersonOption[];
    timezone: string;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();
const { can } = useAccess();

const editing = ref(false);
const cancelling = ref(false);
const completing = ref(false);
const noShow = ref(false);
const scoring = ref(false);

const candidateName = computed(() => props.candidate.name ?? t('talent.candidates.erased'));
const hidden = computed(() => Math.max(0, props.interview.scorecardCount - props.interview.scorecards.length));
const hasActions = computed(() => Object.values(props.interview.abilities).some((value) => value));
const canOpenApplication = computed(() => can('talent.applications.view'));
</script>

<template>
    <PageHeader :eyebrow="opening.title" :title="`${candidateName} - ${interview.roundName}`">
        <template v-if="hasActions" #actions>
            <Button v-if="interview.abilities.feedback" variant="primary" @click="scoring = true">
                <template #icon><ClipboardCheck class="size-4" aria-hidden="true" /></template>
                {{ t('talent.interviews.submitScorecard') }}
            </Button>
            <Button v-if="interview.abilities.complete" variant="secondary" @click="completing = true">{{ t('talent.interviews.complete') }}</Button>
            <Button v-if="interview.abilities.edit" variant="secondary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('talent.interviews.reschedule') }}
            </Button>
            <Button v-if="interview.abilities.cancel" variant="ghost" @click="noShow = true">{{ t('talent.interviews.noShow') }}</Button>
            <Button v-if="interview.abilities.cancel" variant="danger" @click="cancelling = true">{{ t('talent.interviews.cancel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-8">
            <Card :title="t('talent.interviews.scorecards')" :description="t('talent.interviews.blindNote')">
                <EmptyState v-if="interview.scorecards.length === 0" :title="t('talent.interviews.noScorecards')" compact>
                    <template #icon><ClipboardCheck /></template>
                </EmptyState>
                <ul v-else class="grid gap-4" data-testid="scorecards">
                    <li v-for="(card, index) in interview.scorecards" :key="index" class="glass-well grid gap-2 rounded-control p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-medium text-ink">{{ card.employee?.name ?? t('talent.common.dash') }}</span>
                            <div class="flex items-center gap-2">
                                <span class="tabular text-sm text-ink">{{ t('talent.interviews.ratingOf', { rating: format.number(card.rating), max: format.number(5) }) }}</span>
                                <TalentBadge kind="recommendation" :value="card.recommendation" />
                            </div>
                        </div>
                        <div v-if="card.strengths">
                            <p class="eyebrow">{{ t('talent.interviews.strengths') }}</p>
                            <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ card.strengths }}</p>
                        </div>
                        <div v-if="card.concerns">
                            <p class="eyebrow">{{ t('talent.interviews.concerns') }}</p>
                            <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ card.concerns }}</p>
                        </div>
                    </li>
                </ul>
                <p v-if="hidden > 0" class="mt-3 text-xs text-ink-subtle" role="status" data-testid="hidden-scorecards">
                    {{ t('talent.interviews.hiddenScorecards', { count: format.number(hidden) }) }}
                </p>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-4">
            <Card :title="t('talent.common.details')">
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-2">
                            <TalentBadge kind="interview" :value="interview.status" />
                            <TalentBadge v-if="interview.outcome" kind="outcome" :value="interview.outcome" />
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.interviews.when') }}</dt>
                        <dd class="tabular mt-1 flex items-center gap-1.5 text-ink">
                            <CalendarClock class="size-4 text-ink-subtle" aria-hidden="true" />
                            {{ format.date(interview.date) }} {{ talent.clock(interview.time) }} - {{ talent.clock(interview.endTime) }}
                        </dd>
                        <dd class="mt-0.5 text-xs text-ink-subtle">{{ interview.timezone }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.mode') }}</dt>
                        <dd class="mt-1 text-ink">{{ t(`talent.status.mode.${interview.mode}`) }}</dd>
                    </div>
                    <div v-if="interview.location">
                        <dt class="eyebrow">{{ t('talent.fields.location') }}</dt>
                        <dd class="mt-1 text-ink">{{ interview.location }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.interviews.panel') }}</dt>
                        <dd class="mt-1 text-ink">{{ interview.panel.map((person) => person.name).join(', ') }}</dd>
                    </div>
                    <div v-if="interview.notes">
                        <dt class="eyebrow">{{ t('talent.fields.note') }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-ink">{{ interview.notes }}</dd>
                    </div>
                    <div v-if="canOpenApplication">
                        <Link :href="route('talent.applications.show', { application: application.id })" class="text-brand-text hover:underline">
                            {{ t('talent.interviews.openApplication') }}
                        </Link>
                    </div>
                </dl>
            </Card>
        </div>
    </div>

    <InterviewDialog
        v-if="interview.abilities.edit"
        v-model:open="editing"
        :interview="interview"
        :employees="employees"
        :timezone="timezone"
    />
    <CompleteInterviewDialog v-if="interview.abilities.complete" v-model:open="completing" :interview-id="interview.id" />
    <ScorecardDialog v-if="interview.abilities.feedback" v-model:open="scoring" :interview-id="interview.id" />
    <NoteDialog
        v-if="interview.abilities.cancel"
        v-model:open="cancelling"
        :url="route('talent.interviews.cancel', { interview: interview.id })"
        :title="t('talent.interviews.cancelTitle')"
        :description="t('talent.interviews.cancelHint')"
        :submit-label="t('talent.interviews.cancel')"
        danger
    />
    <NoteDialog
        v-if="interview.abilities.cancel"
        v-model:open="noShow"
        :url="route('talent.interviews.no-show', { interview: interview.id })"
        :title="t('talent.interviews.noShowTitle')"
        :description="t('talent.interviews.noShowHint')"
        :submit-label="t('talent.interviews.noShow')"
    />
</template>
