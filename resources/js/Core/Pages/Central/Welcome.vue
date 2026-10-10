<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Backdrop from '@/Core/Components/Backdrop.vue';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import { pricesOf, suffixRoom, type PlanOffer } from '@/Core/Components/Central/signup';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Logo from '@/Core/Components/Logo.vue';
import LocaleSwitch from '@/Core/Components/Shell/LocaleSwitch.vue';
import ThemeToggle from '@/Core/Components/Shell/ThemeToggle.vue';
import Toaster from '@/Core/Components/Toaster.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/**
 * The page at the root domain. No tenant is active here and nobody is signed
 * in. It sends a person to their workspace's sign-in page and, while the
 * platform accepts sign-ups, offers to create a workspace.
 *
 * It only describes what exists: with sign-up closed there is no call to
 * action other than the workspace field, and the only prices shown are the
 * plans the server sends.
 */
const props = defineProps<{
    /** Root domain of the platform; a workspace lives at `{name}.{domain}`. */
    domain: string;
    /** Someone can sign up for a workspace here (at once, or pending approval). */
    registrationOpen: boolean;
    /** The plans on offer. Always empty while sign-up is closed. */
    plans: PlanOffer[];
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({
    workspace: '',
});

const fieldId = useId();
const suffix = computed(() => `.${props.domain}`);

/** Each plan with its price - the monthly one when it has one - and any other; no price for a plan that costs nothing. */
const offers = computed(() =>
    props.plans.map((plan) => {
        const [price, ...otherPrices] = pricesOf(plan);

        return { plan, price: price ?? null, otherPrices };
    }),
);

/** Whatever was typed or pasted - a name, an address, a whole URL - down to the workspace name. */
function workspaceName(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/^[a-z][a-z0-9+.-]*:\/\//, '')
        .split(/[./?#]/, 1)
        .join('');
}

// On change (blur or Enter), not on every key: someone typing "https://..." by hand must be able to finish.
function tidy(): void {
    form.workspace = workspaceName(form.workspace);
}

function submit(): void {
    tidy();
    form.post(route('central.workspace.find'));
}

const points = ['isolation', 'languages', 'roles', 'security'] as const;
</script>

<template>
    <Head :title="t('central.welcome.title')" />
    <Backdrop variant="stage" />

    <div class="flex min-h-dvh flex-col">
        <header class="flex items-center justify-between gap-4 px-5 pt-5 sm:px-8 sm:pt-7">
            <Logo />
            <div class="flex items-center gap-1">
                <LocaleSwitch />
                <ThemeToggle />
            </div>
        </header>

        <!--
            The headline is the only text set straight on the backdrop. The top
            padding keeps it below the stage's top disc (which ends 9rem down) at
            every width; everything smaller sits on the sheet, where contrast is
            guaranteed whatever is behind it.
        -->
        <main id="main" class="mx-auto flex w-full max-w-7xl flex-1 flex-col justify-center px-5 pt-24 pb-10 sm:px-8 sm:pb-14">
            <h1
                class="max-w-[16ch] animate-rise text-[clamp(2.75rem,8vw,5.75rem)] leading-[0.96] tracking-[-0.045em] text-ink [&:lang(bn)]:leading-[1.3] [&:lang(bn)]:tracking-normal"
            >
                {{ t('central.welcome.headline') }}
            </h1>

            <div class="glass mt-8 animate-rise rounded-panel p-6 [animation-delay:90ms] sm:mt-11 sm:p-9 lg:p-10">
                <div class="grid gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:gap-14">
                    <div>
                        <p
                            class="font-display text-[1.25rem] leading-[1.35] font-medium tracking-[-0.015em] text-ink sm:text-[1.4375rem] [&:lang(bn)]:leading-[1.65] [&:lang(bn)]:tracking-normal"
                        >
                            {{ t('central.welcome.lede') }}
                        </p>

                        <Button v-if="registrationOpen" variant="primary" size="lg" class="mt-6" :href="route('central.register')">
                            {{ t('central.welcome.create') }}
                        </Button>
                    </div>

                    <form novalidate @submit.prevent="submit">
                        <h2 class="text-lg text-ink">{{ t('central.welcome.find.title') }}</h2>

                        <Field
                            class="mt-4"
                            :for="fieldId"
                            :label="t('central.welcome.find.label')"
                            :hint="t('central.welcome.find.hint', { domain })"
                            :error="form.errors.workspace"
                        >
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <div class="min-w-0 flex-1">
                                    <!-- Right-aligned so the name runs straight into the domain: one address. -->
                                    <Input
                                        :id="fieldId"
                                        v-model="form.workspace"
                                        name="workspace"
                                        mono
                                        class="h-11 text-right text-[0.9375rem]"
                                        :style="{ paddingRight: suffixRoom(suffix) }"
                                        :placeholder="t('central.welcome.find.placeholder')"
                                        :aria-invalid="form.errors.workspace ? 'true' : undefined"
                                        :aria-describedby="form.errors.workspace ? `${fieldId}-error` : `${fieldId}-hint`"
                                        inputmode="url"
                                        enterkeyhint="go"
                                        autocapitalize="none"
                                        autocorrect="off"
                                        spellcheck="false"
                                        maxlength="255"
                                        required
                                        @change="tidy"
                                    >
                                        <template #trailing>
                                            <label
                                                :for="fieldId"
                                                class="cursor-text pr-1.5 font-mono text-[0.9375rem] tracking-wide text-ink-muted select-none"
                                            >
                                                {{ suffix }}
                                            </label>
                                        </template>
                                    </Input>
                                </div>

                                <!-- One primary action on the sheet: creating a workspace when that is on offer, otherwise this. -->
                                <Button type="submit" :variant="registrationOpen ? 'secondary' : 'primary'" size="lg" :loading="form.processing">
                                    {{ t('central.welcome.find.submit') }}
                                </Button>
                            </div>
                        </Field>
                    </form>
                </div>

                <section class="mt-10 sm:mt-12" :aria-labelledby="`${fieldId}-today`">
                    <h2 :id="`${fieldId}-today`" class="eyebrow">{{ t('central.welcome.today.label') }}</h2>

                    <!-- Each statement hangs from its own rule; the gaps between the rules are the columns. -->
                    <ul class="mt-4 grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-4">
                        <li v-for="point in points" :key="point" class="border-t border-line-strong pt-4">
                            <h3 class="text-[1.0625rem] text-ink">{{ t(`central.welcome.today.${point}.title`) }}</h3>
                            <p class="mt-1.5 text-[0.8125rem] text-ink-muted">{{ t(`central.welcome.today.${point}.body`) }}</p>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Prices are only sent while someone can act on them. The columns are those of the list above. -->
            <section
                v-if="offers.length > 0"
                class="glass mt-3 animate-rise rounded-panel p-6 [animation-delay:160ms] sm:p-9 lg:p-10"
                :aria-labelledby="`${fieldId}-plans`"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-x-8 gap-y-1.5">
                    <h2 :id="`${fieldId}-plans`" class="eyebrow">{{ t('central.welcome.plans.label') }}</h2>
                    <p class="text-[0.8125rem] text-ink-muted">{{ t('central.welcome.plans.body') }}</p>
                </div>

                <ul class="mt-4 grid gap-x-8 gap-y-8 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="{ plan, price, otherPrices } in offers" :key="plan.key" class="flex flex-col border-t border-line-strong pt-4">
                        <h3 class="text-[1.0625rem] wrap-break-word text-ink">{{ plan.name }}</h3>

                        <template v-if="price">
                            <p class="mt-3 flex flex-wrap items-baseline gap-x-1.5 gap-y-1">
                                <span class="figure text-[1.75rem] text-ink">{{ format.money(price.amount, plan.currency) }}</span>
                                <span class="text-[0.8125rem] text-ink-muted">{{ t(`billing.terms.perInterval.${price.interval}`) }}</span>
                            </p>
                            <p v-for="other in otherPrices" :key="other.interval" class="mt-1.5 text-[0.8125rem] text-ink-muted">
                                <span class="tabular text-ink">{{ format.money(other.amount, plan.currency) }}</span>
                                {{ t(`billing.terms.perInterval.${other.interval}`) }}
                            </p>
                            <p v-if="plan.trialDays > 0" class="mt-3">
                                <Badge tone="brand" class="tabular">
                                    {{ t('billing.terms.trialDays', { count: format.number(plan.trialDays) }) }}
                                </Badge>
                            </p>
                        </template>
                        <!-- A word, not a number: Bengali needs the room `figure` takes away. -->
                        <p v-else class="figure mt-3 text-[1.75rem] text-ink [&:lang(bn)]:leading-[1.4] [&:lang(bn)]:tracking-normal">
                            {{ t('billing.terms.free') }}
                        </p>

                        <p v-if="plan.description" class="mt-3 text-[0.8125rem] wrap-break-word text-ink-muted">{{ plan.description }}</p>

                        <div class="mt-auto pt-5">
                            <Button
                                :href="route('central.register', { plan: plan.key })"
                                :aria-label="t('central.welcome.plans.choosePlan', { plan: plan.name })"
                            >
                                {{ t('central.welcome.plans.choose') }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </main>
    </div>

    <Toaster />
</template>
