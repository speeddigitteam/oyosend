<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { History, ShieldAlert, ShieldCheck, ShieldOff } from 'lucide-vue-next';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import RecoveryCodes from '@/Core/Components/RecoveryCodes.vue';
import SettingsTabs from '@/Core/Components/Settings/SettingsTabs.vue';
import TwoFactorEnrolment from '@/Core/Components/TwoFactorEnrolment.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

interface TwoFactorState {
    /** Confirmed and required at sign-in. */
    enabled: boolean;
    /** A secret has been issued but not yet confirmed with a code. */
    pending: boolean;
    /** Enrolment material; only sent while `pending`. `qrCode` is SVG markup. */
    qrCode: string | null;
    secret: string | null;
    /** Only sent while `enabled`. */
    recoveryCodesRemaining: number | null;
}

const props = defineProps<{
    twoFactor: TwoFactorState;
    /** ISO 8601, or null before the first recorded sign-in. */
    lastLoginAt: string | null;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();

/*
|--------------------------------------------------------------------------
| Password
|--------------------------------------------------------------------------
*/

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function updatePassword(): void {
    passwordForm.put(route('core.settings.password.update'), {
        preserveScroll: true,
        // Reset here, not in onFinish: after a success the form adopts its current values as the new defaults.
        onSuccess: () => {
            passwordForm.reset();
        },
        onError: (errors) => {
            if (errors.current_password) {
                passwordForm.reset('current_password');
            }

            if (errors.password) {
                passwordForm.reset('password', 'password_confirmation');
            }
        },
    });
}

/*
|--------------------------------------------------------------------------
| Two-step verification
|--------------------------------------------------------------------------
|
| off -> (password) -> pending -> (code) -> on. Every change except
| confirming the code asks for the current password again, in one dialog.
|
*/

type Status = 'off' | 'pending' | 'on';

const status = computed<Status>(() => {
    if (props.twoFactor.enabled) {
        return 'on';
    }

    return props.twoFactor.pending ? 'pending' : 'off';
});

// "Off" is a warning here as it is on the dashboard's account widget.
const STATUS_TONES: Record<Status, BadgeTone> = { off: 'warn', pending: 'warn', on: 'ok' };

const remaining = computed(() => props.twoFactor.recoveryCodesRemaining ?? 0);
const runningLow = computed(() => remaining.value <= 2);

/*
 * Fresh recovery codes arrive once, as flash data. They are held here so an
 * unrelated save on this page (which brings its own flash) cannot wipe the
 * only copy off the screen before it has been stored.
 */
const recoveryCodes = ref<string[]>([]);
const recoveryBlock = ref<HTMLElement | null>(null);

watch(
    () => page.flash?.recoveryCodes,
    async (codes) => {
        if (!codes?.length) {
            return;
        }

        recoveryCodes.value = [...codes];

        await nextTick();
        recoveryBlock.value?.scrollIntoView({ block: 'nearest' });
    },
    { immediate: true },
);

watch(
    () => props.twoFactor.enabled,
    (enabled) => {
        if (!enabled) {
            recoveryCodes.value = [];
        }
    },
);

// Finishing enrolment: the code proves the authenticator app is set up.
const confirmForm = useForm({ code: '' });
const codeField = ref<InstanceType<typeof Input> | null>(null);

const BENGALI_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Six Latin digits at most. A Bengali keyboard types ০-৯, which the server would not read as a code. */
function toCode(value: string): string {
    return value
        .replace(/[০-৯]/g, (digit) => String(BENGALI_DIGITS.indexOf(digit)))
        .replace(/\D/g, '')
        .slice(0, 6);
}

/*
 * Cleans the code as it is typed. The element is written to directly as well
 * as the form: when cleaning gives back the value the form already had (a
 * letter typed after "123"), nothing changes for Vue and the stray character
 * would stay on screen.
 */
function cleanCode(event: Event): void {
    // Leave an input method alone until it has finished composing.
    if (event instanceof InputEvent && event.isComposing) {
        return;
    }

    const input = event.target as HTMLInputElement;
    const code = toCode(input.value);

    if (input.value !== code) {
        input.value = code;
    }

    confirmForm.code = code;
}

function confirmEnrolment(): void {
    confirmForm.code = toCode(confirmForm.code);

    confirmForm.post(route('core.settings.two-factor.confirm'), {
        preserveScroll: true,
        onSuccess: () => {
            confirmForm.reset();
        },
        // The code has rotated by now; clear it and wait for the next one.
        onError: () => {
            confirmForm.reset();
            codeField.value?.focus();
        },
    });
}

// The password prompt in front of every other change.
type Intent = 'enable' | 'regenerate' | 'disable' | 'cancel';

const intent = ref<Intent>('enable');
const promptOpen = ref(false);
const promptFormId = useId();
const promptFieldId = useId();

const prompt = useForm({ current_password: '' });

const twoFactorCard = ref<InstanceType<typeof Card> | null>(null);
let opener: HTMLElement | null = null;

function ask(next: Intent): void {
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    intent.value = next;
    promptOpen.value = true;
}

/** Runs once the dialog has finished mounting or unmounting, and with it its own focus handling. */
function afterDialog(callback: () => void): void {
    void nextTick(() => requestAnimationFrame(callback));
}

/*
 * The dialog puts focus on its first control, the close button. Move it to
 * the password field; PasswordInput exposes no focus(), so go by id.
 */
function focusPrompt(): void {
    afterDialog(() => document.getElementById(promptFieldId)?.focus());
}

watch(promptOpen, (open) => {
    if (open) {
        focusPrompt();

        return;
    }

    prompt.reset();
    prompt.clearErrors();

    /*
     * Modal is opened without a trigger element, so it hands focus back to
     * nothing. Return it to the button that asked - or, when the change has
     * just replaced that button (off -> pending -> on), to the card.
     */
    afterDialog(() => {
        const card: HTMLElement | null = twoFactorCard.value?.$el ?? null;

        (opener?.isConnected ? opener : card)?.focus({ preventScroll: true });
        opener = null;
    });
});

function submitPrompt(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            prompt.reset();
            promptOpen.value = false;
        },
        // Stay open with the server's message under the field.
        onError: () => {
            prompt.reset();
            focusPrompt();
        },
    };

    switch (intent.value) {
        case 'enable':
            prompt.post(route('core.settings.two-factor.enable'), options);
            break;
        case 'regenerate':
            prompt.post(route('core.settings.two-factor.recovery-codes'), options);
            break;
        default:
            // Turning it off and abandoning a half-finished setup are the same request.
            prompt.delete(route('core.settings.two-factor.disable'), options);
    }
}
</script>

<template>
    <div>
        <PageHeader
            :eyebrow="t('settings.eyebrow')"
            :title="t('settings.security.title')"
            :description="t('settings.security.description')"
        >
            <template #actions><SettingsTabs current="security" /></template>
        </PageHeader>

        <!-- Full width like the platform Settings page: the password and two-step boxes side by side on wide screens
             (two-step takes two columns at xl so the recovery codes keep their room), the last sign-in across the row
             beneath. Each box lays out its own content by its own width (container queries), not the screen's. -->
        <div class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
            <form novalidate class="flex flex-col" @submit.prevent="updatePassword">
                <Card class="flex-1" :title="t('settings.security.password.title')" :description="t('settings.security.password.description')">
                    <div class="@container">
                        <div class="grid gap-4 @lg:grid-cols-2">
                            <div class="@lg:col-start-1">
                                <PasswordInput
                                    v-model="passwordForm.current_password"
                                    name="current_password"
                                    :label="t('settings.security.password.current')"
                                    :error="passwordForm.errors.current_password"
                                    autocomplete="current-password"
                                    required
                                />
                            </div>

                            <div class="@lg:col-start-1">
                                <PasswordInput
                                    v-model="passwordForm.password"
                                    name="password"
                                    :label="t('settings.security.password.new')"
                                    :hint="t('auth.passwordRules')"
                                    :error="passwordForm.errors.password"
                                    autocomplete="new-password"
                                    required
                                />
                            </div>

                            <div>
                                <PasswordInput
                                    v-model="passwordForm.password_confirmation"
                                    name="password_confirmation"
                                    :label="t('settings.security.password.confirm')"
                                    :error="passwordForm.errors.password_confirmation"
                                    autocomplete="new-password"
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <template #footer>
                        <div class="flex justify-end">
                            <Button type="submit" variant="primary" :loading="passwordForm.processing">
                                {{ t('settings.security.password.submit') }}
                            </Button>
                        </div>
                    </template>
                </Card>
            </form>

            <!-- Focusable from script only: focus lands here when a change removes the button that started it. -->
            <Card
                ref="twoFactorCard"
                tabindex="-1"
                class="focus:outline-none xl:col-span-2"
                :title="t('settings.security.twoFactor.title')"
                :description="t('settings.security.twoFactor.description')"
            >
                <template #actions>
                    <Badge :tone="STATUS_TONES[status]" dot>{{ t(`settings.security.twoFactor.status.${status}`) }}</Badge>
                </template>

                <div class="@container">
                    <!-- Shown once: the server does not keep recovery codes in a readable form. -->
                    <div v-if="recoveryCodes.length" ref="recoveryBlock" class="mb-5 scroll-mt-24">
                        <RecoveryCodes :codes="recoveryCodes" />
                    </div>

                    <!-- Off -->
                    <div v-if="status === 'off'" class="flex items-start gap-4">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-warn-soft text-warn" aria-hidden="true">
                            <ShieldAlert class="size-5" />
                        </span>

                        <div class="flex min-w-0 flex-1 flex-col gap-4 @lg:flex-row @lg:items-center @lg:justify-between @lg:gap-6">
                            <p class="max-w-prose text-ink-muted">{{ t('settings.security.twoFactor.off.body') }}</p>

                            <Button variant="primary" class="self-start @lg:self-center" @click="ask('enable')">
                                {{ t('settings.security.twoFactor.off.action') }}
                            </Button>
                        </div>
                    </div>

                    <!-- Pending: scan, then prove it works -->
                    <div v-else-if="status === 'pending'">
                        <p class="text-ink-muted">{{ t('settings.security.twoFactor.pending.intro') }}</p>

                        <ol class="mt-5 flex flex-col gap-6">
                            <li v-if="twoFactor.qrCode && twoFactor.secret">
                                <p class="eyebrow">{{ t('settings.security.twoFactor.pending.step', { number: format.number(1) }) }}</p>
                                <h3 class="mt-1.5 font-sans text-sm font-semibold tracking-normal text-ink">
                                    {{ t('settings.security.twoFactor.pending.scan') }}
                                </h3>

                                <TwoFactorEnrolment class="mt-4" :qr-code="twoFactor.qrCode" :secret="twoFactor.secret" />
                            </li>

                            <li class="border-t border-line pt-6">
                                <p class="eyebrow">{{ t('settings.security.twoFactor.pending.step', { number: format.number(2) }) }}</p>
                                <h3 class="mt-1.5 font-sans text-sm font-semibold tracking-normal text-ink">
                                    {{ t('settings.security.twoFactor.pending.confirm') }}
                                </h3>

                                <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="confirmEnrolment">
                                    <div class="@sm:max-w-xs">
                                        <Input
                                            ref="codeField"
                                            v-model="confirmForm.code"
                                            name="code"
                                            :label="t('common.twoFactor.code')"
                                            :hint="t('common.twoFactor.codeHint')"
                                            :error="confirmForm.errors.code"
                                            control-class="tabular h-11 font-mono text-base tracking-[0.3em]"
                                            inputmode="numeric"
                                            autocomplete="one-time-code"
                                            enterkeyhint="done"
                                            required
                                            @input="cleanCode"
                                            @change="cleanCode"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <Button type="submit" variant="primary" :loading="confirmForm.processing">
                                            {{ t('settings.security.twoFactor.pending.submit') }}
                                        </Button>
                                        <Button variant="ghost" :disabled="confirmForm.processing" @click="ask('cancel')">
                                            {{ t('settings.security.twoFactor.pending.cancel') }}
                                        </Button>
                                    </div>
                                </form>
                            </li>
                        </ol>
                    </div>

                    <!-- On -->
                    <ul v-else>
                        <li class="flex items-center gap-4 pb-5">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-ok-soft text-ok" aria-hidden="true">
                                <ShieldCheck class="size-5" />
                            </span>

                            <p class="min-w-0 flex-1 text-ink">{{ t('settings.security.twoFactor.on.body') }}</p>
                        </li>

                        <li class="flex items-start gap-4 border-t border-line py-5">
                            <!-- The one number on this page that can need attention. Read out as part of the heading beside it. -->
                            <span
                                :class="[
                                    'glass-well figure flex size-11 shrink-0 items-center justify-center rounded-full text-lg',
                                    runningLow ? 'text-accent-text' : 'text-ink',
                                ]"
                                aria-hidden="true"
                            >
                                {{ format.number(remaining) }}
                            </span>

                            <div class="flex min-w-0 flex-1 flex-col gap-3 @lg:flex-row @lg:items-center @lg:justify-between @lg:gap-6">
                                <div class="min-w-0">
                                    <h3
                                        class="font-sans text-sm font-semibold tracking-normal text-ink"
                                        :aria-label="t('settings.security.twoFactor.on.recoveryCount', { count: format.number(remaining) })"
                                    >
                                        {{ t('settings.security.twoFactor.on.recoveryHeading') }}
                                    </h3>
                                    <p class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('settings.security.twoFactor.on.recoveryBody') }}</p>
                                    <p v-if="runningLow" class="mt-1 text-[0.8125rem] text-warn">
                                        {{
                                            remaining === 0
                                                ? t('settings.security.twoFactor.on.recoveryNone')
                                                : t('settings.security.twoFactor.on.recoveryLow')
                                        }}
                                    </p>
                                </div>

                                <Button class="self-start @lg:self-center" @click="ask('regenerate')">
                                    {{ t('settings.security.twoFactor.on.regenerate') }}
                                </Button>
                            </div>
                        </li>

                        <li class="flex items-start gap-4 border-t border-line pt-5">
                            <span class="glass-well flex size-11 shrink-0 items-center justify-center rounded-full text-ink-subtle" aria-hidden="true">
                                <ShieldOff class="size-5" />
                            </span>

                            <div class="flex min-w-0 flex-1 flex-col gap-3 @lg:flex-row @lg:items-center @lg:justify-between @lg:gap-6">
                                <div class="min-w-0">
                                    <h3 class="font-sans text-sm font-semibold tracking-normal text-ink">
                                        {{ t('settings.security.twoFactor.on.turnOffHeading') }}
                                    </h3>
                                    <p class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('settings.security.twoFactor.on.turnOffBody') }}</p>
                                </div>

                                <Button class="self-start @lg:self-center" @click="ask('disable')">
                                    {{ t('settings.security.twoFactor.on.turnOff') }}
                                </Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- A short strip across the row: one fact, read at a glance. -->
            <Card class="lg:col-span-2 xl:col-span-3">
                <div class="@container">
                    <div class="flex items-start gap-4">
                        <span class="glass-well flex size-11 shrink-0 items-center justify-center rounded-full text-ink-subtle" aria-hidden="true">
                            <History class="size-5" />
                        </span>

                        <div class="flex min-w-0 flex-1 flex-col gap-2 @lg:flex-row @lg:items-center @lg:justify-between @lg:gap-6">
                            <div class="min-w-0">
                                <h2 class="font-sans text-sm font-semibold tracking-normal text-ink">
                                    {{ t('settings.security.lastSignIn.title') }}
                                </h2>
                                <p v-if="lastLoginAt" class="mt-1 text-[0.8125rem] text-ink-muted">
                                    {{ t('settings.security.lastSignIn.body') }}
                                </p>
                            </div>

                            <p v-if="lastLoginAt" class="tabular text-sm text-ink @lg:text-right">
                                <time :datetime="lastLoginAt">{{ format.date(lastLoginAt, 'datetime') }}</time>
                                <span class="block text-[0.8125rem] text-ink-subtle">{{ format.relative(lastLoginAt) }}</span>
                            </p>
                            <p v-else class="text-sm text-ink-muted">{{ t('common.never') }}</p>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </div>

    <Modal
        v-model:open="promptOpen"
        :title="t(`settings.security.twoFactor.prompt.${intent}.title`)"
        :description="t(`settings.security.twoFactor.prompt.${intent}.description`)"
        size="sm"
        :persistent="prompt.processing"
    >
        <form :id="promptFormId" novalidate @submit.prevent="submitPrompt">
            <PasswordInput
                :id="promptFieldId"
                v-model="prompt.current_password"
                name="current_password"
                :label="t('settings.security.twoFactor.prompt.password')"
                :error="prompt.errors.current_password"
                autocomplete="current-password"
                required
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="prompt.processing" @click="promptOpen = false">
                {{ t('common.actions.cancel') }}
            </Button>
            <Button
                type="submit"
                :form="promptFormId"
                :variant="intent === 'disable' ? 'danger' : 'primary'"
                :loading="prompt.processing"
            >
                {{ t(`settings.security.twoFactor.prompt.${intent}.confirm`) }}
            </Button>
        </template>
    </Modal>
</template>
