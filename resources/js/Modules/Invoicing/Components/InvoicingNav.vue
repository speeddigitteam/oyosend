<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'invoices' | 'billable' | 'recurring' | 'reports' | 'settings' }>();

const { t } = useI18n();
const { can } = useAccess();

const items = computed(() => [
    { value: 'invoices', name: 'invoicing.invoices.index', permission: 'invoicing.invoices.view' },
    { value: 'billable', name: 'invoicing.billable-time.index', permission: 'invoicing.invoices.edit' },
    { value: 'recurring', name: 'invoicing.recurring.index', permission: 'invoicing.recurring.view' },
    { value: 'reports', name: 'invoicing.reports.index', permission: 'invoicing.reports.view' },
    { value: 'settings', name: 'invoicing.settings.index', permission: 'invoicing.settings.manage' },
].filter((item) => can(item.permission)));
</script>

<template>
    <nav :aria-label="t('invoicing.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`invoicing.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>
