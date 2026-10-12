<script setup lang="ts">
import { BarChart3 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import StatTile from '@/Core/Components/StatTile.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { barWidth, usedPercent } from '../../Components/aiFormat';
import AiNav from '../../Components/AiNav.vue';
import AvailabilityNotice from '../../Components/AvailabilityNotice.vue';
import type { Allowance, Availability, Breakdown } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    allowance: Allowance;
    breakdown: Breakdown;
    availability: Availability;
}>();

const { t, te } = useI18n();
const format = useFormat();

const percent = computed(() => usedPercent(props.allowance));
const empty = computed(() => props.breakdown.byFeature.length === 0 && props.breakdown.byDay.length === 0);
const maxDay = computed(() => Math.max(0, ...props.breakdown.byDay.map((day) => day.tokens)));
const maxFeature = computed(() => Math.max(0, ...props.breakdown.byFeature.map((row) => row.tokens)));

function featureLabel(feature: string): string {
    return te(`ai.feature.${feature}`) ? t(`ai.feature.${feature}`) : t('ai.feature.unknown');
}
</script>

<template>
    <PageHeader :eyebrow="t('ai.eyebrow')" :title="t('ai.usage.title')" :description="t('ai.usage.description')" />
    <AiNav current="usage" />
    <AvailabilityNotice :availability="availability" />

    <div class="grid gap-3">
        <div class="grid gap-3 sm:grid-cols-3">
            <StatTile :label="t('ai.usage.used')" :value="format.number(allowance.used)" />
            <StatTile :label="t('ai.usage.limit')" :value="allowance.limit === null ? t('ai.usage.unlimited') : format.number(allowance.limit)" />
            <StatTile
                :label="t('ai.usage.remaining')"
                :value="allowance.remaining === null ? t('ai.usage.unlimited') : format.number(allowance.remaining)"
                :accent="allowance.remaining === 0"
            />
        </div>

        <Card>
            <div v-if="percent !== null" data-testid="allowance">
                <div
                    class="h-2 overflow-hidden rounded-full bg-hover"
                    role="progressbar"
                    :aria-valuenow="percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="t('ai.usage.percentUsed', { percent: `${format.number(percent)}%` })"
                >
                    <div :class="['h-full rounded-full', percent >= 100 ? 'bg-danger' : 'bg-brand']" :style="{ width: `${percent}%` }" />
                </div>
                <p class="mt-2 text-[0.8125rem] text-ink-muted">{{ t('ai.usage.percentUsed', { percent: `${format.number(percent)}%` }) }}</p>
            </div>
            <p v-else class="text-sm text-ink-muted" data-testid="no-limit">{{ t('ai.usage.noLimit') }}</p>
        </Card>

        <Card v-if="empty">
            <EmptyState :title="t('ai.usage.empty')" :description="t('ai.usage.emptyBody')" compact>
                <template #icon><BarChart3 /></template>
            </EmptyState>
        </Card>

        <template v-else>
            <Card :title="t('ai.usage.byFeature')" :description="t('ai.usage.byFeatureHint')">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" data-testid="by-feature">
                        <thead>
                            <tr class="text-xs text-ink-subtle">
                                <th scope="col" class="py-2 pe-3 font-medium">{{ t('ai.usage.feature') }}</th>
                                <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('ai.usage.requests') }}</th>
                                <th scope="col" class="py-2 ps-3 text-end font-medium">{{ t('ai.usage.tokensColumn') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in breakdown.byFeature" :key="row.feature" class="border-t border-line">
                                <th scope="row" class="py-2.5 pe-3 font-medium text-ink">
                                    {{ featureLabel(row.feature) }}
                                    <span class="mt-1.5 block h-1 overflow-hidden rounded-full bg-hover" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-brand" :style="{ width: `${barWidth(row.tokens, maxFeature)}%` }" />
                                    </span>
                                </th>
                                <td class="tabular px-3 py-2.5 text-end text-ink-muted">{{ format.number(row.requests) }}</td>
                                <td class="tabular py-2.5 ps-3 text-end text-ink">{{ format.number(row.tokens) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card :title="t('ai.usage.byDay')" :description="t('ai.usage.byDayHint')">
                <ul class="grid gap-1.5" data-testid="by-day">
                    <li v-for="day in breakdown.byDay" :key="day.date" class="grid grid-cols-[6.5rem_minmax(0,1fr)_5rem] items-center gap-3 text-sm">
                        <span class="tabular text-ink-muted">{{ format.date(day.date) }}</span>
                        <span class="h-2 overflow-hidden rounded-full bg-hover" aria-hidden="true">
                            <span class="block h-full rounded-full bg-brand" :style="{ width: `${barWidth(day.tokens, maxDay)}%` }" />
                        </span>
                        <span class="tabular text-end text-ink">{{ format.number(day.tokens) }}</span>
                    </li>
                </ul>
            </Card>
        </template>
    </div>
</template>
