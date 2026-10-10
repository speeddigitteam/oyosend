<script setup lang="ts">
import { PackageOpen, Store } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import AppActionPanel from '@/Core/Components/AppStore/AppActionPanel.vue';
import AppCard from '@/Core/Components/AppStore/AppCard.vue';
import type { StoreApp } from '@/Core/Components/AppStore/types';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

defineProps<{ apps: StoreApp[] }>();

const { t } = useI18n();
</script>

<template>
    <PageHeader :eyebrow="t('shell.nav.sections.apps')" :title="t('appStore.mine.title')" :description="t('appStore.mine.description')">
        <template #actions>
            <Button :href="route('core.apps.index')">
                <template #icon><Store class="size-4" aria-hidden="true" /></template>
                {{ t('appStore.actions.browse') }}
            </Button>
        </template>
    </PageHeader>

    <div v-if="apps.length" class="space-y-5">
        <section v-for="app in apps" :key="app.key" class="grid items-stretch gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <AppCard :app="app" />
            <AppActionPanel :app="app" />
        </section>
    </div>
    <div v-else class="glass overflow-hidden rounded-card">
        <EmptyState :title="t('appStore.empty.mine.title')" :description="t('appStore.empty.mine.body')">
            <template #icon><PackageOpen /></template>
            <Button size="sm" :href="route('core.apps.index')">{{ t('appStore.actions.browse') }}</Button>
        </EmptyState>
    </div>
</template>
