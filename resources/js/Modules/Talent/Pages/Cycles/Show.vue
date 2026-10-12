<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Play } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import TalentBadge from '../../Components/TalentBadge.vue';
import { TOTAL_WEIGHT_BP, useTalentFormat, weightsTotal } from '../../Components/talentFormat';
import type { CycleRow, ReviewFormDetail, ReviewStatus } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    cycle: CycleRow;
    form: ReviewFormDetail;
    byStatus: Partial<Record<ReviewStatus, number>>;
    abilities: { edit: boolean; launch: boolean; startCalibration: boolean; close: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();
const { can } = useAccess();

type Step = 'launch' | 'calibration' | 'close';

const confirming = ref<Step | null>(null);
const confirmOpen = ref(false);
const busy = ref(false);

const STATUSES: ReviewStatus[] = ['pending_self', 'pending_manager', 'pending_calibration', 'calibrated', 'acknowledged'];
const total = computed(() => weightsTotal(props.form.items.map((item) => item.weightBp)));
const balanced = computed(() => total.value === TOTAL_WEIGHT_BP);

function ask(step: Step): void {
    confirming.value = step;
    confirmOpen.value = true;
}

function run(): void {
    if (confirming.value === null) {
        return;
    }

    busy.value = true;
    router.post(route(`talent.cycles.${confirming.value}`, { cycle: props.cycle.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; confirmOpen.value = false; confirming.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.cycles.title')" :title="cycle.name">
        <template #actions>
            <Button v-if="abilities.launch" variant="primary" @click="ask('launch')">
                <template #icon><Play class="size-4" aria-hidden="true" /></template>
                {{ t('talent.cycles.launch') }}
            </Button>
            <Button v-if="abilities.startCalibration" variant="primary" @click="ask('calibration')">{{ t('talent.cycles.startCalibration') }}</Button>
            <Button v-if="abilities.close" variant="danger" @click="ask('close')">{{ t('talent.cycles.close') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-7">
            <Card :title="t('talent.cycles.progress')" :description="t('talent.cycles.progressHint')">
                <dl class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3" data-testid="cycle-progress">
                    <div v-for="status in STATUSES" :key="status">
                        <dt class="eyebrow">{{ t(`talent.status.review.${status}`) }}</dt>
                        <dd class="figure tabular mt-2 text-2xl text-ink">{{ format.number(byStatus[status] ?? 0) }}</dd>
                    </div>
                </dl>
                <p v-if="can('talent.reviews.view')" class="mt-4 border-t border-line pt-3 text-sm">
                    <Link :href="route('talent.reviews.index', { cycle_id: cycle.id })" class="text-brand-text hover:underline">{{ t('talent.cycles.openReviews') }}</Link>
                </p>
            </Card>

            <Card :title="t('talent.cycles.competencies')" :description="t('talent.cycles.scaleOf', { max: format.number(form.scaleMax) })">
                <ul class="divide-y divide-line" data-testid="cycle-form-items">
                    <li v-for="item in form.items" :key="item.id" class="flex items-start justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ item.name }}</p>
                            <p v-if="item.description" class="text-xs text-ink-subtle">{{ item.description }}</p>
                        </div>
                        <span class="tabular shrink-0 text-sm text-ink-muted">{{ talent.percent(item.weightBp) }}</span>
                    </li>
                </ul>
                <p class="tabular mt-3 border-t border-line pt-3 text-sm" :class="balanced ? 'text-ok' : 'text-warn'" role="status">
                    {{ t('talent.forms.total', { total: talent.percent(total) }) }}
                </p>
            </Card>
        </div>

        <Card class="min-w-0 lg:col-span-5" :title="t('talent.common.details')">
            <dl class="grid gap-3 text-sm">
                <div>
                    <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                    <dd class="mt-1"><TalentBadge kind="cycle" :value="cycle.status" /></dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('talent.cycles.period') }}</dt>
                    <dd class="tabular mt-1 text-ink">{{ format.date(cycle.startsOn) }} - {{ format.date(cycle.endsOn) }}</dd>
                </div>
                <div v-if="cycle.selfDueOn">
                    <dt class="eyebrow">{{ t('talent.cycles.selfDue') }}</dt>
                    <dd class="mt-1 text-ink">{{ format.date(cycle.selfDueOn) }}</dd>
                </div>
                <div v-if="cycle.managerDueOn">
                    <dt class="eyebrow">{{ t('talent.cycles.managerDue') }}</dt>
                    <dd class="mt-1 text-ink">{{ format.date(cycle.managerDueOn) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('talent.cycles.form') }}</dt>
                    <dd class="mt-1 text-ink">{{ form.name }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('talent.cycles.selfReview') }}</dt>
                    <dd class="mt-1 text-ink">{{ cycle.requiresSelfReview ? t('talent.common.yes') : t('talent.common.no') }}</dd>
                </div>
            </dl>
        </Card>
    </div>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="confirming ? t(`talent.cycles.confirm.${confirming}.title`) : ''"
        :description="confirming ? t(`talent.cycles.confirm.${confirming}.body`) : ''"
        :confirm-label="confirming ? t(`talent.cycles.confirm.${confirming}.action`) : ''"
        :tone="confirming === 'close' ? 'danger' : 'default'"
        :loading="busy"
        @confirm="run"
    />
</template>
