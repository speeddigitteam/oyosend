<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'inbox' | 'campaigns' | 'templates' | 'lists' | 'quickReplies' | 'analytics' | 'flows' | 'settings' }>();

const { t } = useI18n();
const { can } = useAccess();

const items = computed(() =>
    [
        { value: 'inbox', name: 'whatsapp_marketing.inbox.index', permission: 'whatsapp_marketing.conversations.view' },
        { value: 'campaigns', name: 'whatsapp_marketing.campaigns.index', permission: 'whatsapp_marketing.campaigns.view' },
        { value: 'templates', name: 'whatsapp_marketing.templates.index', permission: 'whatsapp_marketing.templates.view' },
        { value: 'lists', name: 'whatsapp_marketing.lists.index', permission: 'whatsapp_marketing.lists.view' },
        { value: 'quickReplies', name: 'whatsapp_marketing.quick-replies.index', permission: 'whatsapp_marketing.quick_replies.view' },
        { value: 'analytics', name: 'whatsapp_marketing.analytics.index', permission: 'whatsapp_marketing.analytics.view' },
        { value: 'flows', name: 'whatsapp_marketing.flows.index', permission: 'whatsapp_marketing.flows.manage' },
        { value: 'settings', name: 'whatsapp_marketing.settings.index', permission: 'whatsapp_marketing.settings.manage' },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <nav :aria-label="t('whatsapp.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`whatsapp.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>
