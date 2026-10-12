<script setup lang="ts">
import { Gavel } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import ProbationDecisionDialog from '../../Components/ProbationDecisionDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { ProbationDecision, ProbationRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    probation: ProbationRow;
    decisions: ProbationDecision[];
    abilities: { decide: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const deciding = ref(false);
</script>

<template>
    <PageHeader
        :eyebrow="t('talent.probations.title')"
        :title="probation.employee?.name ?? t('talent.common.dash')"
        :description="probation.employee?.number"
    >
        <template v-if="abilities.decide" #actions>
            <Button variant="primary" @click="deciding = true">
                <template #icon><Gavel class="size-4" aria-hidden="true" /></template>
                {{ t('talent.probations.decide') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <Card class="lg:col-span-5" :title="t('talent.common.details')">
            <dl class="grid gap-3 text-sm">
                <div>
                    <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                    <dd class="mt-1"><TalentBadge kind="probation" :value="probation.status" /></dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('talent.probations.started') }}</dt>
                    <dd class="mt-1 text-ink">{{ format.date(probation.startedOn) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('talent.probations.ends') }}</dt>
                    <dd class="mt-1 text-ink">
                        {{ format.date(probation.endsOn) }}
                        <span v-if="probation.status === 'in_progress' && probation.daysLeft !== null" class="text-ink-muted">
                            - {{ probation.daysLeft < 0 ? t('talent.probations.overdueBy', { days: talent.days(Math.abs(probation.daysLeft)) }) : talent.days(probation.daysLeft) }}
                        </span>
                    </dd>
                </div>
                <div v-if="probation.closedOn">
                    <dt class="eyebrow">{{ t('talent.probations.closed') }}</dt>
                    <dd class="mt-1 text-ink">{{ format.date(probation.closedOn) }}</dd>
                </div>
            </dl>
            <p class="mt-4 border-t border-line pt-3 text-xs text-ink-subtle">{{ t('talent.probations.recommendationNote') }}</p>
        </Card>

        <Card class="lg:col-span-7" :title="t('talent.probations.decisions')">
            <EmptyState v-if="probation.reviews.length === 0" :title="t('talent.probations.noDecisions')" compact />
            <ol v-else class="grid gap-3" data-testid="probation-decisions">
                <li v-for="review in probation.reviews" :key="review.id" class="glass-well grid gap-1 rounded-control p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-medium text-ink">{{ t(`talent.probations.decision.${review.decision}`) }}</span>
                        <span class="text-xs text-ink-subtle">{{ format.date(review.decidedOn) }}</span>
                    </div>
                    <p v-if="review.extendUntil" class="text-xs text-ink-muted">{{ t('talent.probations.extendedTo', { date: format.date(review.extendUntil) }) }}</p>
                    <p v-if="review.note" class="text-sm whitespace-pre-line text-ink">{{ review.note }}</p>
                </li>
            </ol>
        </Card>
    </div>

    <ProbationDecisionDialog
        v-if="abilities.decide"
        v-model:open="deciding"
        :probation-id="probation.id"
        :ends-on="probation.endsOn"
    />
</template>
