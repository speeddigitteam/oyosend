<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** What is queued, what needs a decision and which accounts need attention. */
const props = defineProps<{
    data: {
        scheduled: number;
        awaitingApproval: number;
        failed: number;
        accountsNeedingAttention: number;
        nextScheduledFor: string | null;
        leadAdsEnabled?: boolean;
        leadAdsReceived?: number;
        leadAdsProcessed?: number;
        leadAdsFailed?: number;
        leadAdsWaiting?: number;
    };
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const tiles = computed(() => [
    { key: 'scheduled', value: props.data.scheduled, attention: false },
    { key: 'awaitingApproval', value: props.data.awaitingApproval, attention: props.data.awaitingApproval > 0 },
    { key: 'failed', value: props.data.failed, attention: props.data.failed > 0 },
    { key: 'accountsNeedingAttention', value: props.data.accountsNeedingAttention, attention: props.data.accountsNeedingAttention > 0 },
]);

const leadTiles = computed(() => [
    { key: 'received', value: props.data.leadAdsReceived ?? 0, attention: false },
    { key: 'processed', value: props.data.leadAdsProcessed ?? 0, attention: false },
    { key: 'failed', value: props.data.leadAdsFailed ?? 0, attention: (props.data.leadAdsFailed ?? 0) > 0 },
    { key: 'waiting', value: props.data.leadAdsWaiting ?? 0, attention: (props.data.leadAdsWaiting ?? 0) > 0 },
]);
</script>

<template>
    <Card :title="t('social.widget.overview.title')" class="h-full" data-testid="social-overview-widget">
        <template #actions>
            <Button v-if="can('social.posts.view')" size="sm" variant="ghost" :href="route('social.calendar.index')">
                {{ t('social.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <dl class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div v-for="tile in tiles" :key="tile.key">
                <dt class="eyebrow">{{ t(`social.widget.overview.${tile.key}`) }}</dt>
                <dd class="figure mt-2 text-2xl" :class="tile.attention ? 'text-brand-text' : 'text-ink'">{{ format.number(tile.value) }}</dd>
            </div>
        </dl>

        <section v-if="data.leadAdsReceived !== undefined && (data.leadAdsEnabled || data.leadAdsReceived > 0)" class="mt-4 border-t border-line pt-3" data-testid="social-lead-ads">
            <div class="flex items-center justify-between gap-2">
                <h3 class="eyebrow">{{ t('leadAds.widget.title') }}</h3>
                <Button v-if="can('social.leadads.view')" size="sm" variant="ghost" :href="route('social.leadads.index')">{{ t('leadAds.widget.open') }}</Button>
            </div>
            <dl class="mt-2 grid grid-cols-4 gap-x-3">
                <div v-for="tile in leadTiles" :key="tile.key">
                    <dt class="text-xs text-ink-subtle">{{ t(`leadAds.widget.${tile.key}`) }}</dt>
                    <dd :class="['figure mt-1 text-lg', tile.attention ? 'text-brand-text' : 'text-ink']">{{ format.number(tile.value) }}</dd>
                </div>
            </dl>
        </section>

        <p class="mt-4 text-[0.8125rem] text-ink-muted" data-testid="social-next">
            <template v-if="data.nextScheduledFor">
                {{ t('social.widget.overview.next', { when: format.date(data.nextScheduledFor, 'datetime') }) }}
            </template>
            <template v-else>{{ t('social.widget.overview.nothingNext') }}</template>
        </p>
    </Card>
</template>
