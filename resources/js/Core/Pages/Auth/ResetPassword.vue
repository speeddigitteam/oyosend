<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lock } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: AuthLayout });

const props = defineProps<{
    /** Reset token from the emailed link. */
    token: string;
    /** Address the link was sent to; empty when the link did not carry one. */
    email: string;
}>();

const { t } = useI18n();

// The emailed link normally carries the address. Only ask for it when it does not.
const emailFromLink = props.email !== '';

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(route('auth.password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('auth.reset.title')" />

    <div class="mb-7">
        <h1 class="text-[1.75rem] text-ink">{{ t('auth.reset.title') }}</h1>
        <p class="mt-1 text-ink-muted">{{ t('auth.reset.subtitle') }}</p>
    </div>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <!--
            Kept as a real field even when it cannot be edited: password managers
            file the new password under it, and an expired or invalid link is
            reported by the server as an error on the email.
        -->
        <Input
            v-model="form.email"
            type="email"
            name="email"
            :label="t('auth.reset.email')"
            :error="form.errors.email"
            :readonly="emailFromLink"
            :class="emailFromLink ? 'text-ink-muted' : ''"
            :autofocus="!emailFromLink"
            autocomplete="username"
            inputmode="email"
            autocapitalize="none"
            spellcheck="false"
            required
        >
            <template v-if="emailFromLink" #trailing>
                <Lock class="mr-2 size-3.5 text-ink-subtle" aria-hidden="true" />
            </template>
        </Input>

        <PasswordInput
            v-model="form.password"
            name="password"
            :label="t('auth.reset.password')"
            :hint="t('auth.passwordRules')"
            :error="form.errors.password"
            :autofocus="emailFromLink"
            autocomplete="new-password"
            required
        />

        <PasswordInput
            v-model="form.password_confirmation"
            name="password_confirmation"
            :label="t('auth.reset.passwordConfirmation')"
            :error="form.errors.password_confirmation"
            autocomplete="new-password"
            required
        />

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ t('auth.reset.submit') }}
        </Button>
    </form>

    <div class="mt-6 flex flex-col items-start gap-0.5 border-t border-line pt-4">
        <!-- The link is single-use and short-lived; when the server rejects it, the way forward is a new one. -->
        <Button v-if="form.errors.email" variant="ghost" size="sm" class="-ml-3" :href="route('auth.password.request')">
            {{ t('auth.reset.requestNew') }}
        </Button>

        <Button variant="ghost" size="sm" class="-ml-3" :href="route('auth.login')">
            <template #icon><ArrowLeft class="size-3.5" aria-hidden="true" /></template>
            {{ t('auth.reset.back') }}
        </Button>
    </div>
</template>
