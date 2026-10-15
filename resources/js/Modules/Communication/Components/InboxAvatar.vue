<script setup lang="ts">
import { UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import { avatarTint, initialsOf } from './inboxChat';

/**
 * A conversation's avatar in the unified inbox: one or two initials from its title on a tint picked from its key, so a
 * conversation keeps its colour in the list and in the open chat. No initials (a phone number) shows a person icon.
 * Decorative: the title is always written next to it.
 */
const props = withDefaults(defineProps<{ title: string; seed: string; size?: 'md' | 'lg' }>(), { size: 'lg' });

const initials = computed(() => initialsOf(props.title));
const tint = computed(() => avatarTint(props.seed));
</script>

<template>
    <span
        :class="[
            'inline-flex shrink-0 items-center justify-center rounded-full font-semibold tracking-wide select-none',
            'shadow-[inset_0_1px_0_var(--glass-edge)] ring-1 ring-line',
            size === 'lg' ? 'size-11 text-sm' : 'size-9 text-xs',
            tint,
        ]"
        :data-tint="tint"
        aria-hidden="true"
    >
        <template v-if="initials">{{ initials }}</template>
        <UserRound v-else :class="size === 'lg' ? 'size-5' : 'size-4'" aria-hidden="true" />
    </span>
</template>
