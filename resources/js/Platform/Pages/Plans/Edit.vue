<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CircleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type { BillingInterval, PlanSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { limitDescription, limitLabel, moduleLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import FormRule from '@/Platform/Components/FormRule.vue';
import FormSection from '@/Platform/Components/FormSection.vue';
import { fractionDigits, fromMinorUnits, toMinorUnits, toWholeNumber, type ParsedAmount } from '@/Platform/Components/Plans/amount';

defineOptions({ layout: PlatformLayout });

const props = defineProps<{
    /** `null` when creating a plan. */
    plan: (PlanSummary & { subscribers: number }) | null;
    /** The optional modules of this build: what a plan can include. */
    modules: { key: string; name: string }[];
    /** Everything a plan can cap in this build. */
    limits: { key: string; kind: 'gauge' | 'metered' }[];
    /** ISO 4217 codes a plan can be priced in. */
    currencies: string[];
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

const startCurrency = props.plan?.currency ?? (props.currencies.includes(page.props.app.currency) ? page.props.app.currency : (props.currencies[0] ?? ''));

/*
 * Every field is text while it is being edited. Prices are turned into
 * integer minor units, and the counts into whole numbers, only when the form
 * is sent (see `payload()`); the names match what the server validates, so
 * its messages land on the right field.
 */
const form = useForm({
    key: props.plan?.key ?? '',
    name: props.plan?.name ?? '',
    description: props.plan?.description ?? '',
    currency: startCurrency,
    monthly_amount: fromMinorUnits(props.plan?.monthlyAmount, fractionDigits(startCurrency)),
    yearly_amount: fromMinorUnits(props.plan?.yearlyAmount, fractionDigits(startCurrency)),
    trial_days: String(props.plan?.trialDays ?? 0),
    // A module or a limit this build no longer has cannot be kept: the server would refuse it.
    modules: (props.plan?.modules ?? []).filter((key) => props.modules.some((module) => module.key === key)),
    limits: Object.fromEntries(
        props.limits.map((limit) => {
            const maximum = props.plan?.limits[limit.key];

            return [limit.key, maximum === undefined || maximum === null ? '' : String(maximum)];
        }),
    ) as Record<string, string>,
    is_public: props.plan?.isPublic ?? true,
    is_active: props.plan?.isActive ?? true,
    sort_order: String(props.plan?.sortOrder ?? 0),
});

const currencyOptions = computed<SelectOption[]>(() => props.currencies.map((code) => ({ value: code, label: code })));

/*
 * Prices. Parsed as they are typed, so the line under each field can say
 * what the entry comes to before anything is saved.
 */
const digits = computed(() => fractionDigits(form.currency));
const parsed = computed<Record<BillingInterval, ParsedAmount>>(() => ({
    monthly: toMinorUnits(form.monthly_amount, digits.value),
    yearly: toMinorUnits(form.yearly_amount, digits.value),
}));

function amountError(interval: BillingInterval): string | undefined {
    const result = parsed.value[interval];

    if (result.ok) {
        return undefined;
    }

    if (result.reason === 'range') {
        return t('platformPlans.editor.amountTooLarge');
    }

    return digits.value === 0
        ? t('platformPlans.editor.amountInvalidWhole')
        : t('platformPlans.editor.amountInvalid', { digits: format.number(digits.value) });
}

function amountHint(interval: BillingInterval): string {
    const result = parsed.value[interval];

    if (!result.ok || result.value === null) {
        return t('platformPlans.editor.notSold');
    }

    const amount = result.value === 0 ? t('billing.terms.free') : `${format.money(result.value, form.currency)} ${terms.perInterval(interval)}`;

    return t('platformPlans.editor.shownAs', { amount });
}

/** Asked for once the form has been sent without a price: a plan needs one for at least one interval. */
const attempted = ref(false);
const unpriced = computed(() => parsed.value.monthly.ok && parsed.value.yearly.ok && parsed.value.monthly.value === null && parsed.value.yearly.value === null);

function toggleModule(key: string, on: boolean): void {
    form.modules = on ? [...new Set([...form.modules, key])] : form.modules.filter((item) => item !== key);
}

// The server reports a module it does not know under `modules.0`, `modules.1` ...
const modulesError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.modules ?? Object.entries(errors).find(([key]) => key.startsWith('modules.'))?.[1];
});

function payload(): Record<string, unknown> {
    const monthly = parsed.value.monthly;
    const yearly = parsed.value.yearly;

    return {
        key: form.key.trim(),
        name: form.name.trim(),
        description: form.description.trim() === '' ? null : form.description.trim(),
        currency: form.currency,
        // Integer minor units, or null for "not sold for this interval".
        monthly_amount: monthly.ok ? monthly.value : null,
        yearly_amount: yearly.ok ? yearly.value : null,
        trial_days: toWholeNumber(form.trial_days),
        modules: form.modules,
        // Only the limits that are set; a key the plan does not name is not limited.
        limits: Object.fromEntries(
            Object.entries(form.limits)
                .map(([key, value]) => [key, toWholeNumber(value)] as const)
                .filter(([, value]) => value !== null),
        ),
        is_public: form.is_public,
        is_active: form.is_active,
        sort_order: toWholeNumber(form.sort_order),
    };
}

function submit(): void {
    attempted.value = true;

    // Nothing is sent while a price cannot be read as an amount: the field says why.
    if (!parsed.value.monthly.ok || !parsed.value.yearly.ok || unpriced.value) {
        return;
    }

    const request = form.transform(payload);

    if (props.plan) {
        request.put(route('platform.plans.update', { plan: props.plan.id }));
    } else {
        request.post(route('platform.plans.store'));
    }
}

const subscribersNote = computed(() => {
    const count = props.plan?.subscribers ?? 0;

    return t(count === 1 ? 'platformPlans.editor.subscribersOne' : 'platformPlans.editor.subscribers', { count: format.number(count) });
});
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader
            :eyebrow="t('shell.nav.plans')"
            :title="plan ? t('platformPlans.editor.editTitle', { name: plan.name }) : t('platformPlans.editor.createTitle')"
            :description="plan ? t('platformPlans.editor.editDescription') : t('platformPlans.editor.createDescription')"
        >
            <template #actions>
                <Button variant="ghost" :href="route('platform.plans.index')">
                    <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                    {{ t('platformPlans.editor.back') }}
                </Button>
            </template>
        </PageHeader>

        <form novalidate @submit.prevent="submit">
            <Card>
                <FormSection :title="t('platformPlans.editor.details.title')" :description="t('platformPlans.editor.details.description')">
                    <div class="grid content-start gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.name"
                            name="name"
                            :label="t('platformPlans.editor.name')"
                            :error="form.errors.name"
                            autocomplete="off"
                            maxlength="80"
                            required
                        />
                        <Input
                            v-model="form.key"
                            name="key"
                            mono
                            :label="t('platformPlans.editor.key')"
                            :hint="plan ? t('platformPlans.editor.keyHintEdit') : t('platformPlans.editor.keyHint')"
                            :error="form.errors.key"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            maxlength="40"
                            required
                        />
                        <div class="sm:col-span-2">
                            <Input
                                v-model="form.description"
                                name="description"
                                :label="t('platformPlans.editor.description')"
                                :hint="t('platformPlans.editor.descriptionHint')"
                                :error="form.errors.description"
                                autocomplete="off"
                                maxlength="255"
                                optional
                            />
                        </div>
                    </div>
                </FormSection>

                <FormRule />

                <FormSection :title="t('platformPlans.editor.pricing.title')" :description="t('platformPlans.editor.pricing.description')">
                    <div class="flex flex-col gap-4">
                        <div class="grid content-start gap-4 sm:grid-cols-3">
                            <Select
                                v-model="form.currency"
                                name="currency"
                                :options="currencyOptions"
                                :label="t('platformPlans.editor.currency')"
                                :error="form.errors.currency"
                            />
                            <Input
                                v-model="form.monthly_amount"
                                name="monthly_amount"
                                inputmode="decimal"
                                autocomplete="off"
                                :label="t('platformPlans.editor.monthly')"
                                :hint="amountHint('monthly')"
                                :error="amountError('monthly') ?? form.errors.monthly_amount"
                                optional
                            />
                            <Input
                                v-model="form.yearly_amount"
                                name="yearly_amount"
                                inputmode="decimal"
                                autocomplete="off"
                                :label="t('platformPlans.editor.yearly')"
                                :hint="amountHint('yearly')"
                                :error="amountError('yearly') ?? form.errors.yearly_amount"
                                optional
                            />
                        </div>

                        <p v-if="attempted && unpriced" class="flex items-start gap-2 text-[0.8125rem] text-danger" role="alert">
                            <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            {{ t('platformPlans.editor.needsPrice') }}
                        </p>

                        <div class="sm:max-w-48">
                            <Input
                                v-model="form.trial_days"
                                name="trial_days"
                                inputmode="numeric"
                                autocomplete="off"
                                :label="t('platformPlans.editor.trialDays')"
                                :hint="t('platformPlans.editor.trialDaysHint')"
                                :error="form.errors.trial_days"
                                maxlength="3"
                                required
                            />
                        </div>
                    </div>
                </FormSection>

                <FormRule />

                <FormSection :title="t('platformPlans.editor.modules.title')" :description="t('platformPlans.editor.modules.description')">
                    <p v-if="modules.length === 0" class="text-[0.8125rem] text-ink-muted">{{ t('platformPlans.editor.modules.none') }}</p>

                    <div v-else class="flex flex-col gap-3">
                        <Checkbox
                            v-for="module in modules"
                            :key="module.key"
                            :model-value="form.modules.includes(module.key)"
                            :label="moduleLabel(module.key, module.name)"
                            :description="module.key"
                            @update:model-value="toggleModule(module.key, $event)"
                        />
                        <p v-if="modulesError" class="text-[0.8125rem] text-danger" role="alert">{{ modulesError }}</p>
                    </div>
                </FormSection>

                <FormRule />

                <FormSection :title="t('platformPlans.editor.limits.title')" :description="t('platformPlans.editor.limits.description')">
                    <p v-if="limits.length === 0" class="text-[0.8125rem] text-ink-muted">{{ t('platformPlans.editor.limits.none') }}</p>

                    <div v-else class="flex flex-col gap-4">
                        <div v-for="limit in limits" :key="limit.key" class="grid gap-x-4 gap-y-1.5 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)] sm:items-start">
                            <Input
                                v-model="form.limits[limit.key]"
                                :name="`limits[${limit.key}]`"
                                inputmode="numeric"
                                autocomplete="off"
                                :label="limitLabel(limit.key)"
                                :placeholder="t('billing.terms.unlimited')"
                                maxlength="12"
                            />
                            <p class="text-[0.8125rem] text-ink-subtle sm:pt-7">
                                {{ limitDescription(limit.key) }}
                                <span class="block">{{ t(`platformPlans.editor.limits.kind.${limit.kind}`) }}</span>
                            </p>
                        </div>
                        <p v-if="form.errors.limits" class="text-[0.8125rem] text-danger" role="alert">{{ form.errors.limits }}</p>
                    </div>
                </FormSection>

                <FormRule />

                <FormSection
                    :title="t('platformPlans.editor.availability.title')"
                    :description="t('platformPlans.editor.availability.description')"
                >
                    <div class="flex flex-col gap-4">
                        <Checkbox
                            v-model="form.is_public"
                            :label="t('platformPlans.editor.isPublic')"
                            :description="t('platformPlans.editor.isPublicDescription')"
                        />
                        <Checkbox
                            v-model="form.is_active"
                            :label="t('platformPlans.editor.isActive')"
                            :description="t('platformPlans.editor.isActiveDescription')"
                        />
                        <div class="sm:max-w-48">
                            <Input
                                v-model="form.sort_order"
                                name="sort_order"
                                inputmode="numeric"
                                autocomplete="off"
                                :label="t('platformPlans.editor.sortOrder')"
                                :hint="t('platformPlans.editor.sortOrderHint')"
                                :error="form.errors.sort_order"
                                maxlength="4"
                                required
                            />
                        </div>
                    </div>
                </FormSection>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
                        <p v-if="plan" class="mr-auto text-[0.8125rem] text-ink-muted">{{ subscribersNote }}</p>
                        <Button variant="ghost" :href="route('platform.plans.index')" :disabled="form.processing">
                            {{ t('common.actions.cancel') }}
                        </Button>
                        <Button type="submit" variant="primary" :loading="form.processing" :disabled="plan !== null && !form.isDirty">
                            {{ plan ? t('common.actions.save') : t('platformPlans.editor.create') }}
                        </Button>
                    </div>
                </template>
            </Card>
        </form>
    </div>
</template>
