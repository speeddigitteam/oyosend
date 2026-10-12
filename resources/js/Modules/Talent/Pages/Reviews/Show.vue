<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AssignReviewerDialog from '../../Components/AssignReviewerDialog.vue';
import CalibrateDialog from '../../Components/CalibrateDialog.vue';
import NoteDialog from '../../Components/NoteDialog.vue';
import RatingsForm from '../../Components/RatingsForm.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { CycleRow, PersonOption, RatingRow, ReviewDetail, ReviewFormDetail } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    review: ReviewDetail;
    cycle: CycleRow;
    form: ReviewFormDetail;
    ratings: RatingRow[];
    abilities: { submitSelf: boolean; submitManager: boolean; calibrate: boolean; acknowledge: boolean; assignReviewer: boolean };
    employees: PersonOption[];
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const calibrating = ref(false);
const acknowledging = ref(false);
const assigning = ref(false);

/** What the server withheld from the viewer is `null`; absent ratings are simply not rendered. */
const managerRatings = computed(() => new Map(props.ratings.filter((item) => item.party === 'manager').map((item) => [item.itemId, item])));
const selfRatings = computed(() => new Map(props.ratings.filter((item) => item.party === 'self').map((item) => [item.itemId, item])));
const showSelfColumn = computed(() => selfRatings.value.size > 0);
const showManagerColumn = computed(() => managerRatings.value.size > 0);
/** The subject sees nothing of the manager's side until the review is calibrated. */
const waitingForRelease = computed(() => props.review.status === 'pending_calibration' && props.review.managerScore === null);
const hasActions = computed(() => props.abilities.assignReviewer || props.abilities.calibrate || props.abilities.acknowledge);

function ratingText(value: RatingRow | undefined): string {
    return value === undefined ? t('talent.common.dash') : format.number(value.rating);
}
</script>

<template>
    <PageHeader :eyebrow="cycle.name" :title="review.employee?.name ?? t('talent.common.dash')" :description="review.employee?.number">
        <template v-if="hasActions" #actions>
            <Button v-if="abilities.assignReviewer" variant="secondary" @click="assigning = true">{{ t('talent.reviews.assignReviewer') }}</Button>
            <Button v-if="abilities.calibrate" variant="primary" @click="calibrating = true">{{ t('talent.reviews.calibrate') }}</Button>
            <Button v-if="abilities.acknowledge" variant="primary" @click="acknowledging = true">{{ t('talent.reviews.acknowledge') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-8">
            <Card v-if="abilities.submitSelf" :title="t('talent.reviews.selfStep')" :description="t('talent.reviews.selfStepHint')">
                <RatingsForm :review-id="review.id" party="self" :items="form.items" :scale-max="form.scaleMax" />
            </Card>
            <Card v-if="abilities.submitManager" :title="t('talent.reviews.managerStep')" :description="t('talent.reviews.managerStepHint', { name: review.employee?.name ?? '' })">
                <RatingsForm :review-id="review.id" party="manager" :items="form.items" :scale-max="form.scaleMax" />
            </Card>

            <Card :title="t('talent.reviews.ratings')" :description="t('talent.reviews.scaleOf', { max: format.number(form.scaleMax) })">
                <p v-if="waitingForRelease" class="mb-3 text-sm text-ink-muted" role="status" data-testid="withheld-note">{{ t('talent.reviews.managerSideWithheld') }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" data-testid="ratings-table">
                        <caption class="sr-only">{{ t('talent.reviews.ratings') }}</caption>
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="eyebrow py-2 pr-3">{{ t('talent.forms.competency') }}</th>
                                <th scope="col" class="eyebrow px-3 py-2 text-right">{{ t('talent.forms.weight') }}</th>
                                <th v-if="showSelfColumn" scope="col" class="eyebrow px-3 py-2 text-right">{{ t('talent.reviews.selfScore') }}</th>
                                <th v-if="showManagerColumn" scope="col" class="eyebrow py-2 pl-3 text-right">{{ t('talent.reviews.managerScore') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in form.items" :key="item.id" class="border-b border-line last:border-b-0">
                                <th scope="row" class="py-2.5 pr-3 font-normal text-ink">{{ item.name }}</th>
                                <td class="tabular px-3 py-2.5 text-right text-ink-muted">{{ talent.percent(item.weightBp) }}</td>
                                <td v-if="showSelfColumn" class="tabular px-3 py-2.5 text-right text-ink">{{ ratingText(selfRatings.get(item.id)) }}</td>
                                <td v-if="showManagerColumn" class="tabular py-2.5 pl-3 text-right text-ink">{{ ratingText(managerRatings.get(item.id)) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card v-if="review.selfComment || review.managerComment || review.calibrationNote || review.employeeComment" :title="t('talent.reviews.comments')">
                <dl class="grid gap-4 text-sm">
                    <div v-if="review.selfComment">
                        <dt class="eyebrow">{{ t('talent.reviews.selfComment') }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-ink">{{ review.selfComment }}</dd>
                    </div>
                    <div v-if="review.managerComment">
                        <dt class="eyebrow">{{ t('talent.reviews.managerComment') }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-ink">{{ review.managerComment }}</dd>
                    </div>
                    <div v-if="review.calibrationNote">
                        <dt class="eyebrow">{{ t('talent.reviews.calibrationNote') }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-ink">{{ review.calibrationNote }}</dd>
                    </div>
                    <div v-if="review.employeeComment">
                        <dt class="eyebrow">{{ t('talent.reviews.employeeComment') }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-ink">{{ review.employeeComment }}</dd>
                    </div>
                </dl>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-4">
            <Card :title="t('talent.reviews.scores')">
                <dl class="grid gap-3 text-sm" data-testid="review-scores">
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                        <dd class="mt-1"><TalentBadge kind="review" :value="review.status" /></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.reviews.selfScore') }}</dt>
                        <dd class="figure tabular mt-1 text-xl text-ink" data-testid="score-self">{{ talent.score(review.selfScore) }}</dd>
                    </div>
                    <div v-if="review.managerScore !== null">
                        <dt class="eyebrow">{{ t('talent.reviews.managerScore') }}</dt>
                        <dd class="figure tabular mt-1 text-xl text-ink" data-testid="score-manager">{{ talent.score(review.managerScore) }}</dd>
                    </div>
                    <div v-if="review.finalScore !== null">
                        <dt class="eyebrow">{{ t('talent.reviews.finalScore') }}</dt>
                        <dd class="figure tabular mt-1 text-xl text-ink" data-testid="score-final">
                            {{ talent.score(review.finalScore) }}
                            <span v-if="review.finalOverridden" class="ml-1 text-xs text-ink-subtle">{{ t('talent.reviews.overridden') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.reviewer') }}</dt>
                        <dd class="mt-1 text-ink">{{ review.reviewer?.name ?? t('talent.reviews.noReviewer') }}</dd>
                    </div>
                    <div v-if="review.calibratedAt">
                        <dt class="eyebrow">{{ t('talent.reviews.calibratedOn') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(review.calibratedAt) }}</dd>
                    </div>
                    <div v-if="review.acknowledgedAt">
                        <dt class="eyebrow">{{ t('talent.reviews.acknowledgedOn') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(review.acknowledgedAt) }}</dd>
                    </div>
                </dl>
            </Card>
        </div>
    </div>

    <CalibrateDialog
        v-if="abilities.calibrate"
        v-model:open="calibrating"
        :review-id="review.id"
        :manager-score="review.managerScore"
        :scale-max="form.scaleMax"
    />
    <NoteDialog
        v-if="abilities.acknowledge"
        v-model:open="acknowledging"
        :url="route('talent.reviews.acknowledge', { review: review.id })"
        :title="t('talent.reviews.acknowledgeTitle')"
        :description="t('talent.reviews.acknowledgeHint')"
        :submit-label="t('talent.reviews.acknowledge')"
        field="comment"
        :label="t('talent.reviews.employeeComment')"
        :maxlength="3000"
    />
    <AssignReviewerDialog
        v-if="abilities.assignReviewer"
        v-model:open="assigning"
        :review-id="review.id"
        :reviewer-id="review.reviewer?.id ?? null"
        :subject-id="review.employee?.id ?? null"
        :employees="employees"
    />
</template>
