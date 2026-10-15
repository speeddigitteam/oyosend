<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'dialer' | 'calls' | 'settings' }>();

const { t } = useI18n();
const { can } = useAccess();

const items = computed(() =>
    [
        { value: 'dialer', name: 'telephony.dialer', permission: 'telephony.calls.make' },
        { value: 'calls', name: 'telephony.calls.index', permission: 'telephony.calls.view' },
        { value: 'settings', name: 'telephony.settings.index', permission: 'telephony.settings.manage' },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <nav :aria-label="t('telephony.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`telephony.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>
