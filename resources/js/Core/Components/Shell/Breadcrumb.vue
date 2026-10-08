<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePageTitle } from '@/Core/composables/usePageTitle';

/**
 * Where you are: the area's home, then the current page. Two levels is all
 * the navigation has today; deeper trails come with modules that need them.
 */
defineProps<{
    /** Name of the root: the workspace, or the admin panel. */
    root: string;
    rootHref: string;
}>();

const { pageTitle } = usePageTitle();
</script>

<template>
    <nav :aria-label="$t('shell.breadcrumb')" class="hidden min-w-0 items-center gap-2 pl-1.5 text-[0.8125rem] sm:flex">
        <Link :href="rootHref" class="shrink-0 truncate text-ink-subtle transition-colors hover:text-ink">{{ root }}</Link>
        <template v-if="pageTitle">
            <span class="text-ink-subtle/60" aria-hidden="true">/</span>
            <span class="truncate font-medium text-ink" aria-current="page">{{ pageTitle }}</span>
        </template>
    </nav>
</template>
