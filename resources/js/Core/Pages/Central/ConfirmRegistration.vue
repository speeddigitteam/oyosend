<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleCheck, Hourglass, Link2Off } from 'lucide-vue-next';
import { computed, h, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

// Inertia calls this with Vue's own `h`, but types that argument without room for props - hence the imported one.
defineOptions({ layout: (_h, page) => h(AuthLayout, { plain: true }, () => page) });

/** Where the sign-up stands once the link has been used (or cannot be). */
type Outcome =
    | 'ready' // the workspace exists and can be signed in to
    | 'pending' // it exists and waits for the platform's approval
    | 'invalid'; // the link is unknown, expired, replaced or used up

/**
 * The page the emailed link opens. Opening it creates nothing - a mail
 * scanner follows links too - so `confirm` shows what is about to be made and
 * one button. Pressing it either takes the browser into the new workspace or
 * brings this page back in one of the other states.
 */
const props = defineProps<{
    state: 'confirm' | Outcome;
    /** `confirm` only: the token of the link, posted back by the button. */
    token: string | null;
    /** Name of the workspace. Not sent with `invalid`. */
    company: string | null;
    /** `confirm` only: the address being confirmed, which will own the workspace. */
    email: string | null;
    /** Host name of the workspace. Not sent with `invalid`. */
    address: string | null;
    /** `confirm` only: name of the chosen plan, if plans are on offer. */
    plan: string | null;
    /** `ready` only: the workspace's sign-in page, on its own host. */
    loginUrl: string | null;
    /** Whether a new sign-up can be started from here. */
    registrationOpen: boolean;
}>();

const { t } = useI18n();

const OUTCOMES: Record<Outcome, { icon: Component; tone: string }> = {
    ready: { icon: CircleCheck, tone: 'bg-ok-soft text-ok' },
    pending: { icon: Hourglass, tone: 'bg-info-soft text-info' },
    invalid: { icon: Link2Off, tone: 'bg-warn-soft text-warn' },
};

const outcome = computed(() => (props.state === 'confirm' ? null : OUTCOMES[props.state]));
const title = computed(() => t(`register.${props.state}.title`, { company: props.company ?? props.address ?? '' }));

/** What is about to be created, in the order it is read out. */
const details = computed(() =>
    [
        { key: 'company', value: props.company, mono: false },
        { key: 'address', value: props.address, mono: true },
        { key: 'owner', value: props.email, mono: false },
        { key: 'plan', value: props.plan, mono: false },
    ].filter((row): row is { key: 'company' | 'address' | 'owner' | 'plan'; value: string; mono: boolean } => row.value !== null),
);

const form = useForm({});

function create(): void {
    if (props.token) {
        form.post(route('central.register.complete', { token: props.token }));
    }
}
</script>

<template>
    <Head :title="title" />

    <template v-if="state === 'confirm'">
        <h1 class="text-[1.75rem] wrap-break-word text-ink">{{ title }}</h1>
        <p class="mt-2 text-ink-muted">{{ t('register.confirm.body') }}</p>

        <dl class="glass-well mt-5 divide-y divide-line rounded-control">
            <div v-for="row in details" :key="row.key" class="px-4 py-3">
                <dt class="eyebrow">{{ t(`register.confirm.${row.key}`) }}</dt>
                <dd :class="['mt-1 break-all text-ink', row.mono ? 'font-mono text-[0.8125rem] tracking-wide' : 'font-medium']">
                    {{ row.value }}
                </dd>
            </div>
        </dl>

        <form class="mt-6" @submit.prevent="create">
            <Button type="submit" variant="primary" size="lg" block :loading="form.processing">
                {{ t('register.confirm.submit') }}
            </Button>
        </form>

        <p class="mt-3 text-[0.8125rem] text-ink-subtle">{{ t('register.confirm.once') }}</p>
    </template>

    <template v-else-if="outcome">
        <span :class="['flex size-12 items-center justify-center rounded-full', outcome.tone]" aria-hidden="true">
            <component :is="outcome.icon" class="size-6" />
        </span>

        <h1 class="mt-5 text-2xl wrap-break-word text-ink">{{ title }}</h1>
        <p class="mt-2 text-ink-muted">{{ t(`register.${state}.body`) }}</p>

        <dl v-if="address" class="glass-well mt-5 rounded-control px-4 py-3">
            <dt class="eyebrow">{{ t('register.confirm.address') }}</dt>
            <dd class="mt-1 font-mono text-[0.8125rem] tracking-wide break-all text-ink">{{ address }}</dd>
        </dl>

        <template v-if="state === 'ready'">
            <p class="mt-4 text-[0.8125rem] text-ink-muted">{{ t('register.ready.password') }}</p>

            <!-- Another origin, so a plain link: `Button` with an `href` would make an Inertia visit of it. -->
            <a
                v-if="loginUrl"
                :href="loginUrl"
                class="mt-6 flex h-11 w-full items-center justify-center rounded-control bg-brand px-5 text-[0.9375rem] font-medium whitespace-nowrap text-brand-ink shadow-[inset_0_1px_0_rgb(255_255_255/0.22),0_1px_2px_rgb(0_0_0/0.16),0_8px_18px_-8px_var(--brand-shadow)] transition-[background-color,box-shadow,transform] duration-150 select-none hover:bg-brand-hover active:translate-y-px active:shadow-[inset_0_1px_2px_rgb(0_0_0/0.25)]"
            >
                {{ t('register.ready.signIn') }}
            </a>
        </template>

        <div v-else-if="state === 'invalid'" class="mt-7 flex flex-wrap gap-2">
            <Button v-if="registrationOpen" variant="primary" :href="route('central.register')">
                {{ t('register.invalid.again') }}
            </Button>
            <Button :variant="registrationOpen ? 'ghost' : 'secondary'" :href="route('central.home')" native>
                {{ t('register.invalid.home') }}
            </Button>
        </div>
    </template>
</template>
