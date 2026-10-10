<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'customers' | 'products' | 'priceLists' | 'quotes' | 'orders' | 'contracts' | 'targets' | 'reports' }>();
const { t } = useI18n(); const { can } = useAccess();
const items = computed(() => [
    { value: 'customers', label: t('crm.sales.customers.title'), permission: 'crm.customers.view', route: 'crm.sales.customers.index' },
    { value: 'products', label: t('crm.nav.catalogue'), permission: 'crm.products.view', route: 'crm.sales.products.index' },
    { value: 'priceLists', label: t('crm.sales.priceLists.title'), permission: 'crm.products.view', route: 'crm.sales.price-lists.index' },
    { value: 'quotes', label: t('crm.nav.quotes'), permission: 'crm.quotes.view', route: 'crm.sales.quotes.index' },
    { value: 'orders', label: t('crm.nav.orders'), permission: 'crm.orders.view', route: 'crm.sales.orders.index' },
    { value: 'contracts', label: t('crm.nav.contracts'), permission: 'crm.contracts.view', route: 'crm.sales.contracts.index' },
    { value: 'targets', label: t('crm.nav.targets'), permission: 'crm.targets.view', route: 'crm.sales.targets.index' },
    { value: 'reports', label: t('crm.nav.reports'), permission: 'crm.reports.view', route: 'crm.sales.reports.index' },
].filter((item) => can(item.permission)));
</script>

<template>
    <nav :aria-label="t('crm.nav.label')" class="mb-4 overflow-x-auto pb-1"><div class="flex min-w-max gap-1 rounded-control bg-hover p-1"><Button v-for="item in items" :key="item.value" size="sm" :variant="item.value === current ? 'secondary' : 'ghost'" :href="route(item.route)">{{ item.label }}</Button></div></nav>
</template>
