<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { DropdownMenuItem } from 'reka-ui';

/**
 * One entry of a Dropdown. With `href` it navigates (Inertia); otherwise
 * listen for `select`.
 */
withDefaults(
    defineProps<{
        href?: string;
        tone?: 'default' | 'danger';
        disabled?: boolean;
        /** Marks the current choice in a list of options. */
        active?: boolean;
    }>(),
    { tone: 'default', href: undefined },
);

defineEmits<{ select: [event: Event] }>();

const base =
    'flex w-full cursor-pointer items-center gap-2.5 rounded-control px-2.5 py-2 text-left text-sm outline-none select-none transition-colors data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&>svg]:size-4 [&>svg]:shrink-0';

const TONES = {
    default: 'text-ink data-[highlighted]:bg-hover [&>svg]:text-ink-subtle',
    danger: 'text-danger data-[highlighted]:bg-danger-soft',
} as const;
</script>

<template>
    <DropdownMenuItem
        :as-child="Boolean(href)"
        :disabled="disabled"
        :class="href ? undefined : [base, TONES[tone], active ? 'font-medium' : '']"
        @select="$emit('select', $event)"
    >
        <Link v-if="href" :href="href" :class="[base, TONES[tone], active ? 'font-medium' : '']">
            <slot name="icon" />
            <span class="min-w-0 flex-1 truncate"><slot /></span>
            <slot name="trailing" />
        </Link>
        <template v-else>
            <slot name="icon" />
            <span class="min-w-0 flex-1 truncate"><slot /></span>
            <slot name="trailing" />
        </template>
    </DropdownMenuItem>
</template>
