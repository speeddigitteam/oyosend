<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, UsersRound } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { route } from '@/Core/lib/route';

export type DirectoryTab = 'people' | 'companies';

/**
 * Switches between the two lists of the contact directory. Each page names
 * itself with `current` and puts this above its list.
 *
 * Same vocabulary as the settings tabs and the sidebar - a lifted glass pill
 * and a brand-tinted icon for the current entry - because it is navigation
 * between two pages, not a filter on one.
 */
defineProps<{ current: DirectoryTab }>();

const { t } = useI18n();

const tabs = computed<Array<{ key: DirectoryTab; label: string; href: string; icon: Component }>>(() => [
    { key: 'people', label: t('contacts.tabs.people'), href: route('core.contacts.index'), icon: UsersRound },
    { key: 'companies', label: t('contacts.tabs.companies'), href: route('core.contacts.companies.index'), icon: Building2 },
]);
</script>

<template>
    <nav :aria-label="t('contacts.tabs.label')" class="max-w-full">
        <!-- Outer radius = inner radius + padding, so the corners stay concentric. -->
        <ul class="glass inline-flex max-w-full flex-wrap gap-0.5 rounded-[0.875rem] p-1">
            <li v-for="tab in tabs" :key="tab.key">
                <Link
                    :href="tab.href"
                    :aria-current="tab.key === current ? 'page' : undefined"
                    :class="[
                        'flex min-h-9 items-center gap-2 rounded-control px-3 text-sm whitespace-nowrap transition-colors',
                        tab.key === current
                            ? 'bg-active font-medium text-ink shadow-[inset_0_1px_0_var(--glass-edge),0_1px_2px_rgb(0_0_0/0.07)]'
                            : 'text-ink-muted hover:bg-hover hover:text-ink',
                    ]"
                >
                    <component
                        :is="tab.icon"
                        :class="['size-4 shrink-0', tab.key === current ? 'text-brand-text' : 'text-ink-subtle']"
                        aria-hidden="true"
                    />
                    {{ tab.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
