<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import type { CompatBadge } from './types';

/**
 * Per-platform compatibility of one library file ("Reels-ready", "too long for Reels", "not supported").
 * The server decides; this only words it. Unknown codes are not shown.
 */
const props = defineProps<{
    compat: CompatBadge[];
    /** Show only these platform keys (the chosen accounts); empty or missing shows all. */
    platforms?: string[];
    /** At most this many badges (the rest are summarised in the title). */
    max?: number;
}>();

const { t, te } = useI18n();

const TONES: Record<CompatBadge['tone'], BadgeTone> = { ok: 'ok', warn: 'warn', danger: 'danger', muted: 'neutral' };

const shown = computed(() => {
    const chosen = props.platforms ?? [];
    const items = props.compat.filter((item) => (chosen.length === 0 || chosen.includes(item.platform)) && te(`socialPublishing.compat.${item.code}`));

    return props.max ? items.slice(0, props.max) : items;
});

function text(item: CompatBadge): string {
    return t('socialPublishing.compat.forPlatform', { platform: item.platformLabel, status: t(`socialPublishing.compat.${item.code}`) });
}
</script>

<template>
    <ul v-if="shown.length > 0" class="flex flex-wrap gap-1" data-testid="compat">
        <li v-for="item in shown" :key="`${item.platform}:${item.code}`" class="min-w-0">
            <Badge :tone="TONES[item.tone]" :title="text(item)">{{ text(item) }}</Badge>
        </li>
    </ul>
</template>
