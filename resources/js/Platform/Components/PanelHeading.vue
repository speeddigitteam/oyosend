<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';

/**
 * Heading of the signed-out admin panel screens. It carries the same marker
 * as the sidebar once signed in, next to the address being signed in to, so
 * nobody mistakes this for the door to a customer workspace.
 */
defineProps<{
    title: string;
    description?: string;
    /** Browser tab title, when it should say more than the heading does. */
    documentTitle?: string;
}>();

const { t } = useI18n();

const host = window.location.host;
</script>

<template>
    <Head :title="documentTitle ?? title" />

    <div class="mb-7">
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <Badge tone="accent">{{ t('shell.platform.badge') }}</Badge>
            <span class="min-w-0 font-mono text-xs break-all text-ink-subtle">{{ host }}</span>
        </p>
        <h1 class="mt-4 text-[1.75rem] text-ink">{{ title }}</h1>
        <p v-if="description" class="mt-1 text-ink-muted">{{ description }}</p>
    </div>
</template>
