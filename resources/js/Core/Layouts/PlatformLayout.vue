<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { LogOut, Moon } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Logo from '@/Core/Components/Logo.vue';
import AppShell from '@/Core/Components/Shell/AppShell.vue';
import Breadcrumb from '@/Core/Components/Shell/Breadcrumb.vue';
import type { Command } from '@/Core/Components/Shell/CommandPalette.vue';
import type { ShellNavSection } from '@/Core/Components/Shell/SidebarNav.vue';
import ThemeToggle from '@/Core/Components/Shell/ThemeToggle.vue';
import UserMenu from '@/Core/Components/Shell/UserMenu.vue';
import { useTheme } from '@/Core/composables/useTheme';
import { isCurrentRoute, route } from '@/Core/lib/route';

/**
 * Layout of the Super Admin panel. Same shell and components as a workspace;
 * the navigation is fixed here because the panel is not built from modules.
 */
const page = usePage();
const { t } = useI18n();
const { toggle } = useTheme();

const user = computed(() => page.props.auth?.user ?? null);

const sections = computed<ShellNavSection[]>(() => [
    {
        key: 'platform',
        label: t('shell.nav.sections.platform'),
        items: [
            {
                key: 'dashboard',
                label: t('shell.nav.dashboard'),
                href: route('platform.dashboard'),
                icon: 'layout-dashboard',
                current: page.url !== undefined && isCurrentRoute('platform.dashboard'),
            },
            {
                key: 'tenants',
                label: t('shell.nav.tenants'),
                href: route('platform.tenants.index'),
                icon: 'building-2',
                current: page.url !== undefined && isCurrentRoute('platform.tenants.*'),
            },
        ],
    },
]);

const commands = computed<Command[]>(() => [
    {
        id: 'action:theme',
        group: t('shell.palette.groups.actions'),
        label: t('common.theme.label'),
        icon: Moon,
        keywords: 'dark light',
        perform: toggle,
    },
    {
        id: 'action:sign-out',
        group: t('shell.palette.groups.actions'),
        label: t('shell.account.signOut'),
        icon: LogOut,
        perform: () => router.post(route('platform.logout')),
    },
]);
</script>

<template>
    <AppShell :sections="sections" :commands="commands">
        <template #brand>
            <div class="flex items-center justify-between gap-2 p-1.5">
                <Logo :size="26" />
                <Badge tone="accent">{{ t('shell.platform.badge') }}</Badge>
            </div>
        </template>

        <template #topbar-start>
            <Breadcrumb :root="t('shell.platform.badge')" :root-href="route('platform.dashboard')" />
        </template>

        <template #topbar-end>
            <ThemeToggle />
            <UserMenu
                v-if="user"
                :user="user"
                :caption="user.role?.replace('_', ' ')"
                :logout-url="route('platform.logout')"
            />
        </template>

        <slot />
    </AppShell>
</template>
