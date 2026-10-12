<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'assistant' | 'usage' | 'settings' }>();

const { t } = useI18n();
const { can } = useAccess();

const items = computed(() =>
    [
        { value: 'assistant', name: 'ai.assistant.index', permission: 'ai.assistant.use' },
        { value: 'usage', name: 'ai.usage.index', permission: 'ai.usage.view' },
        { value: 'settings', name: 'ai.settings.index', permission: 'ai.settings.manage' },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <nav :aria-label="t('ai.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`ai.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>
