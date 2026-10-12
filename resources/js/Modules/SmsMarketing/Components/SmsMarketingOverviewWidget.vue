<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** Campaigns in flight, the plan's segment allowance and whether SMS can be sent at all. */
const props = defineProps<{
    data: {
        sending: number;
        scheduled: number;
        onHold: number;
        segmentsUsed: number;
        segmentsLimit: number | null;
        channelAvailable: boolean;
        channelTestDouble: boolean;
        nextScheduledFor: string | null;
    };
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const tiles = computed(() => [
    { key: 'sending', value: props.data.sending, attention: false },
    { key: 'scheduled', value: props.data.scheduled, attention: false },
    { key: 'onHold', value: props.data.onHold, attention: props.data.onHold > 0 },
]);

const allowance = computed(() =>
    props.data.segmentsLimit === null
        ? t('smsMarketing.widget.unlimited', { used: format.number(props.data.segmentsUsed) })
        : t('smsMarketing.widget.allowance', { used: format.number(props.data.segmentsUsed), limit: format.number(props.data.segmentsLimit) }),
);
</script>

<template>
    <Card :title="t('smsMarketing.widget.title')" class="h-full" data-testid="sms-marketing-widget">
        <template #actions>
            <Button v-if="can('sms_marketing.campaigns.view')" size="sm" variant="ghost" :href="route('sms_marketing.campaigns.index')">
                {{ t('smsMarketing.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <dl class="grid grid-cols-3 gap-x-5 gap-y-4">
            <div v-for="tile in tiles" :key="tile.key">
                <dt class="eyebrow">{{ t(`smsMarketing.widget.${tile.key}`) }}</dt>
                <dd class="figure mt-2 text-2xl" :class="tile.attention ? 'text-brand-text' : 'text-ink'">{{ format.number(tile.value) }}</dd>
            </div>
        </dl>

        <p class="mt-4 text-[0.8125rem] text-ink-muted" data-testid="sms-allowance">{{ allowance }}</p>
        <p v-if="!data.channelAvailable" class="mt-1 text-[0.8125rem] text-warn" data-testid="sms-channel">{{ t('smsMarketing.channel.notConfigured') }}</p>
        <p v-else-if="data.channelTestDouble" class="mt-1 text-[0.8125rem] text-warn" data-testid="sms-channel">{{ t('smsMarketing.channel.testDouble') }}</p>
        <p v-if="data.nextScheduledFor" class="mt-1 text-[0.8125rem] text-ink-muted" data-testid="sms-next">
            {{ t('smsMarketing.widget.next', { when: format.date(data.nextScheduledFor, 'datetime') }) }}
        </p>
    </Card>
</template>
