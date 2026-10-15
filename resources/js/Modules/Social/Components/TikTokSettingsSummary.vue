<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { tikTokOptionsFrom } from './tiktokOptions';
import type { TikTokOptions } from './types';

/**
 * The TikTok settings chosen for one target, on the post page. A target saved before they could be chosen
 * (`options` null) says it publishes with the earlier automatic settings.
 */
const props = defineProps<{ options: TikTokOptions | null }>();

const { t } = useI18n();
const chosen = computed(() => (props.options === null ? null : tikTokOptionsFrom(props.options)));

const allowed = computed(() => {
    if (chosen.value === null) {
        return '';
    }

    const list = [
        chosen.value.allow_comment ? t('social.tiktok.allowComment') : null,
        chosen.value.allow_duet ? t('social.tiktok.allowDuet') : null,
        chosen.value.allow_stitch ? t('social.tiktok.allowStitch') : null,
    ].filter((item): item is string => item !== null);

    return list.length > 0 ? list.join(', ') : t('social.tiktok.summary.nothingAllowed');
});

const disclosure = computed(() => {
    if (chosen.value === null || !chosen.value.disclose_commercial) {
        return t('social.tiktok.summary.notDisclosed');
    }

    return [
        chosen.value.brand_organic ? t('social.tiktok.yourBrand') : null,
        chosen.value.brand_content ? t('social.tiktok.brandedContent') : null,
    ].filter((item): item is string => item !== null).join(', ') || t('social.tiktok.summary.notDisclosed');
});
</script>

<template>
    <div class="grid gap-1 rounded-control glass-well px-3 py-2 text-[0.8125rem]" data-testid="tiktok-summary">
        <p class="eyebrow">{{ t('social.tiktok.title') }}</p>
        <p v-if="chosen === null" class="text-ink-muted" data-testid="tiktok-summary-automatic">{{ t('social.tiktok.summary.automatic') }}</p>
        <dl v-else class="grid gap-x-3 gap-y-0.5 sm:grid-cols-[auto_minmax(0,1fr)]">
            <dt class="text-ink-subtle">{{ t('social.tiktok.privacy') }}</dt>
            <dd class="text-ink" data-testid="tiktok-summary-privacy">
                {{ chosen.privacy_level ? t(`social.tiktok.privacyLevels.${chosen.privacy_level}`) : t('social.tiktok.summary.notChosen') }}
            </dd>
            <dt class="text-ink-subtle">{{ t('social.tiktok.summary.allowed') }}</dt>
            <dd class="text-ink">{{ allowed }}</dd>
            <dt class="text-ink-subtle">{{ t('social.tiktok.summary.disclosure') }}</dt>
            <dd class="text-ink">{{ disclosure }}</dd>
        </dl>
    </div>
</template>
