<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-vue-next';
import { watch, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToasts } from '@/Core/composables/useToasts';
import type { ToastType } from '@/Core/types';

/**
 * Renders the toast stack and feeds it from Inertia flash data. Mount it
 * once per layout.
 */
const { toasts, push, dismiss } = useToasts();
const { t } = useI18n();
const page = usePage();

// The server flashes at most one toast per response; a new flash object
// arrives with every visit, so identical messages in a row still show.
watch(
    () => page.flash?.toast,
    (toast) => {
        if (toast?.message) {
            push(toast.type ?? 'info', toast.message);
        }
    },
    { immediate: true },
);

const ICONS: Record<ToastType, Component> = {
    success: CircleCheck,
    info: Info,
    warning: TriangleAlert,
    error: CircleAlert,
};

const TONES: Record<ToastType, string> = {
    success: 'text-ok',
    info: 'text-info',
    warning: 'text-warn',
    error: 'text-danger',
};
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:inset-x-auto sm:right-0 sm:items-end sm:p-5"
        role="region"
        aria-live="polite"
        aria-relevant="additions"
    >
        <TransitionGroup
            enter-active-class="transition duration-300 ease-soft"
            enter-from-class="translate-y-3 opacity-0"
            leave-active-class="transition duration-200 ease-in absolute"
            leave-to-class="translate-x-4 opacity-0"
            move-class="transition duration-300 ease-soft"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="glass-solid pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-card py-3 pr-2 pl-3.5"
                :role="toast.type === 'error' ? 'alert' : 'status'"
            >
                <component :is="ICONS[toast.type]" :class="['mt-0.5 size-[1.125rem] shrink-0', TONES[toast.type]]" aria-hidden="true" />
                <p class="min-w-0 flex-1 py-0.5 text-sm leading-snug text-ink">{{ toast.message }}</p>
                <button
                    type="button"
                    class="flex size-7 shrink-0 items-center justify-center rounded-control text-ink-subtle transition-colors hover:bg-hover hover:text-ink"
                    :aria-label="t('common.dismiss')"
                    @click="dismiss(toast.id)"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
