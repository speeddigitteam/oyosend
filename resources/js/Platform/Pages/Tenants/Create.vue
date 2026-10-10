<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type { BillingInterval, PlanSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import FormRule from '@/Platform/Components/FormRule.vue';
import FormSection from '@/Platform/Components/FormSection.vue';
import { amountFor, intervalsOf } from '@/Platform/Components/Plans/prices';

defineOptions({ layout: PlatformLayout });

const props = defineProps<{
    /** The domain workspaces are served under: an address is `{slug}.{domain}`. */
    domain: string;
    /** Active plans, public or not. */
    plans: PlanSummary[];
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

const languages = computed<SelectOption[]>(() => Object.entries(page.props.app.locales).map(([value, label]) => ({ value, label })));

const form = useForm({
    name: '',
    slug: '',
    owner_name: '',
    owner_email: '',
    // The platform's own default, not the language this operator happens to read the panel in.
    locale: page.props.app.fallbackLocale in page.props.app.locales ? page.props.app.fallbackLocale : (Object.keys(page.props.app.locales)[0] ?? 'en'),
    plan_id: '',
    interval: 'monthly' as BillingInterval,
});

/*
 * The address follows the name until someone types in it: most workspaces
 * are named after the company, and the company's name is most of an address.
 * A name without Latin letters or digits suggests nothing.
 */
const slugEdited = ref(false);
const slugId = useId();

function suggestAddress(name: string): string {
    return name
        .normalize('NFKD')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63)
        .replace(/-+$/, '');
}

watch(
    () => form.name,
    (name) => {
        if (!slugEdited.value) {
            form.slug = suggestAddress(name);
        }
    },
);

/*
 * Plan. Optional; the interval is asked for only once a plan is chosen, and
 * only among the intervals that plan is sold for.
 */
const plan = computed(() => props.plans.find((candidate) => candidate.id === form.plan_id) ?? null);
const offered = computed(() => (plan.value ? intervalsOf(plan.value) : []));
const amount = computed(() => (plan.value ? amountFor(plan.value, form.interval) : null));

const planOptions = computed<SelectOption[]>(() => [
    { value: '', label: t('platformWorkspaces.create.noPlan') },
    ...props.plans.map((candidate) => ({
        value: candidate.id,
        label: candidate.isPublic ? candidate.name : t('platformWorkspaces.create.hiddenPlan', { name: candidate.name }),
    })),
]);

const intervalOptions = computed<Segment<BillingInterval>[]>(() =>
    offered.value.map((interval) => ({ value: interval, label: terms.interval(interval) })),
);

watch(offered, (intervals) => {
    if (intervals.length > 0 && !intervals.includes(form.interval)) {
        form.interval = intervals[0]!;
    }
});

/** What starting on the chosen plan does: the same three cases the server tells apart. */
const planEffect = computed(() => {
    if (!plan.value || amount.value === null) {
        return null;
    }

    if (amount.value === 0) {
        return t('platformWorkspaces.create.planEffect.free');
    }

    return plan.value.trialDays > 0
        ? t('platformWorkspaces.create.planEffect.trial', { days: format.number(plan.value.trialDays) })
        : t('platformWorkspaces.create.planEffect.invoiced');
});

const price = computed(() => {
    if (!plan.value || amount.value === null || amount.value === 0) {
        return undefined;
    }

    return `${format.money(amount.value, plan.value.currency)} ${terms.perInterval(form.interval)}`;
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        plan_id: data.plan_id === '' ? null : data.plan_id,
        interval: data.plan_id === '' ? null : data.interval,
    })).post(route('platform.tenants.store'));
}
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader
            :eyebrow="t('shell.nav.tenants')"
            :title="t('platformWorkspaces.create.title')"
            :description="t('platformWorkspaces.create.description')"
        >
            <template #actions>
                <Button variant="ghost" :href="route('platform.tenants.index')">
                    <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                    {{ t('platformWorkspaces.back') }}
                </Button>
            </template>
        </PageHeader>

        <form novalidate @submit.prevent="submit">
            <Card>
                <FormSection
                    :title="t('platformWorkspaces.create.workspace.title')"
                    :description="t('platformWorkspaces.create.workspace.description')"
                >
                    <div class="flex flex-col gap-4">
                        <Input
                            v-model="form.name"
                            name="name"
                            :label="t('platformWorkspaces.create.name')"
                            :error="form.errors.name"
                            autocomplete="off"
                            maxlength="255"
                            required
                        />

                        <Field
                            :for="slugId"
                            :label="t('platformWorkspaces.create.slug')"
                            :hint="t('platformWorkspaces.create.slugHint')"
                            :error="form.errors.slug"
                        >
                            <!-- The field and the rest of the address read as one line; the domain wraps under it when there is no room. -->
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <div class="min-w-40 flex-1">
                                    <Input
                                        :id="slugId"
                                        v-model="form.slug"
                                        name="slug"
                                        mono
                                        autocomplete="off"
                                        autocapitalize="none"
                                        spellcheck="false"
                                        maxlength="63"
                                        required
                                        :aria-invalid="form.errors.slug ? 'true' : undefined"
                                        :aria-describedby="form.errors.slug ? `${slugId}-error` : `${slugId}-hint`"
                                        @input="slugEdited = true"
                                    />
                                </div>
                                <span class="font-mono text-[0.8125rem] break-all text-ink-muted">.{{ domain }}</span>
                            </div>
                        </Field>
                    </div>
                </FormSection>

                <FormRule />

                <FormSection :title="t('platformWorkspaces.create.owner.title')" :description="t('platformWorkspaces.create.owner.description')">
                    <div class="grid content-start gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.owner_email"
                            type="email"
                            name="owner_email"
                            :label="t('platformWorkspaces.create.ownerEmail')"
                            :error="form.errors.owner_email"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            inputmode="email"
                            maxlength="255"
                            required
                        />
                        <Input
                            v-model="form.owner_name"
                            name="owner_name"
                            :label="t('platformWorkspaces.create.ownerName')"
                            :hint="t('platformWorkspaces.create.ownerNameHint')"
                            :error="form.errors.owner_name"
                            autocomplete="off"
                            maxlength="120"
                            required
                        />
                    </div>
                </FormSection>

                <FormRule />

                <FormSection
                    :title="t('platformWorkspaces.create.language.title')"
                    :description="t('platformWorkspaces.create.language.description')"
                >
                    <div class="sm:max-w-xs">
                        <Select
                            v-model="form.locale"
                            name="locale"
                            :options="languages"
                            :label="t('platformWorkspaces.create.locale')"
                            :error="form.errors.locale"
                        />
                    </div>
                </FormSection>

                <FormRule />

                <FormSection :title="t('platformWorkspaces.create.plan.title')" :description="t('platformWorkspaces.create.plan.description')">
                    <div class="flex flex-col gap-4">
                        <div class="sm:max-w-xs">
                            <Select
                                v-model="form.plan_id"
                                name="plan_id"
                                :options="planOptions"
                                :label="t('platformWorkspaces.create.planField')"
                                :error="form.errors.plan_id"
                                optional
                            />
                        </div>

                        <template v-if="plan">
                            <Field :label="t('platformWorkspaces.create.interval')" :hint="price" :error="form.errors.interval">
                                <div>
                                    <SegmentedControl
                                        v-model="form.interval"
                                        :options="intervalOptions"
                                        :label="t('platformWorkspaces.create.interval')"
                                    />
                                </div>
                            </Field>

                            <p v-if="planEffect" class="glass-well rounded-control px-4 py-3 text-[0.8125rem] text-ink-muted" aria-live="polite">
                                {{ planEffect }}
                            </p>
                        </template>
                    </div>
                </FormSection>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <Button variant="ghost" :href="route('platform.tenants.index')" :disabled="form.processing">
                            {{ t('common.actions.cancel') }}
                        </Button>
                        <Button type="submit" variant="primary" :loading="form.processing">
                            {{ t('platformWorkspaces.create.submit') }}
                        </Button>
                    </div>
                </template>
            </Card>
        </form>
    </div>
</template>
