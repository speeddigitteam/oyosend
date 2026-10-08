<script setup lang="ts">
import { DropdownMenuContent, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';

/**
 * Menu button built on reka-ui (arrow keys, type-ahead, Escape, focus
 * return). Put the control in `#trigger` and DropdownItem / DropdownLabel /
 * DropdownSeparator in the default slot.
 */
withDefaults(
    defineProps<{
        align?: 'start' | 'center' | 'end';
        side?: 'top' | 'right' | 'bottom' | 'left';
        /** Tailwind width class for the menu. */
        width?: string;
    }>(),
    { align: 'end', side: 'bottom', width: 'w-56' },
);

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <DropdownMenuRoot v-model:open="open">
        <DropdownMenuTrigger as-child>
            <slot name="trigger" />
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                :align="align"
                :side="side"
                :side-offset="8"
                :collision-padding="12"
                :class="[
                    'glass-solid z-50 max-h-[min(28rem,var(--reka-dropdown-menu-content-available-height))] overflow-y-auto rounded-card p-1.5',
                    'origin-[var(--reka-dropdown-menu-content-transform-origin)] data-[state=open]:animate-pop focus:outline-none',
                    width,
                ]"
            >
                <slot />
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
