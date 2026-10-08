<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { LogOut } from 'lucide-vue-next';
import { computed, h, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import TwoFactorEnrolment from '@/Core/Components/TwoFactorEnrolment.vue';
import { useFormat } from '@/Core/composables/useFormat';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';
import OneTimeCodeInput from '@/Platform/Components/OneTimeCodeInput.vue';
import PanelHeading from '@/Platform/Components/PanelHeading.vue';

// Inertia calls this with Vue's own `h`, but types that argument without room for props - hence the imported one.
defineOptions({ layout: (_h, page) => h(AuthLayout, { plain: true }, () => page) });

/*
 * The operator is signed in, but EnsurePlatformTwoFactor sends every other
 * request back here until enrolment is confirmed. The only ways off this
 * screen are a valid code and signing out.
 */
defineProps<{
    /** SVG markup of the enrolment QR code, generated on the server. */
    qrCode: string;
    /** The same secret as text, for typing into the app by hand. */
    secret: string;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();

const email = computed(() => page.props.auth?.user?.email ?? '');

const form = useForm({ code: '' });
const codeInput = ref<InstanceType<typeof OneTimeCodeInput> | null>(null);

function submit(): void {
    form.post(route('platform.two-factor.confirm'), {
        // The code has rotated by now; clear it and wait for the next one.
        onError: () => {
            form.reset('code');
            codeInput.value?.focus();
        },
    });
}

const signingOut = ref(false);

function signOut(): void {
    router.post(
        route('platform.logout'),
        {},
        {
            onStart: () => (signingOut.value = true),
            onFinish: () => (signingOut.value = false),
        },
    );
}
</script>

<template>
    <PanelHeading :title="t('platform.auth.setup.title')" :description="t('platform.auth.setup.intro')" />

    <ol class="flex flex-col gap-6">
        <li>
            <p class="eyebrow">{{ t('platform.auth.setup.step', { number: format.number(1) }) }}</p>
            <h2 class="mt-1.5 font-sans text-sm font-semibold tracking-normal text-ink">{{ t('platform.auth.setup.scan') }}</h2>

            <TwoFactorEnrolment class="mt-4" :qr-code="qrCode" :secret="secret" />
        </li>

        <li class="border-t border-line pt-6">
            <p class="eyebrow">{{ t('platform.auth.setup.step', { number: format.number(2) }) }}</p>
            <h2 class="mt-1.5 font-sans text-sm font-semibold tracking-normal text-ink">{{ t('platform.auth.setup.confirm') }}</h2>

            <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submit">
                <OneTimeCodeInput
                    ref="codeInput"
                    v-model="form.code"
                    :label="t('common.twoFactor.code')"
                    :hint="t('common.twoFactor.codeHint')"
                    :error="form.errors.code"
                    enterkeyhint="done"
                    required
                />

                <Button type="submit" variant="primary" size="lg" block :loading="form.processing">
                    {{ t('platform.auth.setup.submit') }}
                </Button>
            </form>

            <p class="mt-4 text-[0.8125rem] text-ink-subtle">{{ t('platform.auth.setup.recoveryNext') }}</p>
        </li>
    </ol>

    <!-- The only other way off this screen. -->
    <div class="mt-7 flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-line pt-5">
        <div class="min-w-0">
            <p class="eyebrow">{{ t('platform.auth.setup.signedInAs') }}</p>
            <p class="mt-1 font-mono text-[0.8125rem] break-all text-ink-muted">{{ email }}</p>
        </div>

        <Button size="sm" :loading="signingOut" @click="signOut">
            <template #icon><LogOut class="size-3.5" aria-hidden="true" /></template>
            {{ t('shell.account.signOut') }}
        </Button>
    </div>
</template>
