<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { KeyRound, LogOut, Moon, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import Logo from '@/Core/Components/Logo.vue';
import AppShell from '@/Core/Components/Shell/AppShell.vue';
import Breadcrumb from '@/Core/Components/Shell/Breadcrumb.vue';
import type { Command } from '@/Core/Components/Shell/CommandPalette.vue';
import LocaleSwitch from '@/Core/Components/Shell/LocaleSwitch.vue';
import type { ShellNavSection } from '@/Core/Components/Shell/SidebarNav.vue';
import ThemeToggle from '@/Core/Components/Shell/ThemeToggle.vue';
import UserMenu from '@/Core/Components/Shell/UserMenu.vue';
import WorkspaceSwitcher from '@/Core/Components/Shell/WorkspaceSwitcher.vue';
import { useTheme } from '@/Core/composables/useTheme';
import { isCurrentRoute, route } from '@/Core/lib/route';

/**
 * Layout of every signed-in workspace page. The navigation arrives from the
 * server already filtered to what this person may open in this workspace.
 */
const page = usePage();
const { t, te } = useI18n();
const { toggle } = useTheme();

const user = computed(() => page.props.auth?.user ?? null);

const sections = computed<ShellNavSection[]>(() =>
    (page.props.navigation ?? []).map((section) => ({
        key: section.key,
        label: t(`shell.nav.sections.${section.key}`),
        items: section.items.map((item) => ({
            key: item.key,
            // A module names its entry `nav.{key}`; fall back to the key until it is translated.
            label: te(`shell.nav.${item.key}`) ? t(`shell.nav.${item.key}`) : item.key,
            href: item.href,
            icon: item.icon,
            // Recomputed on every visit because it reads the current URL.
            current: page.url !== undefined && isCurrentRoute(item.active),
        })),
    })),
);

const commands = computed<Command[]>(() => [
    {
        id: 'action:profile',
        group: t('shell.palette.groups.actions'),
        label: t('shell.account.profile'),
        icon: UserRound,
        perform: () => router.visit(route('core.settings.profile')),
    },
    {
        id: 'action:security',
        group: t('shell.palette.groups.actions'),
        label: t('shell.account.security'),
        icon: KeyRound,
        keywords: 'password two-factor 2fa',
        perform: () => router.visit(route('core.settings.security')),
    },
    {
        id: 'action:theme',
        group: t('shell.palette.groups.actions'),
        label: t('common.theme.label'),
        icon: Moon,
        keywords: 'dark light',
        perform: toggle,
    },
    ...(page.props.workspaces ?? [])
        .filter((workspace) => !workspace.current)
        .map((workspace) => ({
            id: `workspace:${workspace.slug}`,
            group: t('shell.palette.groups.workspaces'),
            label: workspace.name,
            keywords: workspace.slug,
            perform: () => router.post(route('core.workspaces.switch', { workspace: workspace.slug })),
        })),
    {
        id: 'action:sign-out',
        group: t('shell.palette.groups.actions'),
        label: t('shell.account.signOut'),
        icon: LogOut,
        perform: () => router.post(route('auth.logout')),
    },
]);
</script>

<template>
    <AppShell :sections="sections" :commands="commands">
        <template #brand>
            <WorkspaceSwitcher />
        </template>

        <template #sidebar-footer>
            <div class="flex items-center justify-between px-1.5 opacity-80">
                <Logo :size="22" />
            </div>
        </template>

        <template #topbar-start>
            <Breadcrumb v-if="page.props.tenant" :root="page.props.tenant.name" :root-href="route('core.dashboard')" />
        </template>

        <template #topbar-end>
            <LocaleSwitch />
            <ThemeToggle />
            <UserMenu
                v-if="user"
                :user="user"
                :caption="page.props.auth?.membership?.isOwner ? t('shell.account.owner') : undefined"
                :logout-url="route('auth.logout')"
            >
                <DropdownItem :href="route('core.settings.profile')">
                    <template #icon><UserRound aria-hidden="true" /></template>
                    {{ t('shell.account.profile') }}
                </DropdownItem>
                <DropdownItem :href="route('core.settings.security')">
                    <template #icon><KeyRound aria-hidden="true" /></template>
                    {{ t('shell.account.security') }}
                </DropdownItem>
                <DropdownSeparator />
            </UserMenu>
        </template>

        <slot />
    </AppShell>
</template>
