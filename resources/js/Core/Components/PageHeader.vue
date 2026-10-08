<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import { usePageTitle } from '@/Core/composables/usePageTitle';

/**
 * Title block at the top of a page. Also sets the document title and the
 * shell's breadcrumb, so a page only declares its name once.
 */
const props = defineProps<{
    title: string;
    /** Small caption above the title: the section the page belongs to. */
    eyebrow?: string;
    description?: string;
}>();

const { setPageTitle } = usePageTitle();

watchEffect(() => setPageTitle(props.title));
</script>

<template>
    <Head :title="title" />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
        <div class="min-w-0">
            <p v-if="eyebrow" class="eyebrow mb-2">{{ eyebrow }}</p>
            <h1 class="text-[1.75rem] text-ink sm:text-[2rem]">{{ title }}</h1>
            <p v-if="description" class="mt-1.5 max-w-2xl text-ink-muted">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
