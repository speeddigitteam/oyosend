<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { useEmailLabels } from '../../Components/useEmailLabels';
import type { OverviewReport } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ report: OverviewReport; days: number; from: string; to: string }>();

const { t } = useI18n();
const format = useFormat();
const labels = useEmailLabels();

const days = ref(String(props.days));
const dayOptions = computed(() => ['7', '30', '90', '365'].map((value) => ({ value, label: t('emailMarketing.reports.lastDays', { count: Number(value) }) })));

function change(): void {
    router.get(route('email_marketing.reports.index'), { days: days.value }, { preserveState: true, replace: true });
}

function rate(value: number | null): string {
    return value === null ? t('emailMarketing.common.notReported') : format.number(value / 10000, { style: 'percent', maximumFractionDigits: 1 });
}

const delivery = computed(() => [
    { key: 'planned', value: props.report.deliveries.planned },
    { key: 'sent', value: props.report.deliveries.sentTotal },
    { key: 'blocked', value: props.report.deliveries.blocked },
    { key: 'skipped', value: props.report.deliveries.skipped },
    { key: 'bounced', value: props.report.deliveries.bounced },
    { key: 'failed', value: props.report.deliveries.failed },
]);
const people = computed(() => [
    { key: 'subscribed', value: props.report.subscribers.subscribed },
    { key: 'pending', value: props.report.subscribers.pending },
    { key: 'unsubscribed', value: props.report.subscribers.unsubscribed },
    { key: 'bounced', value: props.report.subscribers.bounced },
    { key: 'complained', value: props.report.subscribers.complained },
]);
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.reports.title')" :description="t('emailMarketing.reports.description')">
        <template #actions>
            <Select v-model="days" :options="dayOptions" :label="t('emailMarketing.reports.period')" class="w-44" @update:model-value="change" />
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-2">
        <Card :title="t('emailMarketing.reports.deliveries')" :description="t('emailMarketing.reports.deliveriesNote')">
            <dl class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3" data-testid="delivery-figures">
                <div v-for="tile in delivery" :key="tile.key">
                    <dt class="eyebrow">{{ t(`emailMarketing.report.${tile.key}`) }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ format.number(tile.value) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.report.clickRate') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ rate(report.deliveries.clickRate) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.report.bounceRate') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ rate(report.deliveries.bounceRate) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.report.opens') }}</dt>
                    <dd class="mt-2 text-sm text-ink-muted">{{ t('emailMarketing.common.notMeasured') }}</dd>
                </div>
            </dl>
        </Card>

        <Card :title="t('emailMarketing.reports.people')" :description="t('emailMarketing.reports.peopleNote', { joined: format.number(report.subscribers.joined), left: format.number(report.subscribers.left) })">
            <dl class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3" data-testid="people-figures">
                <div v-for="tile in people" :key="tile.key">
                    <dt class="eyebrow">{{ t(`emailMarketing.status.subscription.${tile.key}`) }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ format.number(tile.value) }}</dd>
                </div>
            </dl>
        </Card>

        <Card :title="t('emailMarketing.reports.campaigns')" class="lg:col-span-2">
            <dl class="grid grid-cols-3 gap-4">
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.reports.finished') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ format.number(report.campaigns.finished) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.reports.active') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ format.number(report.campaigns.active) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.reports.scheduled') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ format.number(report.campaigns.scheduled) }}</dd>
                </div>
            </dl>
        </Card>

        <Card :title="t('emailMarketing.report.reasons')" :description="t('emailMarketing.reports.reasonsNote')" class="lg:col-span-2">
            <p v-if="report.reasons.length === 0" class="text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.reports.noReasons') }}</p>
            <ul v-else class="grid gap-1 text-[0.8125rem] text-ink-muted" data-testid="reasons">
                <li v-for="row in report.reasons" :key="row.reason" class="flex gap-2">
                    <span class="tabular w-12 shrink-0 text-ink">{{ format.number(row.count) }}</span>
                    <span>{{ labels.reason(row.reason) }}</span>
                </li>
            </ul>
        </Card>
    </div>
</template>
