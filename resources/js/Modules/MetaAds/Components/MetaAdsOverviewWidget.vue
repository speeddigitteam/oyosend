<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** Seven days of spend and leads in the main ad-account currency, and how many accounts need attention. */
defineProps<{
    data: {
        currency: string | null;
        currencies: number;
        spend: number | null;
        leads: number | null;
        trackedAccounts: number;
        accountsNeedingAttention: number;
    };
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
</script>

<template>
    <Card :title="t('metaAds.widget.title')" class="h-full" data-testid="meta-ads-widget">
        <template #actions>
            <Button v-if="can('meta_ads.insights.view')" size="sm" variant="ghost" :href="route('meta_ads.performance.index')">
                {{ t('metaAds.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <p v-if="data.trackedAccounts === 0" class="text-[0.8125rem] text-ink-muted" data-testid="widget-empty">{{ t('metaAds.widget.none') }}</p>

        <dl v-else class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div>
                <dt class="eyebrow">{{ t('metaAds.widget.spend') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ data.spend === null || !data.currency ? t('metaAds.notReported') : format.money(data.spend, data.currency) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('metaAds.widget.leads') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ data.leads === null ? t('metaAds.notReported') : format.number(data.leads) }}</dd>
            </div>
            <div class="col-span-2 text-[0.8125rem]" :class="data.accountsNeedingAttention > 0 ? 'text-warn' : 'text-ink-muted'">
                {{ data.accountsNeedingAttention > 0 ? t('metaAds.widget.attention', { count: format.number(data.accountsNeedingAttention) }) : t('metaAds.widget.last7') }}
                <template v-if="data.currencies > 1"> · {{ t('metaAds.widget.currencies', { currency: data.currency ?? '' }) }}</template>
            </div>
        </dl>
    </Card>
</template>
