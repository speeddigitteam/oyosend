<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: AuthLayout });

defineProps<{
    /** Name of the workspace whose subdomain this is. */
    workspace: string;
}>();

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post(route('auth.login.store'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t('auth.login.title')" />

    <div class="mb-7">
        <h1 class="text-[1.75rem] text-ink">{{ t('auth.login.title') }}</h1>
        <p class="mt-1 text-ink-muted">{{ t('auth.login.subtitle', { workspace }) }}</p>
    </div>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Input
            v-model="form.email"
            type="email"
            name="email"
            :label="t('auth.login.email')"
            :error="form.errors.email"
            autocomplete="username"
            inputmode="email"
            autofocus
            required
        />

        <PasswordInput
            v-model="form.password"
            name="password"
            :label="t('auth.login.password')"
            :error="form.errors.password"
            autocomplete="current-password"
            required
        >
            <template #aside>
                <Link :href="route('auth.password.request')" class="text-[0.8125rem] font-medium text-brand-text hover:underline">
                    {{ t('auth.login.forgot') }}
                </Link>
            </template>
        </PasswordInput>

        <Checkbox v-model="form.remember" :label="t('auth.login.remember')" class="mt-1" />

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ t('auth.login.submit') }}
        </Button>
    </form>
</template>
