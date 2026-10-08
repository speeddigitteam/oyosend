<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/Core/composables/useTheme';

const { resolved, toggle } = useTheme();
const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        class="flex size-9 shrink-0 items-center justify-center rounded-control text-ink-muted transition-colors hover:bg-hover hover:text-ink"
        :aria-label="resolved === 'dark' ? t('common.theme.switchToLight') : t('common.theme.switchToDark')"
        :title="resolved === 'dark' ? t('common.theme.switchToLight') : t('common.theme.switchToDark')"
        @click="toggle"
    >
        <Sun v-if="resolved === 'dark'" class="size-[1.125rem]" aria-hidden="true" />
        <Moon v-else class="size-[1.125rem]" aria-hidden="true" />
    </button>
</template>
