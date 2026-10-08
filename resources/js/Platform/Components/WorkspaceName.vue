<script setup lang="ts">
import { computed } from 'vue';
import Avatar from '@/Core/Components/Avatar.vue';

/**
 * A workspace's name with its tile, as the workspace switcher shows it. The
 * initials are worked out here because the panel only receives the name.
 */
const props = defineProps<{ name: string }>();

/** First user-perceived character, so a Bengali conjunct is not cut in half. */
function firstCharacter(word: string): string {
    if (typeof Intl.Segmenter === 'function') {
        for (const { segment } of new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(word)) {
            return segment;
        }
    }

    return Array.from(word)[0] ?? '';
}

const initials = computed(() => {
    const words = props.name.split(/\s+/).filter((word) => /^[\p{L}\p{N}]/u.test(word));

    return (words.length > 0 ? words : [props.name.trim()]).slice(0, 2).map(firstCharacter).join('');
});
</script>

<template>
    <span class="flex items-center gap-2.5">
        <Avatar :initials="initials" :name="name" size="sm" square />
        <span class="min-w-0 font-medium text-ink">{{ name }}</span>
    </span>
</template>
