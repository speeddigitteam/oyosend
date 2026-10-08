<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { LockKeyhole } from 'lucide-vue-next';
import { computed, h, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';
import PanelHeading from '@/Platform/Components/PanelHeading.vue';

// Inertia calls this with Vue's own `h`, but types that argument without room for props - hence the imported one.
defineOptions({ layout: (_h, page) => h(AuthLayout, { plain: true }, () => page) });

const page = usePage();
const { t } = useI18n();

// No "remember me": a session in this panel ends when the browser session does.
const form = useForm({
    email: '',
    password: '',
});

const emailInput = ref<InstanceType<typeof Input> | null>(null);

/*
 * The two-step screen sends people back here with a message when a sign-in
 * runs out of attempts. That message belongs to the page, not to this form,
 * so it is read from the page until the form has an answer of its own.
 */
const emailError = computed(() => form.errors.email ?? page.props.errors.email);

function submit(): void {
    form.post(route('platform.login.store'), {
        onFinish: () => form.reset('password'),
    });
}

// `autofocus` only fires on a full page load; this covers arriving by an in-app visit.
onMounted(() => emailInput.value?.focus());
</script>

<template>
    <PanelHeading
        :title="t('platform.auth.login.title')"
        :description="t('platform.auth.login.subtitle', { app: page.props.app.name })"
        :document-title="t('platform.auth.login.documentTitle')"
    />

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Input
            ref="emailInput"
            v-model="form.email"
            type="email"
            name="email"
            :label="t('platform.auth.login.email')"
            :error="emailError"
            autocomplete="username"
            inputmode="email"
            autofocus
            required
        />

        <PasswordInput
            v-model="form.password"
            name="password"
            :label="t('platform.auth.login.password')"
            :error="form.errors.password"
            autocomplete="current-password"
            required
        />

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ t('platform.auth.login.submit') }}
        </Button>
    </form>

    <div class="glass-well mt-7 flex items-start gap-3 rounded-control px-4 py-3.5" role="note">
        <LockKeyhole class="mt-px size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
        <div class="min-w-0">
            <p class="eyebrow">{{ t('platform.auth.login.restrictedTitle') }}</p>
            <p class="mt-1.5 text-[0.8125rem] text-ink-muted">{{ t('platform.auth.login.restrictedBody') }}</p>
        </div>
    </div>

    <p class="mt-4 text-[0.8125rem] text-ink-subtle">
        {{ t('platform.auth.login.noReset') }} {{ t('platform.auth.login.notWorkspace') }}
    </p>
</template>
