<script setup lang="ts">
import { ArrowUpRight, ScrollText } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { eventLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';

interface ActivityData {
    entries: Array<{ id: string; event: string; actor: string | null; subject: string | null; createdAt: string }>;
}

defineProps<{ data: ActivityData }>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('dashboard.activity.title')" flush>
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('core.audit.index')">
                {{ t('dashboard.activity.viewAll') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <EmptyState v-if="data.entries.length === 0" :title="t('dashboard.activity.empty')" compact>
            <template #icon><ScrollText /></template>
        </EmptyState>

        <ol v-else class="pb-2">
            <li
                v-for="entry in data.entries"
                :key="entry.id"
                class="flex items-baseline gap-4 border-t border-line px-5 py-2.5 first:border-t-0"
            >
                <span class="tabular w-28 shrink-0 font-mono text-xs text-ink-subtle">{{ format.relative(entry.createdAt) }}</span>
                <span class="min-w-0 flex-1 text-sm text-ink">
                    {{ eventLabel(entry.event) }}
                    <span v-if="entry.subject" class="text-ink-muted"> · {{ entry.subject }}</span>
                </span>
                <span class="hidden max-w-[40%] shrink-0 truncate text-[0.8125rem] text-ink-subtle sm:block">
                    {{ entry.actor ? t('dashboard.activity.by', { actor: entry.actor }) : t('common.system') }}
                </span>
            </li>
        </ol>
    </Card>
</template>
