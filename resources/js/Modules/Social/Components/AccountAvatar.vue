<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import { initialsOf } from './preview/postPreview';

/**
 * A connected account's profile picture, loaded straight from the platform (no referrer), or its initials when
 * there is no picture, the address is not http(s), or the picture does not load.
 */
const props = withDefaults(
    defineProps<{
        account: { name: string; avatarUrl?: string | null };
        size?: 'sm' | 'md';
    }>(),
    { size: 'md' },
);

const { t } = useI18n();
const failed = ref(false);

const src = computed(() => {
    const url = (props.account.avatarUrl ?? '').trim();

    return /^https?:\/\/[^\s/?#]+/i.test(url) ? url : null;
});

watch(src, () => {
    failed.value = false;
});

const SIZES = { sm: 'size-7', md: 'size-9' } as const;
</script>

<template>
    <img
        v-if="src && !failed"
        :src="src"
        :alt="t('social.composer.avatarAlt', { name: account.name })"
        referrerpolicy="no-referrer"
        loading="lazy"
        decoding="async"
        :class="['inline-block shrink-0 rounded-full bg-hover object-cover ring-1 ring-line', SIZES[size]]"
        data-testid="account-avatar"
        @error="failed = true"
    />
    <Avatar v-else :initials="initialsOf(account.name)" :name="account.name" :size="size" />
</template>
