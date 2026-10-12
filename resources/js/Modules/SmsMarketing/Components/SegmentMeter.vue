<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { SegmentCount } from './smsFormat';

/** The length of a text in characters and SMS segments, with the encoding that decides the segment size. */
const props = defineProps<{ count: SegmentCount; maxSegments?: number }>();

const { t } = useI18n();
const format = useFormat();

const unicode = computed(() => props.count.encoding === 'ucs2');
const over = computed(() => props.maxSegments !== undefined && props.count.segments > props.maxSegments);
</script>

<template>
    <div class="grid gap-1.5 text-[0.8125rem]" data-testid="segment-meter">
        <div class="flex flex-wrap items-center gap-2">
            <Badge :tone="unicode ? 'warn' : 'neutral'">{{ unicode ? t('smsMarketing.segments.unicode') : t('smsMarketing.segments.gsm') }}</Badge>
            <span class="tabular text-ink">
                {{ t('smsMarketing.segments.summary', { characters: format.number(count.characters), segments: format.number(count.segments) }) }}
            </span>
            <span class="tabular text-ink-subtle">
                {{ t('smsMarketing.segments.capacity', { used: format.number(count.perSegment - count.remaining), size: format.number(count.perSegment) }) }}
            </span>
        </div>
        <p v-if="unicode" class="text-ink-muted">
            {{ t('smsMarketing.segments.unicodeHint', { characters: count.unicodeCharacters.join(' ') }) }}
        </p>
        <p v-if="over" class="text-danger" role="alert">{{ t('smsMarketing.segments.tooLong', { max: format.number(maxSegments ?? 0) }) }}</p>
    </div>
</template>
