<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleDashed, Clock, MailCheck } from 'lucide-vue-next';
import { computed, h } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useFormat } from '@/Core/composables/useFormat';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

// Inertia calls this with Vue's own `h`, but types that argument without room for props - hence the imported one.
defineOptions({ layout: (_h, page) => h(AuthLayout, { plain: true }, () => page) });

/**
 * Shown after the sign-up form was accepted. The server shows it for every
 * address alike, so it says what was sent and where - never whether the
 * address is new - and that nothing exists until the link is confirmed.
 */
const props = defineProps<{
    /** The address as it was typed into the form. */
    email: string;
    /** How long the emailed link can be confirmed. */
    expiresInHours: number;
}>();

const { t } = useI18n();
const format = useFormat();

const validity = computed(() =>
    t(props.expiresInHours === 1 ? 'register.sent.valid.one' : 'register.sent.valid.other', {
        count: format.number(props.expiresInHours),
    }),
);
</script>

<template>
    <Head :title="t('register.sent.title')" />

    <span class="flex size-12 items-center justify-center rounded-full bg-brand-soft text-brand-text" aria-hidden="true">
        <MailCheck class="size-6" />
    </span>

    <h1 class="mt-5 text-2xl text-ink">{{ t('register.sent.title') }}</h1>
    <p class="mt-2 text-ink-muted">{{ t('register.sent.body') }}</p>

    <dl class="glass-well mt-5 rounded-control px-4 py-3">
        <dt class="eyebrow">{{ t('register.sent.sentTo') }}</dt>
        <dd class="mt-1 font-medium break-all text-ink">{{ email }}</dd>
    </dl>

    <ul class="mt-5 flex flex-col gap-2.5 text-[0.8125rem] text-ink-muted">
        <li class="flex items-start gap-2.5">
            <Clock class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            <span class="tabular">{{ validity }}</span>
        </li>
        <li class="flex items-start gap-2.5">
            <CircleDashed class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            {{ t('register.sent.nothingYet') }}
        </li>
    </ul>

    <section class="mt-6 border-t border-line pt-5">
        <h2 class="text-[0.9375rem] text-ink">{{ t('register.sent.missing.title') }}</h2>
        <p class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('register.sent.missing.body') }}</p>

        <div class="mt-4 flex flex-wrap gap-2">
            <Button :href="route('central.register')">{{ t('register.sent.again') }}</Button>
            <Button variant="ghost" :href="route('central.home')" native>{{ t('register.sent.home') }}</Button>
        </div>
    </section>
</template>
