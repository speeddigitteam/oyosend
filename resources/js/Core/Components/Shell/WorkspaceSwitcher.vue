<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownLabel from '@/Core/Components/DropdownLabel.vue';
import { route } from '@/Core/lib/route';

/**
 * Shows the current workspace and lets someone who belongs to several move
 * between them. Each workspace has its own sign-in session, so switching
 * asks the server for a one-time handoff and does a full page visit.
 */
const page = usePage();
const { t } = useI18n();

const tenant = computed(() => page.props.tenant);
const workspaces = computed(() => page.props.workspaces ?? []);
const others = computed(() => workspaces.value.filter((workspace) => !workspace.current));

function initialsOf(name: string): string {
    return name
        .split(/\s+/u)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => [...part][0]?.toLocaleUpperCase() ?? '')
        .join('');
}

function switchTo(slug: string): void {
    router.post(route('core.workspaces.switch', { workspace: slug }));
}
</script>

<template>
    <Dropdown v-if="tenant" align="start" width="w-[14rem]">
        <template #trigger>
            <button
                type="button"
                class="flex w-full items-center gap-2.5 rounded-control p-1.5 text-left transition-colors hover:bg-hover"
                :aria-label="t('shell.workspace.switch')"
            >
                <!-- `mark`: what stands for the workspace - its logo, when the layout has one. -->
                <slot name="mark">
                    <Avatar :initials="initialsOf(tenant.name)" :name="tenant.slug" square />
                </slot>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm leading-tight font-semibold text-ink">{{ tenant.name }}</span>
                    <span class="mt-0.5 block truncate font-mono text-[0.6875rem] leading-tight text-ink-subtle">{{ tenant.slug }}</span>
                </span>
                <ChevronsUpDown class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            </button>
        </template>

        <DropdownLabel>{{ t('shell.workspace.yours') }}</DropdownLabel>

        <DropdownItem active disabled>
            <template #icon><Avatar :initials="initialsOf(tenant.name)" :name="tenant.slug" size="sm" square /></template>
            {{ tenant.name }}
            <template #trailing><Check class="size-4 text-brand-text" aria-hidden="true" /></template>
        </DropdownItem>

        <DropdownItem v-for="workspace in others" :key="workspace.slug" @select="switchTo(workspace.slug)">
            <template #icon><Avatar :initials="initialsOf(workspace.name)" :name="workspace.slug" size="sm" square /></template>
            {{ workspace.name }}
        </DropdownItem>

        <p v-if="others.length === 0" class="px-2.5 pt-1 pb-2 text-[0.8125rem] text-ink-subtle">
            {{ t('shell.workspace.only') }}
        </p>
    </Dropdown>
</template>
