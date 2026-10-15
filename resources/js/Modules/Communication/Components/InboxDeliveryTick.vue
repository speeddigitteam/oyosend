<script setup lang="ts">
import { Check, CheckCheck, CircleAlert, Clock } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { DeliveryState, DeliveryTick } from './inboxChat';

/**
 * The delivery state of an outgoing message as a small icon, named for screen readers by the status word: a clock
 * while it is on its way, one tick when sent, two when delivered, two in the accent colour when read, and an alert in
 * the danger colour when it failed or was blocked.
 */
const props = defineProps<{ state: DeliveryState }>();

const { t } = useI18n();

const ICONS: Record<DeliveryTick, { icon: Component; tone: string }> = {
    pending: { icon: Clock, tone: 'text-ink-subtle' },
    sent: { icon: Check, tone: 'text-ink-subtle' },
    delivered: { icon: CheckCheck, tone: 'text-ink-subtle' },
    read: { icon: CheckCheck, tone: 'text-accent-text' },
    failed: { icon: CircleAlert, tone: 'text-danger' },
};

const shown = computed(() => ICONS[props.state.tick]);
const label = computed(() => t(`communication.inbox.chat.status.${props.state.label}`));
</script>

<template>
    <span class="inline-flex shrink-0" :class="shown.tone" role="img" :aria-label="label" :title="label" :data-tick="state.tick">
        <component :is="shown.icon" class="size-3.5" aria-hidden="true" />
        <!-- The word as text as well, for views that read text only; the image role keeps it from being read twice. -->
        <span class="sr-only">{{ label }}</span>
    </span>
</template>
