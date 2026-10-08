<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { KeyRound, UserRound } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { route } from '@/Core/lib/route';

export type SettingsTab = 'profile' | 'security';

/**
 * Sub-navigation shared by the personal settings pages. Each page names
 * itself with `current` and puts this in its PageHeader's `actions` slot;
 * add an entry here when a new settings page arrives.
 *
 * It borrows the sidebar's vocabulary - a lifted glass pill and a brand-tinted
 * icon for the current entry - so it reads as navigation, not as a filter.
 */
defineProps<{ current: SettingsTab }>();

const { t } = useI18n();

const tabs = computed<Array<{ key: SettingsTab; label: string; href: string; icon: Component }>>(() => [
    { key: 'profile', label: t('settings.tabs.profile'), href: route('core.settings.profile'), icon: UserRound },
    { key: 'security', label: t('settings.tabs.security'), href: route('core.settings.security'), icon: KeyRound },
]);
</script>

<template>
    <nav :aria-label="t('settings.tabs.label')" class="max-w-full">
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
