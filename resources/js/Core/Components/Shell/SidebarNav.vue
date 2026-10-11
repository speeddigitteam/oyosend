<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, useId } from 'vue';
import Icon from '@/Core/Components/Icon.vue';
import { useNavGroups } from '@/Core/composables/useNavGroups';

export interface ShellNavItem {
    key: string;
    label: string;
    href: string;
    icon: string;
    current: boolean;
    /** Entries that share a group are shown under one collapsible header. */
    group?: { key: string; label: string };
}

export interface ShellNavSection {
    key: string;
    label: string;
    items: ShellNavItem[];
}

interface GroupBlock {
    kind: 'group';
    id: string;
    label: string;
    items: ShellNavItem[];
    hasCurrent: boolean;
}

type Block = { kind: 'item'; item: ShellNavItem } | GroupBlock;

const props = defineProps<{ sections: ShellNavSection[] }>();

const uid = useId();
const { isCollapsed, toggle } = useNavGroups();

/** Consecutive entries of one group become a block; the server already keeps a module's entries together. */
const blocks = computed(() =>
    props.sections.map((section) => {
        const list: Block[] = [];

        for (const item of section.items) {
            const last = list[list.length - 1];

            if (item.group === undefined) {
                list.push({ kind: 'item', item });
            } else if (last?.kind === 'group' && last.id === `${section.key}:${item.group.key}`) {
                last.items.push(item);
                last.hasCurrent ||= item.current;
            } else {
                list.push({
                    kind: 'group',
                    id: `${section.key}:${item.group.key}`,
                    label: item.group.label,
                    items: [item],
                    hasCurrent: item.current,
                });
            }
        }

        return { section, blocks: list };
    }),
);

/** The group holding the current page is always open, whatever was stored. */
function isOpen(block: GroupBlock): boolean {
    return block.hasCurrent || !isCollapsed(block.id);
}

function onToggle(block: GroupBlock): void {
    if (!block.hasCurrent) {
        toggle(block.id);
    }
}

function elementId(block: GroupBlock): string {
    return `${uid}-${block.id.replace(/[^a-zA-Z0-9_-]/g, '_')}`;
}

function linkClass(current: boolean): string[] {
    return [
        'group relative flex h-9 items-center gap-2.5 rounded-control px-2.5 text-sm transition-colors',
        current
            ? 'bg-active font-medium text-ink shadow-[inset_0_1px_0_var(--glass-edge),0_1px_2px_rgb(0_0_0/0.07)]'
            : 'text-ink-muted hover:bg-hover hover:text-ink',
    ];
}
</script>

<template>
    <nav :aria-label="$t('shell.nav.primary')" class="flex flex-col gap-5">
        <div v-for="entry in blocks" :key="entry.section.key">
            <p class="eyebrow px-2.5 pb-1.5">{{ entry.section.label }}</p>
            <ul class="flex flex-col gap-0.5">
                <template v-for="block in entry.blocks" :key="block.kind === 'item' ? block.item.key : block.id">
                    <li v-if="block.kind === 'item'">
                        <Link :href="block.item.href" :aria-current="block.item.current ? 'page' : undefined" :class="linkClass(block.item.current)">
                            <Icon
                                :name="block.item.icon"
                                :class="['size-[1.0625rem] shrink-0', block.item.current ? 'text-brand-text' : 'text-ink-subtle group-hover:text-ink-muted']"
                            />
                            <span class="min-w-0 flex-1 truncate">{{ block.item.label }}</span>
                            <span v-if="block.item.current" class="size-1.5 rounded-full bg-accent" aria-hidden="true" />
                        </Link>
                    </li>
                    <li v-else class="flex flex-col gap-0.5">
                        <button
                            type="button"
                            data-nav-group
                            :aria-expanded="isOpen(block)"
                            :aria-controls="elementId(block)"
                            class="flex h-8 w-full items-center gap-2 rounded-control px-2.5 text-start text-xs font-medium text-ink-subtle transition-colors hover:bg-hover hover:text-ink"
                            @click="onToggle(block)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{ block.label }}</span>
                            <ChevronDown
                                :class="['size-3.5 shrink-0 transition-transform', isOpen(block) ? '' : '-rotate-90 rtl:rotate-90']"
                                aria-hidden="true"
                            />
                        </button>
                        <ul v-show="isOpen(block)" :id="elementId(block)" class="flex flex-col gap-0.5">
                            <li v-for="item in block.items" :key="item.key">
                                <Link :href="item.href" :aria-current="item.current ? 'page' : undefined" :class="linkClass(item.current)">
                                    <Icon
                                        :name="item.icon"
                                        :class="['size-[1.0625rem] shrink-0', item.current ? 'text-brand-text' : 'text-ink-subtle group-hover:text-ink-muted']"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                                    <span v-if="item.current" class="size-1.5 rounded-full bg-accent" aria-hidden="true" />
                                </Link>
                            </li>
                        </ul>
                    </li>
                </template>
            </ul>
        </div>
    </nav>
</template>
