<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { KeyRound, LogOut, Moon, UserRound } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import Logo from '@/Core/Components/Logo.vue';
import AppShell from '@/Core/Components/Shell/AppShell.vue';
import Breadcrumb from '@/Core/Components/Shell/Breadcrumb.vue';
import type { Command } from '@/Core/Components/Shell/CommandPalette.vue';
import LocaleSwitch from '@/Core/Components/Shell/LocaleSwitch.vue';
import type { ShellNavSection } from '@/Core/Components/Shell/SidebarNav.vue';
import SubscriptionNotice from '@/Core/Components/Shell/SubscriptionNotice.vue';
import SupportSessionBanner from '@/Core/Components/Shell/SupportSessionBanner.vue';
import ThemeToggle from '@/Core/Components/Shell/ThemeToggle.vue';
import UserMenu from '@/Core/Components/Shell/UserMenu.vue';
import WorkspaceSwitcher from '@/Core/Components/Shell/WorkspaceSwitcher.vue';
import { useTheme } from '@/Core/composables/useTheme';
import { isCurrentRoute, route } from '@/Core/lib/route';

/**
 * Layout of every signed-in workspace page. The navigation arrives from the
 * server already filtered to what this person may open in this workspace.
 *
 * Above the page it carries the two things that are true of every page: the
 * support-session banner and, when the plan needs attention, the subscription
 * notice.
 */
const page = usePage();
const { t, te } = useI18n();
const { toggle } = useTheme();

const user = computed(() => page.props.auth?.user ?? null);

/*
 * A support session works in the workspace, not on the person it is signed in
 * as: their language is not its to change, and leaving goes through the route
 * that records the end of the session rather than through an ordinary sign-out.
 */
const inSupportSession = computed(() => page.props.supportSession != null);
const logoutUrl = computed(() => route(inSupportSession.value ? 'auth.impersonate.stop' : 'auth.logout'));

/*
 * The workspace's logo, for the brand area of the sidebar.
 *
 * The server signs a fresh URL for the same file on every response. Following
 * each one would download the logo again on every visit, so the URL in use is
 * kept until the file itself changes, until it is old enough to be running out
 * (the server signs them for an hour), or until it fails to load - in which
 * case the initials are shown and the next response is given a new try.
 */
const LOGO_URL_MAX_AGE = 30 * 60 * 1000;

const logoUrl = ref<string | null>(page.props.tenant?.logoUrl ?? null);
let logoUrlTakenAt = Date.now();

/** The file a signed URL points at: the URL without its signature. */
function fileOf(url: string): string {
    try {
        const parsed = new URL(url, window.location.origin);

        return parsed.origin + parsed.pathname;
    } catch {
        return url;
    }
}

watch(
    () => page.props.tenant?.logoUrl ?? null,
    (next) => {
        const current = logoUrl.value;

        if (next !== null && current !== null && fileOf(next) === fileOf(current) && Date.now() - logoUrlTakenAt < LOGO_URL_MAX_AGE) {
            return;
        }

        logoUrl.value = next;
        logoUrlTakenAt = Date.now();
    },
);

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

// The owner's own profile and security pages are closed to a support session.
const personalCommands = computed<Command[]>(() =>
    inSupportSession.value
        ? []
        : [
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
          ],
);

const commands = computed<Command[]>(() => [
    ...personalCommands.value,
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
        perform: () => router.post(logoutUrl.value),
    },
]);
</script>

<template>
    <AppShell :sections="sections" :commands="commands">
        <template #brand>
            <WorkspaceSwitcher>
                <!-- Without a logo the switcher keeps its own mark: the workspace's initials. -->
                <template v-if="logoUrl && page.props.tenant" #mark>
                    <!-- White behind it in both themes: logos are drawn for a light page. -->
                    <img
                        :src="logoUrl"
                        :alt="page.props.tenant.name"
                        width="36"
                        height="36"
                        decoding="async"
                        class="size-9 shrink-0 rounded-[0.5625rem] bg-white object-contain ring-1 ring-line"
                        @error="logoUrl = null"
                    />
                </template>
            </WorkspaceSwitcher>
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
            <LocaleSwitch v-if="!inSupportSession" />
            <ThemeToggle />
            <UserMenu
                v-if="user"
                :user="user"
                :caption="page.props.auth?.membership?.isOwner ? t('shell.account.owner') : undefined"
                :logout-url="logoutUrl"
            >
                <template v-if="!inSupportSession">
                    <DropdownItem :href="route('core.settings.profile')">
                        <template #icon><UserRound aria-hidden="true" /></template>
                        {{ t('shell.account.profile') }}
                    </DropdownItem>
                    <DropdownItem :href="route('core.settings.security')">
                        <template #icon><KeyRound aria-hidden="true" /></template>
                        {{ t('shell.account.security') }}
                    </DropdownItem>
                    <DropdownSeparator />
                </template>
            </UserMenu>
        </template>

        <!-- Direct children of the page column: the banner is sticky and needs the whole page to travel in. -->
        <SupportSessionBanner />
        <SubscriptionNotice />

        <slot />
    </AppShell>
</template>
