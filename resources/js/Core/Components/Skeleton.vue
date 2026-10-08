<script setup lang="ts">
/**
 * Loading placeholder. Size it with utility classes (`h-4 w-32`); use
 * `circle` for avatars.
 */
defineProps<{ circle?: boolean }>();
</script>

<template>
    <span
        :class="[
            'block animate-shimmer bg-[linear-gradient(90deg,var(--hover)_0%,var(--line-strong)_45%,var(--hover)_90%)] bg-[length:200%_100%]',
            circle ? 'rounded-full' : 'rounded-chip',
        ]"
        aria-hidden="true"
    />
</template>
