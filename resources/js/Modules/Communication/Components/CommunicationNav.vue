<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bell, FileText, Inbox, Send, Settings, ShieldCheck } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

type Section = 'inbox' | 'notifications' | 'messages' | 'templates' | 'audience' | 'settings';

/**
 * The Communication sections. `tabs` (default) is the row of buttons above a page; `rail` is the narrow
 * column of icons the inbox's chat screen keeps on its left edge, the way a desktop messenger does, with
 * Settings pinned to the bottom. The rail shows from the `md` breakpoint; below it the page keeps the tabs.
 */
const props = withDefaults(defineProps<{ current: Section; variant?: 'tabs' | 'rail' }>(), { variant: 'tabs' });

const { t } = useI18n();
const { can } = useAccess();

const items = computed(() =>
    (
        [
            { value: 'inbox', name: 'communication.inbox.index', permission: 'communication.inbox.view', icon: Inbox },
            { value: 'notifications', name: 'communication.notifications.index', permission: 'communication.notifications.view', icon: Bell },
            { value: 'messages', name: 'communication.messages.index', permission: 'communication.messages.view', icon: Send },
            { value: 'templates', name: 'communication.templates.index', permission: 'communication.templates.view', icon: FileText },
            { value: 'audience', name: 'communication.audience.index', permission: 'communication.audience.view', icon: ShieldCheck },
            { value: 'settings', name: 'communication.settings.index', permission: 'communication.settings.manage', icon: Settings },
        ] satisfies Array<{ value: Section; name: string; permission: string; icon: Component }>
    ).filter((item) => can(item.permission)),
);

const sections = computed(() => items.value.filter((item) => item.value !== 'settings'));
const settings = computed(() => items.value.find((item) => item.value === 'settings') ?? null);

const railClass = (active: boolean): string[] => [
    'relative flex size-10 items-center justify-center rounded-control transition-colors',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand',
    active ? 'bg-brand-soft text-brand-text' : 'text-ink-muted hover:bg-hover hover:text-ink',
];
</script>

<template>
    <nav v-if="props.variant === 'rail'" :aria-label="t('communication.nav.label')" class="hidden w-14 shrink-0 flex-col items-center gap-1 border-r border-line py-3 md:flex" data-testid="communication-rail">
        <Link
            v-for="item in sections"
            :key="item.value"
            :href="route(item.name)"
            :class="railClass(item.value === current)"
            :title="t(`communication.nav.${item.value}`)"
            :aria-current="item.value === current ? 'page' : undefined"
        >
            <component :is="item.icon" class="size-5" aria-hidden="true" />
            <span class="sr-only">{{ t(`communication.nav.${item.value}`) }}</span>
        </Link>
        <template v-if="settings">
            <span class="mt-auto mb-1 h-px w-6 bg-line" aria-hidden="true" />
            <Link
                :href="route(settings.name)"
                :class="railClass(current === 'settings')"
                :title="t('communication.nav.settings')"
                :aria-current="current === 'settings' ? 'page' : undefined"
            >
                <Settings class="size-5" aria-hidden="true" />
                <span class="sr-only">{{ t('communication.nav.settings') }}</span>
            </Link>
        </template>
    </nav>

    <nav v-else :aria-label="t('communication.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`communication.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>
