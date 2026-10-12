<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { WidgetData } from './types';

/** What is scheduled or going out, how many people are on lists and how the last campaign did. */
const props = defineProps<{ data: WidgetData }>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const tiles = computed(() => [
    { key: 'scheduled', value: props.data.scheduled, attention: false },
    { key: 'sending', value: props.data.sending, attention: false },
    { key: 'paused', value: props.data.paused, attention: props.data.paused > 0 },
    ...(props.data.subscribers === null ? [] : [{ key: 'subscribers', value: props.data.subscribers, attention: false }]),
]);
</script>

<template>
    <Card :title="t('emailMarketing.widget.title')" class="h-full" data-testid="email-marketing-widget">
        <template #actions>
            <Button v-if="can('email_marketing.campaigns.view')" size="sm" variant="ghost" :href="route('email_marketing.campaigns.index')">
                {{ t('emailMarketing.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <dl class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div v-for="tile in tiles" :key="tile.key">
                <dt class="eyebrow">{{ t(`emailMarketing.widget.${tile.key}`) }}</dt>
                <dd class="figure mt-2 text-2xl" :class="tile.attention ? 'text-brand-text' : 'text-ink'">{{ format.number(tile.value) }}</dd>
            </div>
        </dl>

        <p class="mt-4 text-[0.8125rem] text-ink-muted" data-testid="email-marketing-last">
            <template v-if="data.last">
                {{ t('emailMarketing.widget.last', { name: data.last.name, sent: format.number(data.last.sent) }) }}
                <template v-if="data.last.clickRate !== null">
                    {{ t('emailMarketing.widget.lastClicks', { rate: format.number(data.last.clickRate / 10000, { style: 'percent', maximumFractionDigits: 1 }) }) }}
                </template>
            </template>
            <template v-else>{{ t('emailMarketing.widget.nothingSent') }}</template>
        </p>
    </Card>
</template>
