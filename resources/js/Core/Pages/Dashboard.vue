<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Blocks } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import ActivityWidget from '@/Core/Components/Widgets/ActivityWidget.vue';
import SecurityWidget from '@/Core/Components/Widgets/SecurityWidget.vue';
import TeamWidget from '@/Core/Components/Widgets/TeamWidget.vue';
import PipelineWidget from '@/Modules/CRM/Components/PipelineWidget.vue';
import HrmOverviewWidget from '@/Modules/Hrm/Components/HrmOverviewWidget.vue';
import HrmSelfWidget from '@/Modules/Hrm/Components/HrmSelfWidget.vue';
import PayrollOverviewWidget from '@/Modules/Payroll/Components/PayrollOverviewWidget.vue';
import PayrollSelfWidget from '@/Modules/Payroll/Components/PayrollSelfWidget.vue';
import ProjectsOverviewWidget from '@/Modules/Projects/Components/ProjectsOverviewWidget.vue';
import NotificationsWidget from '@/Modules/Communication/Components/NotificationsWidget.vue';
import SocialOverviewWidget from '@/Modules/Social/Components/SocialOverviewWidget.vue';
import SupportOverviewWidget from '@/Modules/Support/Components/SupportOverviewWidget.vue';
import AiUsageWidget from '@/Modules/Ai/Components/AiUsageWidget.vue';
import TalentOverviewWidget from '@/Modules/Talent/Components/TalentOverviewWidget.vue';
import TalentSelfWidget from '@/Modules/Talent/Components/TalentSelfWidget.vue';
import { usePageTitle } from '@/Core/composables/usePageTitle';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';

defineOptions({ layout: TenantLayout });

interface Widget {
    key: string;
    /** Name of the component that renders it; see WIDGETS below. */
    component: string;
    /** Columns out of 12 on wide screens. */
    span: number;
    data: Record<string, unknown>;
}

defineProps<{
    /** Already filtered on the server to what this person may see. */
    widgets: Widget[];
    installedApps: number;
}>();

/*
 * Widgets are registered by modules on the server (WidgetDefinition) and
 * resolved to components here. A module adds its widget components to this map.
 */
const WIDGETS: Record<string, Component> = {
    TeamWidget,
    SecurityWidget,
    ActivityWidget,
    PipelineWidget,
    ProjectsOverviewWidget,
    HrmSelfWidget,
    HrmOverviewWidget,
    TalentSelfWidget,
    TalentOverviewWidget,
    PayrollSelfWidget,
    PayrollOverviewWidget,
    SocialOverviewWidget,
    SupportOverviewWidget,
    AiUsageWidget,
    NotificationsWidget,
};

// Tailwind needs to see whole class names, so spans are looked up, not built.
const SPANS: Record<number, string> = {
    3: 'lg:col-span-3',
    4: 'lg:col-span-4',
    5: 'lg:col-span-5',
    6: 'lg:col-span-6',
    7: 'lg:col-span-7',
    8: 'lg:col-span-8',
    12: 'lg:col-span-12',
};

const page = usePage();
const { t } = useI18n();

usePageTitle().setPageTitle(t('dashboard.title'));

const greeting = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: page.props.app.timezone }).format(new Date()),
    );
    const part = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';

    return t(`dashboard.greeting.${part}`, { name: page.props.auth?.user?.name.split(' ')[0] ?? '' });
});
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div class="mb-7">
        <p class="eyebrow mb-2">{{ t('dashboard.title') }}</p>
        <h1 class="text-[1.75rem] text-ink sm:text-[2.125rem]">{{ greeting }}</h1>
        <p class="mt-1.5 text-ink-muted">{{ t('dashboard.subtitle', { workspace: page.props.tenant?.name }) }}</p>
    </div>

    <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">
        <div v-for="widget in widgets" :key="widget.key" :class="['min-w-0', SPANS[widget.span] ?? 'lg:col-span-6']">
            <component :is="WIDGETS[widget.component]" v-if="WIDGETS[widget.component]" :data="widget.data" />
        </div>

        <Card v-if="installedApps === 0" class="lg:col-span-12" :title="t('dashboard.apps.title')">
            <EmptyState :title="t('dashboard.apps.empty')" :description="t('dashboard.apps.emptyBody')" compact>
                <template #icon><Blocks /></template>
            </EmptyState>
        </Card>
    </div>
</template>
