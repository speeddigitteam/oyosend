<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LogOut, Monitor, Moon, Sun } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import { useTheme, type ThemePreference } from '@/Core/composables/useTheme';
import type { AuthUser } from '@/Core/types';

/**
 * Account menu for the top bar. Area-specific links (profile, security) go
 * in the default slot as DropdownItems.
 */
const props = defineProps<{
    user: AuthUser;
    /** Small caption under the email: a role or "Owner". */
    caption?: string;
    /** POST endpoint that ends the session. */
    logoutUrl: string;
}>();

const { t } = useI18n();
const { preference, set } = useTheme();

const themes: Array<{ value: ThemePreference; label: string; icon: typeof Sun }> = [
    { value: 'light', label: t('common.theme.light'), icon: Sun },
    { value: 'dark', label: t('common.theme.dark'), icon: Moon },
    { value: 'system', label: t('common.theme.system'), icon: Monitor },
];

function signOut(): void {
    router.post(props.logoutUrl);
}
</script>

<template>
    <Dropdown width="w-64">
        <template #trigger>
            <button
                type="button"
                class="flex shrink-0 items-center rounded-full transition-shadow hover:shadow-[0_0_0_3px_var(--hover)]"
                :aria-label="t('shell.account.menu')"
            >
                <Avatar :initials="user.initials" :name="user.email" />
            </button>
        </template>

        <div class="flex items-center gap-3 px-2.5 pt-2 pb-2.5">
            <Avatar :initials="user.initials" :name="user.email" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ user.name }}</p>
                <p class="truncate text-[0.8125rem] text-ink-subtle">{{ user.email }}</p>
                <p v-if="caption" class="eyebrow mt-1">{{ caption }}</p>
            </div>
        </div>

        <DropdownSeparator />

        <slot />

        <div class="flex items-center justify-between gap-3 px-2.5 py-2">
            <span class="text-sm text-ink">{{ t('common.theme.label') }}</span>
            <SegmentedControl
                :model-value="preference"
                :options="themes"
                :label="t('common.theme.label')"
                icon-only
                @update:model-value="set($event)"
            />
        </div>

        <DropdownSeparator />

        <DropdownItem tone="danger" @select="signOut">
            <template #icon><LogOut aria-hidden="true" /></template>
            {{ t('shell.account.signOut') }}
        </DropdownItem>
    </Dropdown>
</template>
