<script setup lang="ts">
import { PlugZap } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import type { ChannelInfo } from './types';

/**
 * The honest state of the WhatsApp connection. Not connected is said plainly, with what has to happen; a test
 * provider is labelled as one so nobody mistakes it for live WhatsApp.
 */
const props = defineProps<{ channel: ChannelInfo }>();

const { t, te } = useI18n();

const notConfigured = computed(() => props.channel.state === 'not_configured');
const reasonText = computed(() => {
    const reason = props.channel.reason;

    return reason !== null && te(`whatsapp.settings.reasons.${reason}`) ? t(`whatsapp.settings.reasons.${reason}`) : null;
});
</script>

<template>
    <div
        v-if="notConfigured"
        role="status"
        data-testid="channel-not-configured"
        class="mb-3 flex items-start gap-3 rounded-card border border-warn/40 bg-warn-soft px-4 py-3 text-sm text-ink"
    >
        <PlugZap class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
        <div class="min-w-0">
            <p class="font-medium">{{ t('whatsapp.channel.notConnectedTitle') }}</p>
            <p class="mt-0.5 text-ink-muted">{{ t('whatsapp.channel.notConnectedBody') }}</p>
            <p v-if="reasonText" class="mt-1 text-ink-subtle">{{ reasonText }}</p>
        </div>
    </div>
    <p v-else-if="channel.testDouble" data-testid="channel-test-provider" class="mb-3 flex flex-wrap items-center gap-2 text-sm text-ink-muted">
        <Badge tone="warn">{{ t('whatsapp.channel.testBadge') }}</Badge>
        {{ t('whatsapp.channel.testBody') }}
    </p>
</template>
