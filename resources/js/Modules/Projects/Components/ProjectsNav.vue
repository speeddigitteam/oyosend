<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

defineProps<{ current: 'dashboard' | 'projects' | 'tasks' | 'time' | 'workload' | 'finance' | 'sprints' | 'approvals' | 'reports' | 'settings' }>();
const { t } = useI18n();
const { canAny } = useAccess();
const items = computed(() => [
    { value: 'projects', key: 'projects', name: 'projects.index', permissions: ['projects.projects.view'] },
    { value: 'time', key: 'time', name: 'projects.time.index', permissions: ['projects.time.view'] },
    { value: 'finance', key: 'expenses', name: 'projects.expenses.index', permissions: ['projects.expenses.view'] },
    { value: 'approvals', key: 'approvals', name: 'projects.approvals.index', permissions: ['projects.approvals.view', 'projects.approvals.request', 'projects.approvals.decide'] },
    { value: 'reports', key: 'reports', name: 'projects.reports.index', permissions: ['projects.reports.view'] },
    { value: 'settings', key: 'settings', name: 'projects.settings.index', permissions: ['projects.settings.manage'] },
].filter((item) => canAny(...item.permissions)));
</script>

<template>
    <nav :aria-label="t('projects.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button v-for="item in items" :key="item.value" size="sm" :variant="item.value === current ? 'secondary' : 'ghost'" :href="route(item.name)" :aria-current="item.value === current ? 'page' : undefined">{{ t(`projects.nav.${item.key}`) }}</Button>
        </div>
    </nav>
</template>

