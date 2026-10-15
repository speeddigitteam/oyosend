<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Hourglass } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import {
    ADDRESS_MAX_LENGTH,
    billedInterval,
    cleanAddress,
    finishAddress,
    intervalsCharged,
    priceFor,
    suffixRoom,
    suggestAddress,
    trialDaysFor,
    type BillingInterval,
    type PlanOffer,
} from '@/Core/Components/Central/signup';
import Input from '@/Core/Components/Input.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: AuthLayout });

/**
 * Signing up for a workspace. Submitting creates nothing: the server records
 * the sign-up and emails a link, and answers the same way whether or not the
 * address already has an account - so nothing here may hint at that either.
 */
const props = defineProps<{
    /** Root domain of the platform; the workspace will live at `{address}.{domain}`. */
    domain: string;
    /** The plans on offer. With none, a workspace simply starts without a plan. */
    plans: PlanOffer[];
    /** Key of the plan to start on: the one picked on the landing page, otherwise the first. */
    selectedPlan: string | null;
    /** A new workspace waits for the platform's operators to approve it. */
    needsApproval: boolean;
    /** What the website's start form was given, to begin with. */
    prefill?: { company: string; email: string };
}>();

const page = usePage();
const { t, locale } = useI18n();
const format = useFormat();

const hasPlans = computed(() => props.plans.length > 0);
const intervals = computed(() => intervalsCharged(props.plans));

function startingInterval(): BillingInterval {
    const plan = props.plans.find((offer) => offer.key === props.selectedPlan);
    const first = intervalsCharged(props.plans)[0] ?? 'monthly';

    return plan ? (billedInterval(plan, first) ?? first) : first;
}

const form = useForm({
    name: '',
    email: props.prefill?.email ?? '',
    password: '',
    password_confirmation: '',
    company: props.prefill?.company ?? '',
    slug: suggestAddress(props.prefill?.company ?? ''),
    locale: page.props.app.locale as string,
    plan: props.selectedPlan ?? '',
    interval: startingInterval(),
});

/*
 * The workspace address. Suggested from the company name until the person
 * types one of their own; emptying the field hands it back to the suggestion.
 */
const addressId = useId();
const addressEdited = ref(false);
const suffix = computed(() => `.${props.domain}`);

watch(
    () => form.company,
    (company) => {
        if (!addressEdited.value) {
            form.slug = suggestAddress(company);
        }
    },
);

/** Keeps the field to what an address can hold while it is typed, and the caret where it was. */
function onAddressInput(event: Event): void {
    // An input method is still composing; the event that ends it comes here too.
    if (event instanceof InputEvent && event.isComposing) {
        return;
    }

    const input = event.target as HTMLInputElement;
    const clean = cleanAddress(input.value);

    if (input.value !== clean) {
        const caret = cleanAddress(input.value.slice(0, input.selectionStart ?? input.value.length)).length;

        input.value = clean;
        input.setSelectionRange(caret, caret);
    }

    form.slug = clean;
    addressEdited.value = clean !== '';
}

function tidyAddress(): void {
    form.slug = finishAddress(form.slug);
}

const localeOptions = computed<SelectOption[]>(() =>
    Object.entries(page.props.app.locales).map(([value, label]) => ({ value, label })),
);

const intervalOptions = computed<Segment<BillingInterval>[]>(() =>
    intervals.value.map((value) => ({ value, label: t(`billing.terms.interval.${value}`) })),
);

/** Each plan with what it costs at the interval that is selected. */
const offers = computed(() =>
    props.plans.map((plan) => ({
        plan,
        // Null for a plan that costs nothing.
        price: priceFor(plan, form.interval),
        trialDays: trialDaysFor(plan, form.interval),
    })),
);

const planHeadingId = useId();
const planError = computed(() => form.errors.plan ?? form.errors.interval);

/** What happens about money for the plan that is selected; nothing to say for one that costs nothing. */
const paymentNote = computed(() => {
    const offer = offers.value.find(({ plan }) => plan.key === form.plan);

    if (!offer || offer.price === null) {
        return null;
    }

    return offer.trialDays > 0 ? t('register.form.trialNote') : t('register.form.invoiceNote');
});

function submit(): void {
    tidyAddress();

    form.transform((data) => ({
        ...data,
        plan: hasPlans.value ? data.plan : null,
        interval: hasPlans.value ? data.interval : null,
    })).post(route('central.register.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('register.form.title')" />

    <div class="mb-7">
        <h1 class="text-[1.75rem] text-ink">{{ t('register.form.title') }}</h1>
        <p class="mt-1 text-ink-muted">{{ t('register.form.subtitle') }}</p>
    </div>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <h2 class="eyebrow">{{ t('register.form.about') }}</h2>

        <Input
            v-model="form.name"
            name="name"
            :label="t('register.form.name')"
            :error="form.errors.name"
            autocomplete="name"
            maxlength="120"
            autofocus
            required
        />

        <Input
            v-model="form.email"
            type="email"
            name="email"
            :label="t('register.form.email')"
            :hint="t('register.form.emailHint')"
            :error="form.errors.email"
            autocomplete="username"
            inputmode="email"
            autocapitalize="none"
            spellcheck="false"
            maxlength="255"
            required
        />

        <PasswordInput
            v-model="form.password"
            name="password"
            :label="t('register.form.password')"
            :hint="t('auth.passwordRules')"
            :error="form.errors.password"
            autocomplete="new-password"
            required
        />

        <PasswordInput
            v-model="form.password_confirmation"
            name="password_confirmation"
            :label="t('register.form.passwordConfirmation')"
            :error="form.errors.password_confirmation"
            autocomplete="new-password"
            required
        />

        <h2 class="eyebrow mt-3 border-t border-line pt-5">{{ t('register.form.workspace') }}</h2>

        <Input
            v-model="form.company"
            name="company"
            :label="t('register.form.company')"
            :error="form.errors.company"
            autocomplete="organization"
            maxlength="255"
            required
        />

        <!-- Right-aligned so the name runs straight into the domain: one address. -->
        <Input
            :id="addressId"
            v-model="form.slug"
            name="slug"
            mono
            control-class="text-right"
            :style="{ paddingRight: suffixRoom(suffix) }"
            :label="t('register.form.address')"
            :hint="t('register.form.addressHint')"
            :error="form.errors.slug"
            :placeholder="t('register.form.addressPlaceholder')"
            :maxlength="ADDRESS_MAX_LENGTH"
            autocomplete="off"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            required
            @input="onAddressInput"
            @change="tidyAddress"
        >
            <template #trailing>
                <label :for="addressId" class="cursor-text pr-1.5 font-mono text-sm tracking-wide text-ink-muted select-none">
                    {{ suffix }}
                </label>
            </template>
        </Input>

        <Select
            v-model="form.locale"
            name="locale"
            :options="localeOptions"
            :label="t('register.form.locale')"
            :hint="t('register.form.localeHint')"
            :error="form.errors.locale"
            required
        />

        <template v-if="hasPlans">
            <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-line pt-5">
                <h2 :id="planHeadingId" class="eyebrow">{{ t('register.form.plan') }}</h2>
                <SegmentedControl
                    v-if="intervals.length > 1"
                    v-model="form.interval"
                    :options="intervalOptions"
                    :label="t('register.form.interval')"
                />
            </div>

            <!-- Native radios, so the arrow keys move between plans; each row is the label of its own. -->
            <div
                role="radiogroup"
                :aria-labelledby="planHeadingId"
                :aria-describedby="planError ? `${planHeadingId}-error` : undefined"
                class="-mt-1 flex flex-col gap-2"
            >
                <label
                    v-for="{ plan, price, trialDays } in offers"
                    :key="plan.key"
                    :class="[
                        'flex cursor-pointer items-start gap-3 rounded-control px-3.5 py-3 transition-colors',
                        'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand',
                        form.plan === plan.key ? 'border border-brand bg-brand-soft' : 'glass-well hover:border-line-strong',
                    ]"
                >
                    <input v-model="form.plan" type="radio" name="plan" :value="plan.key" class="sr-only" />

                    <span
                        :class="[
                            'mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded-full border transition-colors',
                            form.plan === plan.key ? 'border-brand bg-brand' : 'border-line-strong bg-control-sunken',
                        ]"
                        aria-hidden="true"
                    >
                        <span v-if="form.plan === plan.key" class="size-1.5 rounded-full bg-brand-ink" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                            <span class="font-medium wrap-break-word text-ink">{{ plan.name }}</span>

                            <span v-if="price" class="text-[0.8125rem] text-ink-muted">
                                <span class="tabular text-sm font-medium text-ink">{{ format.money(price.amount, plan.currency) }}</span>
                                {{ t(`billing.terms.perInterval.${price.interval}`) }}
                            </span>
                            <span v-else class="text-sm font-medium text-ink">{{ t('billing.terms.free') }}</span>
                        </span>

                        <span v-if="plan.description" class="mt-0.5 block text-[0.8125rem] wrap-break-word text-ink-muted">
                            {{ plan.description }}
                        </span>
                        <span v-if="trialDays > 0" class="tabular mt-0.5 block text-[0.8125rem] text-ink-muted">
                            {{ t('billing.terms.trialDays', { count: format.number(trialDays) }) }}
                        </span>
                    </span>
                </label>
            </div>

            <p v-if="planError" :id="`${planHeadingId}-error`" class="-mt-2 text-[0.8125rem] text-danger" role="alert">{{ planError }}</p>
            <p v-else-if="paymentNote" class="-mt-2 text-[0.8125rem] text-ink-subtle">{{ paymentNote }}</p>
        </template>

        <p v-if="needsApproval" class="glass-well mt-1 flex items-start gap-2.5 rounded-control px-3.5 py-3 text-[0.8125rem] text-ink-muted">
            <Hourglass class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            {{ t('register.form.approval') }}
        </p>

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ t('register.form.submit') }}
        </Button>

        <p class="text-[0.8125rem] text-ink-subtle">{{ t('register.form.next') }}</p>
    </form>

    <p class="mt-6 border-t border-line pt-4 text-[0.8125rem] text-ink-muted">
        {{ t('register.form.existing') }}
        <!-- The website's sign-in page, in the language being read: a full page load, it is not part of this app. -->
        <a :href="route(locale === 'bn' ? 'central.site.bn.signin' : 'central.site.signin')" class="font-medium text-brand-text hover:underline">{{ t('register.form.find') }}</a>
    </p>
</template>
