<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PauseCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Backdrop from '@/Core/Components/Backdrop.vue';
import Logo from '@/Core/Components/Logo.vue';

/**
 * Shown for every request to a workspace that is suspended or not yet
 * active. Deliberately offers no way further into the workspace.
 */
const props = defineProps<{
    workspace: string;
    status: string;
}>();

const { t } = useI18n();

const state = computed(() => (props.status === 'pending' ? 'pending' : 'suspended'));
</script>

<template>
    <Head :title="t(`errors.workspace.${state}.title`, { workspace })" />
    <Backdrop variant="stage" />

    <div class="flex min-h-dvh flex-col">
        <header class="px-5 pt-5 sm:px-8 sm:pt-7"><Logo /></header>

        <main class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-5 py-12">
            <div class="glass animate-rise rounded-panel p-8 sm:p-10">
                <span class="flex size-12 items-center justify-center rounded-full bg-warn-soft text-warn" aria-hidden="true">
                    <PauseCircle class="size-6" />
                </span>
                <h1 class="mt-5 text-2xl text-ink">{{ t(`errors.workspace.${state}.title`, { workspace }) }}</h1>
                <p class="mt-2 text-ink-muted">{{ t(`errors.workspace.${state}.body`) }}</p>
            </div>
        </main>
    </div>
</template>
