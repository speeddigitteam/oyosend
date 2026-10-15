<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AccountAvatar from '../AccountAvatar.vue';
import PlatformIcon from '../PlatformIcon.vue';
import type { AccountOption } from '../types';

/**
 * Who the previewed post comes from: the account's profile picture (initials when it has none or it does not load),
 * its name and the "Just now" a fresh post shows. `inline` puts the handle on the name line, as X does.
 */
withDefaults(
    defineProps<{
        account: AccountOption;
        inline?: boolean;
        /** Leave out "Just now" (the time is shown elsewhere in the layout). */
        timeless?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { inline: false, timeless: false, size: 'md' },
);

const { t } = useI18n();
</script>

<template>
    <div class="flex min-w-0 items-center gap-2.5" data-testid="preview-author">
        <span class="relative shrink-0">
            <AccountAvatar :account="account" :size="size" />
            <span class="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full bg-control text-ink-muted ring-1 ring-line">
                <PlatformIcon :platform="account.platform" class="size-2.5" />
            </span>
        </span>
        <span v-if="inline" class="flex min-w-0 flex-wrap items-baseline gap-x-1 text-sm">
            <span class="truncate font-semibold text-ink">{{ account.name }}</span>
            <span v-if="account.handle" class="truncate text-ink-subtle">{{ account.handle }}</span>
            <span v-if="!timeless" class="text-ink-subtle" aria-hidden="true">&middot;</span>
            <span v-if="!timeless" class="text-ink-subtle">{{ t('social.preview.justNow') }}</span>
        </span>
        <span v-else class="grid min-w-0">
            <span class="truncate text-sm font-semibold text-ink">{{ account.name }}</span>
            <span v-if="!timeless" class="truncate text-xs text-ink-subtle">{{ t('social.preview.justNow') }}</span>
            <span v-else-if="account.handle" class="truncate text-xs text-ink-subtle">{{ account.handle }}</span>
        </span>
    </div>
</template>
