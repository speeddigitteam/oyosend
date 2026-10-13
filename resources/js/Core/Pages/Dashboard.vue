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
import EmailMarketingOverviewWidget from '@/Modules/EmailMarketing/Components/EmailMarketingOverviewWidget.vue';
import SmsMarketingOverviewWidget from '@/Modules/SmsMarketing/Components/SmsMarketingOverviewWidget.vue';
import SupportOverviewWidget from '@/Modules/Support/Components/SupportOverviewWidget.vue';
import WhatsappOverviewWidget from '@/Modules/WhatsappMarketing/Components/WhatsappOverviewWidget.vue';
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

const props = defineProps<{
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
    EmailMarketingOverviewWidget,
    SmsMarketingOverviewWidget,
    SupportOverviewWidget,
    WhatsappOverviewWidget,
    AiUsageWidget,
    NotificationsWidget,
};

// Tailwind needs to see whole class names, so spans are looked up, not built.
const XL_SPANS: Record<number, string> = {
    1: 'xl:col-span-1',
    2: 'xl:col-span-2',
    3: 'xl:col-span-3',
    4: 'xl:col-span-4',
    5: 'xl:col-span-5',
    6: 'xl:col-span-6',
    7: 'xl:col-span-7',
    8: 'xl:col-span-8',
    9: 'xl:col-span-9',
    10: 'xl:col-span-10',
    11: 'xl:col-span-11',
    12: 'xl:col-span-12',
};
const MD_SPANS: Record<number, string> = { 1: 'md:col-span-1', 2: 'md:col-span-2' };

/**
 * Packs widgets, in their given order, into rows of `total` units and hands
 * the unused units of each row to the cards in it, so every row is full and
 * no card is left narrower than its neighbours' row. Order is never changed,
 * which keeps reading and tab order equal to the DOM order.
 */
function pack(weights: number[], total: number): number[] {
    const result: number[] = [];
    let row: number[] = [];
    let used = 0;

    const flush = (): void => {
        if (row.length === 0) return;
        const extra = total - used;
        const share = Math.floor(extra / row.length);
        let rest = extra - share * row.length;
        for (const weight of row) {
            result.push(weight + share + (rest > 0 ? 1 : 0));
            rest = Math.max(0, rest - 1);
        }
        row = [];
        used = 0;
    };

    for (const raw of weights) {
        const weight = Math.min(total, Math.max(1, Math.round(raw)));
        if (used + weight > total) flush();
        row.push(weight);
        used += weight;
        if (used >= total) flush();
    }
    flush();

    return result;
}

/** Two columns on tablets (wide widgets take both), twelve on wide screens. */
const layout = computed(() => {
    const xl = pack(props.widgets.map((widget) => widget.span), 12);
    const md = pack(props.widgets.map((widget) => (widget.span >= 7 ? 2 : 1)), 2);

    return props.widgets.map((_, index) => `${MD_SPANS[md[index]] ?? ''} ${XL_SPANS[xl[index]] ?? ''}`);
});

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

/** Day number and month+year shown in the header's date aside. */
const dateParts = computed(() => {
    const now = new Date();
    const tz = page.props.app.timezone ?? 'UTC';
    const locale = page.props.app.locale ?? 'en';

    return {
        day: new Intl.DateTimeFormat(locale, { day: 'numeric', timeZone: tz }).format(now),
        meta: new Intl.DateTimeFormat(locale, { weekday: 'short', month: 'long', year: 'numeric', timeZone: tz }).format(now),
    };
});
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <!-- Header: accent bar + greeting left, date aside right -->
    <div class="mb-8 flex items-stretch gap-5">
        <div class="hidden w-[3px] shrink-0 self-stretch rounded-full bg-brand-text sm:block" aria-hidden="true" />

        <div class="min-w-0 flex-1">
            <p class="eyebrow mb-2">{{ t('dashboard.title') }}</p>
            <h1 class="text-[1.75rem] leading-tight text-ink sm:text-[2.125rem]">{{ greeting }}</h1>
            <p class="mt-1.5 text-ink-muted">{{ t('dashboard.subtitle', { workspace: page.props.tenant?.name }) }}</p>
        </div>

        <div class="hidden shrink-0 flex-col items-end justify-center text-right sm:flex" aria-hidden="true">
            <span class="figure text-[3rem] leading-none tabular text-ink">{{ dateParts.day }}</span>
            <span class="eyebrow mt-1.5 text-ink-subtle">{{ dateParts.meta }}</span>
        </div>
    </div>

    <!-- Widget grid: rows are packed in script (see pack()) so every row is full; DOM order is kept. -->
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-12">
        <div v-for="(widget, index) in widgets" :key="widget.key" :class="['flex min-w-0 [&>*]:min-w-0 [&>*]:flex-1', layout[index]]">
            <component :is="WIDGETS[widget.component]" v-if="WIDGETS[widget.component]" :data="widget.data" />
        </div>

        <Card v-if="installedApps === 0" class="md:col-span-2 xl:col-span-12" :title="t('dashboard.apps.title')">
            <EmptyState :title="t('dashboard.apps.empty')" :description="t('dashboard.apps.emptyBody')" compact>
                <template #icon><Blocks /></template>
            </EmptyState>
        </Card>
    </div>
</template>
