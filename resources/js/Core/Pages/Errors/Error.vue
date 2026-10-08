<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Backdrop from '@/Core/Components/Backdrop.vue';
import Button from '@/Core/Components/Button.vue';
import Logo from '@/Core/Components/Logo.vue';

/**
 * Stand-alone error screen. It uses no layout and reads no shared props: an
 * error can happen before any area - or any tenant - has been resolved.
 */
const props = defineProps<{ status: number }>();

const { t, te } = useI18n();

const key = computed(() => (te(`errors.${props.status}.title`) ? String(props.status) : '500'));

function back(): void {
    window.history.back();
}
</script>

<template>
    <Head :title="t(`errors.${key}.title`)" />
    <Backdrop variant="stage" />

    <div class="flex min-h-dvh flex-col">
        <header class="px-5 pt-5 sm:px-8 sm:pt-7"><Logo /></header>

        <main class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-5 py-12">
            <div class="glass animate-rise rounded-panel p-8 sm:p-10">
                <p class="figure text-[5.5rem] text-accent">{{ status }}</p>
                <h1 class="mt-4 text-2xl text-ink">{{ t(`errors.${key}.title`) }}</h1>
                <p class="mt-2 text-ink-muted">{{ t(`errors.${key}.body`) }}</p>

                <div class="mt-7 flex flex-wrap gap-2">
                    <Button variant="primary" @click="back">{{ t('errors.back') }}</Button>
                    <a
                        href="/"
                        class="inline-flex h-[2.375rem] items-center rounded-control border border-line-strong bg-control px-4 text-sm font-medium text-ink shadow-[inset_0_1px_0_var(--glass-edge)] transition-colors hover:bg-control-hover"
                    >
                        {{ t('errors.home') }}
                    </a>
                </div>
            </div>
        </main>
    </div>
</template>
