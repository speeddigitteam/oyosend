<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Icon from '@/Core/Components/Icon.vue';

export interface ShellNavItem {
    key: string;
    label: string;
    href: string;
    icon: string;
    current: boolean;
}

export interface ShellNavSection {
    key: string;
    label: string;
    items: ShellNavItem[];
}

defineProps<{ sections: ShellNavSection[] }>();
</script>

<template>
    <nav :aria-label="$t('shell.nav.primary')" class="flex flex-col gap-5">
        <div v-for="section in sections" :key="section.key">
            <p class="eyebrow px-2.5 pb-1.5">{{ section.label }}</p>
            <ul class="flex flex-col gap-0.5">
                <li v-for="item in section.items" :key="item.key">
                    <Link
                        :href="item.href"
                        :aria-current="item.current ? 'page' : undefined"
                        :class="[
                            'group relative flex h-9 items-center gap-2.5 rounded-control px-2.5 text-sm transition-colors',
                            item.current
                                ? 'bg-active font-medium text-ink shadow-[inset_0_1px_0_var(--glass-edge),0_1px_2px_rgb(0_0_0/0.07)]'
                                : 'text-ink-muted hover:bg-hover hover:text-ink',
                        ]"
                    >
                        <Icon
                            :name="item.icon"
                            :class="['size-[1.0625rem] shrink-0', item.current ? 'text-brand-text' : 'text-ink-subtle group-hover:text-ink-muted']"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        <span v-if="item.current" class="size-1.5 rounded-full bg-accent" aria-hidden="true" />
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
