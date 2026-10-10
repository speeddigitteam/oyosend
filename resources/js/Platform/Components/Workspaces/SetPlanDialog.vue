<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type { BillingInterval, PlanSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { amountFor, intervalsOf } from '@/Platform/Components/Plans/prices';
import { tomorrowInZone } from './dates';

/**
 * Puts a workspace on a plan at once, without an invoice: a negotiated deal,
 * complimentary access, a correction. Any active plan can be given, public
 * or not.
 *
 * Nothing is preselected: giving the plan the workspace is already on starts
 * its period again, which should be a choice and not a slip.
 */
const props = defineProps<{
    workspace: { id: string; name: string; timezone: string };
    /** Active plans, public or not. */
    plans: PlanSummary[];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

const formId = useId();
const planId = useId();

const form = useForm({ plan_id: '', interval: 'monthly' as BillingInterval, period_end: '' });

const plan = computed(() => props.plans.find((candidate) => candidate.id === form.plan_id) ?? null);
const offered = computed(() => (plan.value ? intervalsOf(plan.value) : []));
const amount = computed(() => (plan.value ? amountFor(plan.value, form.interval) : null));
const paid = computed(() => amount.value !== null && amount.value > 0);

const planOptions = computed<SelectOption[]>(() =>
    props.plans.map((candidate) => ({
        value: candidate.id,
        label: candidate.isPublic ? candidate.name : t('platformWorkspaces.setPlan.hiddenPlan', { name: candidate.name }),
    })),
);

const intervalOptions = computed<Segment<BillingInterval>[]>(() =>
    offered.value.map((interval) => ({ value: interval, label: terms.interval(interval) })),
);

/** The first day the server accepts: tomorrow, on the workspace's own clock. */
const firstDay = computed(() => tomorrowInZone(props.workspace.timezone));

// A plan is not sold for every interval: keep the choice on one it has.
watch(offered, (intervals) => {
    if (intervals.length > 0 && !intervals.includes(form.interval)) {
        form.interval = intervals[0]!;
    }
});

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    // The dialog focuses its close button by default; start on the picker instead.
    await nextTick();
    requestAnimationFrame(() => document.getElementById(planId)?.focus());
});

function submit(): void {
    if (!plan.value) {
        return;
    }

    form.transform((data) => ({
        plan_id: data.plan_id,
        interval: data.interval,
        // A free plan has no period to end.
        period_end: paid.value && data.period_end !== '' ? data.period_end : null,
    })).post(route('platform.tenants.subscription.grant', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="t('platformWorkspaces.setPlan.title', { name: workspace.name })"
        :description="t('platformWorkspaces.setPlan.description')"
        :persistent="form.processing"
    >
        <p v-if="plans.length === 0" class="text-sm text-ink-muted">{{ t('platformWorkspaces.setPlan.noPlans') }}</p>

        <form v-else :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <Select
                :id="planId"
                v-model="form.plan_id"
                name="plan_id"
                :label="t('platformWorkspaces.setPlan.plan')"
                :options="planOptions"
                :placeholder="t('platformWorkspaces.setPlan.planPlaceholder')"
                :error="form.errors.plan_id"
                required
            />

            <template v-if="plan">
                <Field
                    :label="t('platformWorkspaces.setPlan.interval')"
                    :error="form.errors.interval"
                    :hint="
                        paid && amount !== null
                            ? t('platformWorkspaces.setPlan.price', {
                                  price: `${format.money(amount, plan.currency)} ${terms.perInterval(form.interval)}`,
                              })
                            : t('platformWorkspaces.setPlan.free')
                    "
                >
                    <div>
                        <SegmentedControl
                            v-model="form.interval"
                            :options="intervalOptions"
                            :label="t('platformWorkspaces.setPlan.interval')"
                        />
                    </div>
                </Field>

                <!-- min-w-0: a date field must be allowed to get narrower than the browser would like on a phone. -->
                <div v-if="paid" class="min-w-0">
                    <Input
                        v-model="form.period_end"
                        type="date"
                        name="period_end"
                        :label="t('platformWorkspaces.setPlan.periodEnd')"
                        :hint="t('platformWorkspaces.setPlan.periodEndHint')"
                        :error="form.errors.period_end"
                        :min="firstDay"
                        optional
                    />
                </div>
            </template>
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">
                {{ plans.length === 0 ? t('common.actions.close') : t('common.actions.cancel') }}
            </Button>
            <Button v-if="plans.length > 0" type="submit" variant="primary" :form="formId" :loading="form.processing" :disabled="!plan">
                {{ t('platformWorkspaces.setPlan.submit') }}
            </Button>
        </template>
    </Modal>
</template>
