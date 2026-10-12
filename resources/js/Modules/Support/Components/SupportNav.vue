<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'tickets' | 'kb' | 'canned' | 'reports' | 'queues' | 'sla' | 'settings' }>();

const { t } = useI18n();
const { can } = useAccess();

const items = computed(() =>
    [
        { value: 'tickets', name: 'support.tickets.index', permission: 'support.tickets.view' },
        { value: 'kb', name: 'support.kb.index', permission: 'support.kb.view' },
        { value: 'canned', name: 'support.canned.index', permission: 'support.canned.view' },
        { value: 'reports', name: 'support.reports.index', permission: 'support.reports.view' },
        { value: 'queues', name: 'support.queues.index', permission: 'support.queues.manage' },
        { value: 'sla', name: 'support.sla.index', permission: 'support.sla.manage' },
        { value: 'settings', name: 'support.settings.index', permission: 'support.settings.manage' },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <nav :aria-label="t('support.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`support.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>
