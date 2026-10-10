<script setup lang="ts">
import { Clock3 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { TimelineEntry } from './types';

defineProps<{ entries: TimelineEntry[] }>();
const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('crm.timeline.title')" flush>
        <EmptyState v-if="entries.length === 0" :title="t('crm.timeline.empty')" compact><template #icon><Clock3 /></template></EmptyState>
        <ol v-else class="divide-y divide-line">
            <li v-for="entry in entries" :key="entry.id" class="relative py-3 pr-5 pl-10 before:absolute before:top-5 before:left-5 before:size-2 before:rounded-full before:bg-brand">
                <p class="text-sm text-ink">{{ entry.summary }}</p>
                <p class="mt-1 text-xs text-ink-subtle"><span class="tabular">{{ format.date(entry.occurredAt, 'datetime') }}</span> · {{ entry.actor ? t('crm.timeline.by', { name: entry.actor }) : t('crm.timeline.system') }}</p>
            </li>
        </ol>
    </Card>
</template>
