<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Card from '@/Core/Components/Card.vue';
import Skeleton from '@/Core/Components/Skeleton.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { scoreOf, share } from './aiAssist';

type Label = 'positive' | 'neutral' | 'negative' | 'mixed' | 'unknown';

interface AccountMix {
    accountId: string;
    name: string;
    platform: string;
    total: number;
    mix: Record<Label, number>;
    averageScoreBasisPoints: number | null;
}

interface Summary {
    total: number;
    mix: Record<Label, number>;
    averageScoreBasisPoints: number | null;
    accounts: AccountMix[];
    dailyCap: number;
    usedToday: number;
}

const LABELS: Label[] = ['positive', 'neutral', 'negative', 'mixed', 'unknown'];
const BAR: Record<Label, string> = {
    positive: 'bg-ok',
    neutral: 'bg-ink-subtle',
    negative: 'bg-danger',
    mixed: 'bg-warn',
    unknown: 'bg-line-strong',
};

/**
 * Sentiment mix of the audience comments AI has analysed in the period. Read only: it never changes a comment.
 * Loads its own data, so the Analytics page does not depend on it.
 */
const props = defineProps<{ from: string; to: string }>();

const { t } = useI18n();
const format = useFormat();

const summary = ref<Summary | null>(null);
const loading = ref(true);
const failed = ref(false);
let controller: AbortController | null = null;

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    failed.value = false;

    try {
        const url = route('social.analytics.sentiment', { from: props.from, to: props.to });
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });

        if (!response.ok) {
            throw new Error('unavailable');
        }

        summary.value = (await response.json()) as Summary;
    } catch (error) {
        if (controller.signal.aborted) {
            return;
        }

        summary.value = null;
        failed.value = true;
    }

    loading.value = false;
}

function tone(basisPoints: number | null): string {
    if (basisPoints === null) {
        return t('socialAi.sentiment.noScore');
    }

    const label = basisPoints >= 2000 ? 'positive' : basisPoints <= -2000 ? 'negative' : 'neutral';
    const score = scoreOf(basisPoints);

    return t('socialAi.sentiment.average', { label: t(`socialAi.sentiment.labels.${label}`), score: `${score > 0 ? '+' : ''}${format.number(score)}` });
}

onMounted(load);
watch(() => [props.from, props.to], load);
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <Card class="mt-3" :title="t('socialAi.sentiment.title')" :description="t('socialAi.sentiment.description')" data-testid="sentiment">
        <Skeleton v-if="loading" class="h-16 w-full" />

        <p v-else-if="failed" class="text-[0.8125rem] text-ink-muted" role="status" data-testid="sentiment-error">{{ t('socialAi.sentiment.error') }}</p>

        <p v-else-if="summary === null || summary.total === 0" class="text-[0.8125rem] text-ink-muted" data-testid="sentiment-empty">
            {{ t('socialAi.sentiment.empty') }}
        </p>

        <div v-else class="grid gap-4">
            <div>
                <div class="flex h-3 overflow-hidden rounded-chip bg-hover" role="img" :aria-label="t('socialAi.sentiment.barLabel', { total: format.number(summary.total) })">
                    <span
                        v-for="label in LABELS.filter((item) => summary!.mix[item] > 0)"
                        :key="label"
                        :class="BAR[label]"
                        :style="{ width: `${share(summary.mix[label], summary.total)}%` }"
                    />
                </div>
                <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[0.8125rem]" data-testid="sentiment-legend">
                    <li v-for="label in LABELS" :key="label" class="flex items-center gap-1.5 text-ink-muted">
                        <span :class="['size-2.5 rounded-full', BAR[label]]" aria-hidden="true" />
                        {{ t(`socialAi.sentiment.labels.${label}`) }}
                        <span class="tabular text-ink">{{ format.number(summary.mix[label]) }} ({{ format.number(share(summary.mix[label], summary.total)) }}%)</span>
                    </li>
                </ul>
                <p class="mt-2 text-[0.8125rem] text-ink-muted" data-testid="sentiment-average">{{ tone(summary.averageScoreBasisPoints) }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ t('socialAi.sentiment.byPage') }}</caption>
                    <thead>
                        <tr class="text-xs text-ink-subtle">
                            <th scope="col" class="py-1.5 pr-3 font-medium">{{ t('socialAi.sentiment.page') }}</th>
                            <th v-for="label in LABELS" :key="label" scope="col" class="px-2 py-1.5 text-right font-medium">{{ t(`socialAi.sentiment.labels.${label}`) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="account in summary.accounts" :key="account.accountId" class="border-t border-line">
                            <th scope="row" class="py-2 pr-3 font-medium text-ink">{{ account.name }}</th>
                            <td v-for="label in LABELS" :key="label" class="tabular px-2 py-2 text-right text-ink-muted">{{ format.number(account.mix[label]) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                <Badge tone="info">{{ t('socialAi.generated') }}</Badge>
                {{ t('socialAi.sentiment.note') }}
            </p>
        </div>
    </Card>
</template>
