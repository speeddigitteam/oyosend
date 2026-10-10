<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Lock, LogOut } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Backdrop from '@/Core/Components/Backdrop.vue';
import Button from '@/Core/Components/Button.vue';
import Logo from '@/Core/Components/Logo.vue';
import { route } from '@/Core/lib/route';

/**
 * What a member gets instead of any working page of a workspace whose
 * subscription has ended. Only someone who looks after billing can bring the
 * workspace back - and they are sent to billing, not here - so this page
 * explains, and offers the two things that are still open to the reader:
 * their own account settings and signing out.
 */
const props = defineProps<{
    workspace: string;
    /** `expired` (unpaid past the grace period) or `canceled`. */
    status: string;
}>();

const page = usePage();
const { t } = useI18n();

const reason = computed(() => (props.status === 'canceled' ? 'canceled' : 'expired'));
const email = computed(() => page.props.auth?.user?.email ?? null);

const signingOut = ref(false);

function signOut(): void {
    signingOut.value = true;

    router.post(
        route('auth.logout'),
        {},
        {
            onFinish: () => {
                signingOut.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('workspace.locked.title', { workspace })" />
    <Backdrop variant="stage" />

    <div class="flex min-h-dvh flex-col">
        <header class="px-5 pt-5 sm:px-8 sm:pt-7"><Logo /></header>

        <main class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-5 py-12">
            <div class="glass animate-rise rounded-panel p-8 sm:p-10">
                <span class="flex size-12 items-center justify-center rounded-full bg-warn-soft text-warn" aria-hidden="true">
                    <Lock class="size-6" />
                </span>
                <h1 class="mt-5 text-2xl wrap-break-word text-ink">{{ t('workspace.locked.title', { workspace }) }}</h1>
                <p class="mt-2 text-ink-muted">{{ t(`workspace.locked.${reason}`) }}</p>
                <p class="mt-2 text-ink-muted">{{ t('workspace.locked.safe') }}</p>

                <div class="mt-7 flex flex-wrap gap-2">
                    <Button variant="primary" :loading="signingOut" @click="signOut">
                        <template #icon><LogOut class="size-4" aria-hidden="true" /></template>
                        {{ t('shell.account.signOut') }}
                    </Button>
                    <Button :href="route('core.settings.profile')">{{ t('workspace.locked.account') }}</Button>
                </div>

                <p v-if="email" class="mt-6 border-t border-line pt-4 text-[0.8125rem] wrap-anywhere text-ink-subtle">
                    {{ t('workspace.locked.signedInAs', { email }) }}
                </p>
            </div>
        </main>
    </div>
</template>
