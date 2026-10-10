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
import LocaleSwitch from '@/Core/Components/Shell/LocaleSwitch.vue';
import type { ShellNavItem, ShellNavSection } from '@/Core/Components/Shell/SidebarNav.vue';
import ThemeToggle from '@/Core/Components/Shell/ThemeToggle.vue';
import UserMenu from '@/Core/Components/Shell/UserMenu.vue';
import { useTheme } from '@/Core/composables/useTheme';
import { isCurrentRoute, route } from '@/Core/lib/route';

/**
 * Layout of the Super Admin panel. Same shell and components as a workspace;
 * the navigation is fixed here because the panel is not built from modules.
 */
const page = usePage();
const { t, te } = useI18n();
const { toggle } = useTheme();

const user = computed(() => page.props.auth?.user ?? null);

interface PanelEntry {
    key: string;
    /** Key under `shell.nav`. */
    label: string;
    route: string;
    /** Route-name pattern that marks the entry active. */
    active: string;
    /** Name in the icon registry (`Core/Components/Icon.vue`). */
    icon: string;
    /** The `platform.*` ability the entry's route asks for. */
    ability: string;
}

const ENTRIES: PanelEntry[] = [
    {
        key: 'dashboard',
        label: 'dashboard',
        route: 'platform.dashboard',
        active: 'platform.dashboard',
        icon: 'layout-dashboard',
        ability: 'platform.dashboard.view',
    },
    {
        key: 'tenants',
        label: 'tenants',
        route: 'platform.tenants.index',
        active: 'platform.tenants.*',
        icon: 'building-2',
        ability: 'platform.tenants.view',
    },
    {
        key: 'plans',
        label: 'plans',
        route: 'platform.plans.index',
        active: 'platform.plans.*',
        icon: 'layers',
        ability: 'platform.plans.view',
    },
    {
        key: 'invoices',
        label: 'invoices',
        route: 'platform.invoices.index',
        active: 'platform.invoices.*',
        icon: 'receipt',
        ability: 'platform.billing.view',
    },
    {
        key: 'audit',
        label: 'platformAudit',
        route: 'platform.audit.index',
        active: 'platform.audit.*',
        icon: 'scroll-text',
        ability: 'platform.audit.view',
    },
    {
        key: 'settings',
        label: 'platformSettings',
        route: 'platform.settings.edit',
        active: 'platform.settings.*',
        icon: 'settings',
        ability: 'platform.settings.manage',
    },
];

/*
 * An entry is listed only when the operator's role holds the ability its
 * route asks for. This hides what would answer 403; the route still checks.
 */
const sections = computed<ShellNavSection[]>(() => {
    const abilities = new Set(page.props.auth?.abilities ?? []);

    const items = ENTRIES.filter((entry) => abilities.has(entry.ability)).map(
        (entry): ShellNavItem => ({
            key: entry.key,
            label: t(`shell.nav.${entry.label}`),
            href: route(entry.route),
            icon: entry.icon,
            // Recomputed on every visit because it reads the current URL.
            current: page.url !== undefined && isCurrentRoute(entry.active),
        }),
    );

    return items.length > 0 ? [{ key: 'platform', label: t('shell.nav.sections.platform'), items }] : [];
});

/** The operator's role, under their name in the account menu. */
const roleLabel = computed(() => {
    const role = user.value?.role;

    if (!role) {
        return undefined;
    }

    const key = `platform.roles.${role}`;

    // A role this build has no label for is shown as the server named it rather than hidden.
    return te(key) || te(key, 'en') ? t(key) : role;
});

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
            <LocaleSwitch />
            <ThemeToggle />
            <UserMenu v-if="user" :user="user" :caption="roleLabel" :logout-url="route('platform.logout')" />
        </template>

        <slot />
    </AppShell>
</template>
