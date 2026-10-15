<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformIcon from './PlatformIcon.vue';
import { platformRequirements, requirementProblems } from './requirements';
import type { Capabilities } from './types';

/**
 * What one chosen account's platform demands of a post (a photo or video, video only, length and size
 * limits), with the platform's icon and name. A requirement the chosen media do not meet is shown in the
 * warning tone; the server check (the per-account issues) stays the judge before scheduling.
 */
const props = defineProps<{
    platform: string;
    platformLabel: string;
    capabilities: Capabilities;
    /** The media chosen for the post, to mark an unmet requirement. */
    media?: { kind: string }[];
}>();

const { t } = useI18n();
const format = useFormat();

const items = computed(() => {
    const unmet = new Set(requirementProblems(props.capabilities, props.media ?? []));

    return platformRequirements(props.capabilities).map((item) => ({
        key: item.key,
        unmet: unmet.has(item.key),
        text: t(`social.requirements.${item.key}`, { value: format.number(item.value ?? 0) }),
    }));
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5" data-testid="account-requirements">
        <span class="inline-flex items-center gap-1.5 text-xs text-ink-subtle">
            <PlatformIcon :platform="platform" class="size-3.5 shrink-0" />
            {{ platformLabel }}
        </span>
        <Badge v-for="item in items" :key="item.key" :tone="item.unmet ? 'warn' : 'neutral'" :data-requirement="item.key">
            {{ item.text }}
        </Badge>
    </div>
</template>
