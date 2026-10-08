<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Link2Off, Lock, MailCheck, TimerOff, UserRoundX } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';
import { Translation, useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import { useFormat } from '@/Core/composables/useFormat';
import AuthLayout from '@/Core/Layouts/AuthLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: AuthLayout });

/** States in which the invitation can still be accepted, and what each asks for. */
type OpenState =
    | 'new_account' // no account for this address yet: name and a new password
    | 'existing_account' // an account exists: its password, to prove it is theirs
    | 'confirm'; // already signed in as the invitee: one button

/** States with nothing to accept. */
type ClosedState = 'invalid' | 'expired' | 'revoked' | 'accepted' | 'wrong_account';

interface InvitationSummary {
    email: string;
    /** Display name of the role being granted. */
    role: string;
    inviter: string | null;
    /** ISO 8601. */
    expiresAt: string;
}

const props = defineProps<{
    /** Name of the workspace the invitation belongs to. */
    workspace: string;
    state: OpenState | ClosedState;
    /** Sent while the invitation is still pending (the open states and `wrong_account`). */
    token?: string;
    invitation?: InvitationSummary;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();

const CLOSED: Record<ClosedState, { icon: Component; tone: string }> = {
    invalid: { icon: Link2Off, tone: 'bg-warn-soft text-warn' },
    expired: { icon: TimerOff, tone: 'bg-warn-soft text-warn' },
    revoked: { icon: Ban, tone: 'bg-warn-soft text-warn' },
    accepted: { icon: MailCheck, tone: 'bg-brand-soft text-brand-text' },
    wrong_account: { icon: UserRoundX, tone: 'bg-warn-soft text-warn' },
};

const closed = computed(() => (props.state in CLOSED ? CLOSED[props.state as ClosedState] : null));

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

// The server can reject the invitation itself (used or withdrawn in the meantime); that error belongs to no field.
const invitationError = computed(() => page.props.errors.invitation);

const expires = computed(() =>
    props.invitation ? t('auth.invitation.expires', { date: format.date(props.invitation.expiresAt, 'datetime') }) : '',
);

function submit(): void {
    if (!props.token) {
        return;
    }

    form.transform((data) => {
        switch (props.state) {
            case 'new_account':
                return data;
            case 'existing_account':
                return { password: data.password };
            default:
                return {};
        }
    }).post(route('auth.invitations.accept', { token: props.token }), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

const signingOut = ref(false);

/** Signed in as someone else: sign out, then open this same invitation as a guest. */
function switchAccount(): void {
    const token = props.token;

    router.post(
        route('auth.logout'),
        {},
        {
            onStart: () => {
                signingOut.value = true;
            },
            onFinish: () => {
                signingOut.value = false;
            },
            onSuccess: () => {
                if (token) {
                    router.visit(route('auth.invitations.show', { token }));
                }
            },
        },
    );
}
</script>

<template>
    <template v-if="closed">
        <Head :title="t(`auth.invitation.states.${state}.title`)" />

        <span :class="['flex size-12 items-center justify-center rounded-full', closed.tone]" aria-hidden="true">
            <component :is="closed.icon" class="size-6" />
        </span>

        <p class="eyebrow mt-5">{{ workspace }}</p>
        <h1 class="mt-2 text-2xl text-ink">{{ t(`auth.invitation.states.${state}.title`) }}</h1>
        <p class="mt-2 text-ink-muted">{{ t(`auth.invitation.states.${state}.body`) }}</p>

        <!-- Which account to come back with. -->
        <dl v-if="state === 'wrong_account' && invitation" class="glass-well mt-5 rounded-control px-4 py-3">
            <dt class="eyebrow">{{ t('auth.invitation.emailLabel') }}</dt>
            <dd class="mt-1 font-medium break-all text-ink">{{ invitation.email }}</dd>
        </dl>

        <div v-if="state === 'wrong_account' || state === 'accepted' || state === 'invalid'" class="mt-7 flex flex-wrap gap-2">
            <Button v-if="state === 'wrong_account'" variant="primary" :loading="signingOut" @click="switchAccount">
                {{ t('auth.invitation.switchAccount') }}
            </Button>
            <Button v-else :variant="state === 'accepted' ? 'primary' : 'secondary'" :href="route('auth.login')">
                {{ t('auth.invitation.signIn') }}
            </Button>
        </div>
    </template>

    <template v-else-if="invitation">
        <Head :title="t('auth.invitation.title', { workspace })" />

        <div class="mb-7">
            <h1 class="text-[1.75rem] text-ink">{{ t('auth.invitation.title', { workspace }) }}</h1>

            <Translation
                :keypath="invitation.inviter ? 'auth.invitation.invitedBy' : 'auth.invitation.invited'"
                tag="p"
                scope="global"
                class="mt-1 text-ink-muted"
            >
                <template #inviter>
                    <strong class="font-medium text-ink">{{ invitation.inviter }}</strong>
                </template>
                <template #role>
                    <strong class="font-medium text-ink">{{ invitation.role }}</strong>
                </template>
            </Translation>
        </div>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <template v-if="state === 'confirm'">
                <div class="glass-well rounded-control px-4 py-3">
                    <p class="text-ink">{{ t('auth.invitation.confirmHint', { email: invitation.email }) }}</p>
                    <p class="tabular mt-1 text-[0.8125rem] text-ink-subtle">{{ expires }}</p>
                </div>

                <p v-if="invitationError" class="text-[0.8125rem] text-danger" role="alert">{{ invitationError }}</p>
            </template>

            <template v-else>
                <!--
                    Not editable - the invitation is for this address only - but a real
                    field, so a password manager files the password under it.
                -->
                <Input
                    :model-value="invitation.email"
                    type="email"
                    name="email"
                    :label="t('auth.invitation.emailLabel')"
                    :hint="expires"
                    :error="invitationError"
                    class="text-ink-muted"
                    autocomplete="username"
                    readonly
                >
                    <template #trailing>
                        <Lock class="mr-2 size-3.5 text-ink-subtle" aria-hidden="true" />
                    </template>
                </Input>

                <template v-if="state === 'new_account'">
                    <Input
                        v-model="form.name"
                        name="name"
                        :label="t('auth.invitation.name')"
                        :error="form.errors.name"
                        autocomplete="name"
                        maxlength="120"
                        autofocus
                        required
                    />

                    <PasswordInput
                        v-model="form.password"
                        name="password"
                        :label="t('auth.invitation.password')"
                        :hint="t('auth.passwordRules')"
                        :error="form.errors.password"
                        autocomplete="new-password"
                        required
                    />

                    <PasswordInput
                        v-model="form.password_confirmation"
                        name="password_confirmation"
                        :label="t('auth.invitation.passwordConfirmation')"
                        :error="form.errors.password_confirmation"
                        autocomplete="new-password"
                        required
                    />
                </template>

                <template v-else>
                    <p class="text-[0.8125rem] text-ink-muted">{{ t('auth.invitation.existingHint') }}</p>

                    <PasswordInput
                        v-model="form.password"
                        name="password"
                        :label="t('auth.invitation.existingPassword')"
                        :error="form.errors.password"
                        autocomplete="current-password"
                        autofocus
                        required
                    />
                </template>
            </template>

            <Button type="submit" variant="primary" size="lg" block :loading="form.processing" class="mt-2">
                {{ state === 'new_account' ? t('auth.invitation.createAndJoin') : t('auth.invitation.accept') }}
            </Button>
        </form>
    </template>
</template>
