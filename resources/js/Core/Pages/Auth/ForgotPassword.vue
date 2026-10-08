<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: AuthLayout });

/**
 * Asks for a password reset link. The server answers every address the same
 * way (a toast), so nothing here may suggest the address was recognised, and
 * the form stays ready for another try.
 */
const { t } = useI18n();

const form = useForm({
    email: '',
});

// Stays set once a request has gone through, so the follow-up advice does not flicker on a second try.
const requested = ref(false);

function submit(): void {
    form.post(route('auth.password.email'), {
        preserveScroll: true,
        onSuccess: () => {
            requested.value = true;
        },
    });
}
</script>

<template>
    <Head :title="t('auth.forgot.title')" />

    <div class="mb-7">
        <h1 class="text-[1.75rem] text-ink">{{ t('auth.forgot.title') }}</h1>
        <p class="mt-1 text-ink-muted">{{ t('auth.forgot.subtitle') }}</p>
    </div>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Input
            v-model="form.email"
            type="email"
            name="email"
            :label="t('auth.forgot.email')"
            :error="form.errors.email"
            autocomplete="username"
            inputmode="email"
            autocapitalize="none"
            spellcheck="false"
            autofocus
            required
        />

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ requested ? t('auth.forgot.resend') : t('auth.forgot.submit') }}
        </Button>

        <p v-if="requested" class="glass-well rounded-control px-3.5 py-3 text-[0.8125rem] text-ink-muted">
            {{ t('auth.forgot.afterSend') }}
        </p>
    </form>

    <div class="mt-6 flex flex-col items-start gap-0.5 border-t border-line pt-4">
        <Button variant="ghost" size="sm" class="-ml-3" :href="route('auth.login')">
            <template #icon><ArrowLeft class="size-3.5" aria-hidden="true" /></template>
            {{ t('auth.forgot.back') }}
        </Button>
    </div>
</template>
