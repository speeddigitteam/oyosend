<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { h, nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';
import OneTimeCodeInput from '@/Platform/Components/OneTimeCodeInput.vue';
import PanelHeading from '@/Platform/Components/PanelHeading.vue';

// Inertia calls this with Vue's own `h`, but types that argument without room for props - hence the imported one.
defineOptions({ layout: (_h, page) => h(AuthLayout, { plain: true }, () => page) });

/**
 * Second step of signing in to the panel. The password step has already
 * succeeded and is held in the session; this page sends either an
 * authenticator code or one recovery code, never both.
 */
type Mode = 'code' | 'recovery';

const { t } = useI18n();

const mode = ref<Mode>('code');

const form = useForm({
    code: '',
    recovery_code: '',
});

const codeInput = ref<InstanceType<typeof OneTimeCodeInput> | null>(null);
const recoveryInput = ref<InstanceType<typeof Input> | null>(null);

function focusField(): void {
    (mode.value === 'code' ? codeInput.value : recoveryInput.value)?.focus();
}

function switchTo(next: Mode): void {
    mode.value = next;
    form.reset();
    form.clearErrors();

    void nextTick(focusField);
}

function submit(): void {
    form
        // Recovery codes are issued in lower case and compared exactly.
        .transform((data) =>
            mode.value === 'code' ? { code: data.code } : { recovery_code: data.recovery_code.replace(/\s+/g, '').toLowerCase() },
        )
        .post(route('platform.two-factor.verify'), {
            onError: () => {
                // An authenticator code has rotated by now; a mistyped recovery code is worth correcting.
                if (mode.value === 'code') {
                    form.reset('code');
                }

                focusField();
            },
        });
}

// `autofocus` only fires on a full page load, and this screen is reached by an in-app visit.
onMounted(focusField);
</script>

<template>
    <PanelHeading
        :title="t('platform.auth.challenge.title')"
        :description="mode === 'code' ? t('platform.auth.challenge.codeIntro') : t('platform.auth.challenge.recoveryIntro')"
    />

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <OneTimeCodeInput
            v-if="mode === 'code'"
            ref="codeInput"
            v-model="form.code"
            :label="t('platform.auth.challenge.code')"
            :error="form.errors.code"
            enterkeyhint="go"
            autofocus
            required
        />

        <Input
            v-else
            ref="recoveryInput"
            v-model="form.recovery_code"
            name="recovery_code"
            mono
            :label="t('platform.auth.challenge.recoveryCode')"
            :hint="t('platform.auth.challenge.recoveryHint')"
            :error="form.errors.recovery_code"
            maxlength="40"
            autocomplete="off"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            enterkeyhint="go"
            required
        />

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ t('platform.auth.challenge.submit') }}
        </Button>
    </form>

    <div class="mt-6 flex flex-col items-start gap-0.5 border-t border-line pt-4">
        <!-- Button keeps its label on one line; this sentence has to be able to wrap on a narrow screen. -->
        <Button
            size="sm"
            variant="ghost"
            class="-ml-3 h-auto! min-h-8 max-w-[calc(100%+0.75rem)] py-1.5 text-left whitespace-normal!"
            @click="switchTo(mode === 'code' ? 'recovery' : 'code')"
        >
            {{ mode === 'code' ? t('platform.auth.challenge.useRecovery') : t('platform.auth.challenge.useCode') }}
        </Button>

        <Button size="sm" variant="ghost" class="-ml-3" :href="route('platform.login')">
            <template #icon><ArrowLeft class="size-3.5" aria-hidden="true" /></template>
            {{ t('platform.auth.challenge.back') }}
        </Button>
    </div>
</template>
