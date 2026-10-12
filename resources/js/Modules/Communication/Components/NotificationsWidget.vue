<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Bell } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { NotificationItem } from './types';

defineProps<{
    data: { unread: number; items: NotificationItem[] };
}>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('communication.widget.title')" class="h-full">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('communication.notifications.index')">
                {{ t('communication.widget.view') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>
        <EmptyState v-if="data.unread === 0" :title="t('communication.widget.empty')" compact>
            <template #icon><Bell /></template>
        </EmptyState>
        <div v-else>
            <p class="eyebrow">{{ t('communication.widget.unread') }}</p>
            <p class="figure mt-2 text-3xl text-ink">{{ format.number(data.unread) }}</p>
            <ul class="mt-4 grid gap-2" :aria-label="t('communication.widget.latest')">
                <li v-for="item in data.items" :key="item.id" class="min-w-0">
                    <Link v-if="item.url" :href="item.url" class="block truncate text-sm text-ink hover:text-brand-text">{{ item.title }}</Link>
                    <span v-else class="block truncate text-sm text-ink">{{ item.title }}</span>
                    <span class="tabular text-xs text-ink-subtle">{{ format.relative(item.createdAt) }}</span>
                </li>
            </ul>
            <p v-if="data.unread > data.items.length" class="mt-3 text-xs text-ink-subtle">
                {{ t('communication.widget.more', { count: format.number(data.unread - data.items.length) }) }}
            </p>
        </div>
    </Card>
</template>
