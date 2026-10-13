<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';

/**
 * Vertical bars with their numbers printed. A bucket with no posts has no bar and says so
 * ("no data"), it is never drawn as zero. No chart library.
 */
const props = defineProps<{
    label: string;
    items: { key: string; label: string; value: number | null; posts: number }[];
    /** Keys to emphasise (the best buckets). */
    highlight?: string[];
}>();

const { t } = useI18n();
const format = useFormat();

const max = computed(() => Math.max(0, ...props.items.map((item) => item.value ?? 0)));

function height(value: number | null): string {
    return value === null || max.value === 0 ? '0%' : `${Math.max(4, Math.round((value / max.value) * 100))}%`;
}

function shown(value: number | null): string {
    return value === null ? t('socialInsights.noData') : format.number(value, { maximumFractionDigits: 1 });
}
</script>

<template>
    <figure>
        <div class="flex h-32 items-end gap-1" role="img" :aria-label="label">
            <div v-for="item in items" :key="item.key" class="flex h-full min-w-0 flex-1 flex-col justify-end" :title="`${item.label}: ${shown(item.value)} (${format.number(item.posts)})`">
                <span v-if="items.length <= 7" class="tabular mb-1 truncate text-center text-[0.6875rem] text-ink-muted">{{ item.value === null ? '' : shown(item.value) }}</span>
                <div
                    :class="['w-full rounded-t-[4px]', highlight?.includes(item.key) ? 'bg-accent' : 'bg-brand']"
                    :style="{ height: height(item.value) }"
                />
            </div>
        </div>
        <div class="mt-1 flex gap-1 text-center text-[0.6875rem] text-ink-subtle" aria-hidden="true">
            <span v-for="item in items" :key="item.key" class="min-w-0 flex-1 truncate">{{ item.label }}</span>
        </div>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs">
                <caption class="sr-only">{{ label }}</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="px-2 py-1 font-normal text-ink-subtle" />
                        <th v-for="item in items" :key="item.key" scope="col" class="px-2 py-1 text-right font-normal text-ink-subtle">{{ item.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-line">
                        <th scope="row" class="px-2 py-1 font-normal text-ink-subtle">{{ t('socialInsights.best.average') }}</th>
                        <td v-for="item in items" :key="item.key" :class="['tabular px-2 py-1 text-right', highlight?.includes(item.key) ? 'font-medium text-ink' : 'text-ink-muted']">{{ shown(item.value) }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="px-2 py-1 font-normal text-ink-subtle">{{ t('socialInsights.best.posts') }}</th>
                        <td v-for="item in items" :key="item.key" class="tabular px-2 py-1 text-right text-ink-muted">{{ format.number(item.posts) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>
