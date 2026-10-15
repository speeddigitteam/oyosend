<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { channelIcon, channelLabelKey } from './inboxChat';

/**
 * A conversation's channel as a generic icon (never a brand logo), named for screen readers by the channel. A channel
 * this page does not know gets a generic icon and the source's own (server-translated) label.
 */
const props = defineProps<{ channel: string; sourceLabel: string }>();

const { t } = useI18n();
const icon = computed(() => channelIcon(props.channel));
const label = computed(() => {
    const key = channelLabelKey(props.channel);

    return key === null ? props.sourceLabel : t(key);
});
</script>

<template>
    <span class="inline-flex shrink-0 text-ink-subtle" role="img" :aria-label="label" :title="label" :data-channel="channel">
        <component :is="icon" class="size-4" aria-hidden="true" />
    </span>
</template>
