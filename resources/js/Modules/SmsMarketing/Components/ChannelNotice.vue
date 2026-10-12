<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ChannelState } from './types';

/**
 * Says plainly whether SMS can be sent from this workspace. Not configured is never dressed up as working,
 * and the in-memory test provider is named as what it is.
 */
const props = defineProps<{ channel: ChannelState }>();

const { t } = useI18n();

const notConfigured = computed(() => props.channel.state !== 'available');
const reason = computed(() => (props.channel.reason ? t(`smsMarketing.channel.reasons.${props.channel.reason}`) : ''));
</script>

<template>
    <div
        v-if="notConfigured || channel.testDouble"
        class="flex items-start gap-3 rounded-card border border-line bg-warn-soft px-4 py-3 text-[0.8125rem] text-ink"
        role="status"
        data-testid="channel-notice"
    >
        <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
        <div>
            <p v-if="notConfigured" class="font-medium">{{ t('smsMarketing.channel.notConfigured') }}</p>
            <p v-else class="font-medium">{{ t('smsMarketing.channel.testDouble') }}</p>
            <p v-if="notConfigured" class="mt-0.5 text-ink-muted">
                {{ t('smsMarketing.channel.notConfiguredBody', { reason }) }}
            </p>
            <p v-else class="mt-0.5 text-ink-muted">{{ t('smsMarketing.channel.testDoubleBody') }}</p>
        </div>
    </div>
</template>
