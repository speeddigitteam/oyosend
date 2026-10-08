<script setup lang="ts">
import { KeyRound, Languages, ShieldCheck } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Backdrop from '@/Core/Components/Backdrop.vue';
import Logo from '@/Core/Components/Logo.vue';
import LocaleSwitch from '@/Core/Components/Shell/LocaleSwitch.vue';
import ThemeToggle from '@/Core/Components/Shell/ThemeToggle.vue';
import Toaster from '@/Core/Components/Toaster.vue';

/**
 * Full-screen layout for signed-out pages: an editorial statement on the
 * left, the form on a glass sheet on the right. On small screens only the
 * sheet remains.
 *
 * The discs are positioned relative to the sheet, not the window, so the
 * sheet always has something to refract and the statement always sits on a
 * clean background, whatever the screen size.
 *
 * `plain` drops the statement for pages that are not about signing in to a
 * workspace (the Super Admin panel, standalone notices).
 */
defineProps<{ plain?: boolean }>();

const { t } = useI18n();
</script>

<template>
    <Backdrop variant="plain" />

    <div class="flex min-h-dvh flex-col overflow-x-clip">
        <header class="flex items-center justify-between gap-4 px-5 pt-5 sm:px-8 sm:pt-7">
            <Logo />
            <div class="flex items-center gap-1">
                <LocaleSwitch />
                <ThemeToggle />
            </div>
        </header>

        <div
            :class="[
                'mx-auto grid w-full flex-1 items-center gap-12 px-5 py-10 sm:px-8',
                plain ? 'max-w-md' : 'max-w-6xl lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)] lg:gap-20',
            ]"
        >
            <section v-if="!plain" class="hidden animate-rise lg:block" aria-hidden="true">
                <p class="eyebrow mb-5">{{ t('auth.aside.eyebrow') }}</p>
                <p class="font-display text-[3.25rem] leading-[1.04] font-semibold tracking-[-0.035em] text-balance text-ink xl:text-[3.75rem]">
                    {{ t('auth.aside.headline') }}
                </p>
                <p class="mt-6 max-w-md text-base text-ink-muted">{{ t('auth.aside.body') }}</p>

                <ul class="mt-10 flex flex-col gap-3 text-sm text-ink-muted">
                    <li class="flex items-center gap-3">
                        <span class="glass flex size-8 items-center justify-center rounded-full"><ShieldCheck class="size-4 text-brand-text" /></span>
                        {{ t('auth.aside.points.isolation') }}
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="glass flex size-8 items-center justify-center rounded-full"><Languages class="size-4 text-brand-text" /></span>
                        {{ t('auth.aside.points.languages') }}
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="glass flex size-8 items-center justify-center rounded-full"><KeyRound class="size-4 text-brand-text" /></span>
                        {{ t('auth.aside.points.roles') }}
                    </li>
                </ul>
            </section>

            <div class="relative w-full animate-rise">
                <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                    <span class="absolute -top-16 -left-20 size-56 rounded-full bg-(--orb-ember) opacity-80" />
                    <span class="absolute -right-24 -bottom-24 size-72 rounded-full bg-(--orb-pine) opacity-80" />
                    <span class="absolute bottom-10 -left-9 size-20 rounded-full bg-(--orb-butter) opacity-80" />
                    <span class="absolute -top-10 -right-14 size-36 rounded-full border-[1.5px] border-(--orb-ember) opacity-80" />
                </div>

                <main id="main" class="glass relative rounded-panel p-7 sm:p-9">
                    <slot />
                </main>
            </div>
        </div>
    </div>

    <Toaster />
</template>
