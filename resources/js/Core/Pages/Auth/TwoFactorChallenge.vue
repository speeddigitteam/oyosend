<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: AuthLayout });

/**
 * Second step of signing in. The password step has already succeeded and is
 * held in the session; this page sends either an authenticator code or one
 * recovery code, never both.
 */
type Mode = 'code' | 'recovery';

const { t } = useI18n();

const mode = ref<Mode>('code');
const field = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({
    code: '',
    recovery_code: '',
});

const BENGALI_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Six Latin digits at most. A Bengali keyboard types ০-৯, which the server would not read as a code. */
function toCode(value: string): string {
    return value
        .replace(/[০-৯]/g, (digit) => String(BENGALI_DIGITS.indexOf(digit)))
        .replace(/\D/g, '')
        .slice(0, 6);
}

/** Recovery codes are issued in lower case, without spaces, and compared exactly. */
function toRecoveryCode(value: string): string {
    return value.replace(/\s+/g, '').toLowerCase();
}

/*
 * Cleans a field as it is typed into. The element is written to directly as
 * well as the form: when cleaning gives back the value the form already had
 * (a letter typed after "123"), nothing changes for Vue and the stray
 * character would stay on screen.
 */
function clean(event: Event, key: 'code' | 'recovery_code', tidy: (value: string) => string): void {
    // Leave an input method alone until it has finished composing.
    if (event instanceof InputEvent && event.isComposing) {
        return;
    }

    const input = event.target as HTMLInputElement;
    const value = tidy(input.value);

    if (input.value !== value) {
        input.value = value;
    }

    form[key] = value;
}

async function focusField(): Promise<void> {
    await nextTick();
    field.value?.focus();
}

function switchTo(next: Mode): void {
    mode.value = next;
    form.reset();
    form.clearErrors();
    void focusField();
}

function submit(): void {
    form.code = toCode(form.code);
    form.recovery_code = toRecoveryCode(form.recovery_code);

    form.transform((data) => (mode.value === 'code' ? { code: data.code } : { recovery_code: data.recovery_code })).post(
        route('auth.two-factor.verify'),
        {
            onError: () => {
                // An authenticator code has rotated by now; a mistyped recovery code is worth correcting.
                if (mode.value === 'code') {
                    form.reset('code');
                }

                void focusField();
            },
        },
    );
}
</script>

<template>
    <Head :title="t('auth.twoFactor.title')" />

    <div class="mb-7">
        <h1 class="text-[1.75rem] text-ink">{{ t('auth.twoFactor.title') }}</h1>
        <p class="mt-1 text-ink-muted">
            {{ mode === 'code' ? t('auth.twoFactor.subtitle') : t('auth.twoFactor.recoverySubtitle') }}
        </p>
    </div>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Input
            v-if="mode === 'code'"
            key="code"
            ref="field"
            v-model="form.code"
            name="code"
            :label="t('auth.twoFactor.code')"
            :error="form.errors.code"
            class="tabular h-12 font-mono text-lg tracking-[0.3em]"
            inputmode="numeric"
            autocomplete="one-time-code"
            enterkeyhint="go"
            autofocus
            required
            @input="clean($event, 'code', toCode)"
            @change="clean($event, 'code', toCode)"
        />

        <Input
            v-else
            key="recovery"
            ref="field"
            v-model="form.recovery_code"
            name="recovery_code"
            mono
            :label="t('auth.twoFactor.recoveryCode')"
            :hint="t('auth.twoFactor.recoveryHint')"
            :error="form.errors.recovery_code"
            autocomplete="off"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            enterkeyhint="go"
            required
            @input="clean($event, 'recovery_code', toRecoveryCode)"
            @change="clean($event, 'recovery_code', toRecoveryCode)"
        />

        <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
            {{ t('auth.twoFactor.submit') }}
        </Button>
    </form>

    <div class="mt-6 flex flex-col items-start gap-0.5 border-t border-line pt-4">
        <!-- Button keeps its label on one line; this sentence has to be able to wrap on a narrow screen. -->
        <Button
            variant="ghost"
            size="sm"
            class="-ml-3 h-auto! min-h-8 max-w-[calc(100%+0.75rem)] py-1.5 text-left whitespace-normal!"
            @click="switchTo(mode === 'code' ? 'recovery' : 'code')"
        >
            {{ mode === 'code' ? t('auth.twoFactor.useRecovery') : t('auth.twoFactor.useCode') }}
        </Button>

        <Button variant="ghost" size="sm" class="-ml-3" :href="route('auth.login')">
            <template #icon><ArrowLeft class="size-3.5" aria-hidden="true" /></template>
            {{ t('auth.twoFactor.back') }}
        </Button>
    </div>
</template>
