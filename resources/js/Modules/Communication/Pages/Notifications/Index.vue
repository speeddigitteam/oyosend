<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, Bell, CheckCheck, Mail, MailOpen, Settings2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import { severityTone } from '../../Components/communicationFormat';
import type { NotificationItem } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

type Filter = 'all' | 'unread' | 'archived';

const props = defineProps<{
    notifications: Paginated<NotificationItem>;
    filters: { filter: Filter; category: string | null };
    unreadCount: number;
    categories: string[];
}>();

const { t } = useI18n();
const format = useFormat();
const { label } = useCommunicationLabels();

const segments = computed(() => [
    { value: 'all' as const, label: t('communication.notifications.filter.all') },
    { value: 'unread' as const, label: t('communication.notifications.filter.unread') },
    { value: 'archived' as const, label: t('communication.notifications.filter.archived') },
]);

const categoryOptions = computed(() => [
    { value: '', label: t('communication.notifications.allCategories') },
    ...props.categories.map((value) => ({ value, label: label('category', value) })),
]);

function visit(filter: Filter, category: string | null): void {
    const query: Record<string, string> = {};

    if (filter !== 'all') {
        query.filter = filter;
    }

    if (category) {
        query.category = category;
    }

    router.get(route('communication.notifications.index'), query, { preserveState: true });
}

const filterModel = computed<Filter>({
    get: () => props.filters.filter,
    set: (value) => visit(value, props.filters.category),
});

const categoryModel = computed<string>({
    get: () => props.filters.category ?? '',
    set: (value) => visit(props.filters.filter, value === '' ? null : value),
});

function act(action: 'read' | 'unread' | 'archive', item: NotificationItem): void {
    router.post(route(`communication.notifications.${action}`, { notification: item.id }), {}, { preserveScroll: true });
}

function readAll(): void {
    router.post(route('communication.notifications.read-all'), {}, { preserveScroll: true });
}

const emptyKey = computed(() => (props.filters.filter === 'unread' ? 'Unread' : props.filters.filter === 'archived' ? 'Archived' : ''));
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.notifications.title')" :description="t('communication.notifications.description')">
        <template #actions>
            <Button variant="secondary" :href="route('communication.preferences.index')">
                <template #icon><Settings2 class="size-4" aria-hidden="true" /></template>
                {{ t('communication.notifications.preferences') }}
            </Button>
            <Button v-if="unreadCount > 0" variant="primary" @click="readAll">
                <template #icon><CheckCheck class="size-4" aria-hidden="true" /></template>
                {{ t('communication.notifications.markAllRead') }}
            </Button>
        </template>
    </PageHeader>
    <CommunicationNav current="notifications" />

    <div class="grid gap-3">
        <Card>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <SegmentedControl v-model="filterModel" :options="segments" :label="t('communication.notifications.filterLabel')" />
                <div class="min-w-48">
                    <Select v-model="categoryModel" :options="categoryOptions" :label="t('communication.notifications.category')" />
                </div>
            </div>
            <p v-if="unreadCount > 0" class="tabular mt-3 text-sm text-ink-muted">
                {{ t('communication.notifications.unread', { count: format.number(unreadCount) }) }}
            </p>
        </Card>

        <Card v-if="notifications.data.length === 0" flush>
            <EmptyState
                :title="t(`communication.notifications.empty${emptyKey}`)"
                :description="t(`communication.notifications.empty${emptyKey}Body`)"
            >
                <template #icon><Bell /></template>
            </EmptyState>
        </Card>

        <Card v-else flush>
            <ul class="divide-y divide-line" :aria-label="t('communication.notifications.list')">
                <li v-for="item in notifications.data" :key="item.id" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-4">
                    <span
                        :class="['mt-1.5 size-2 shrink-0 rounded-full', item.read ? 'bg-ink-subtle' : 'bg-accent']"
                        :title="item.read ? t('communication.notifications.read') : t('communication.notifications.filter.unread')"
                        aria-hidden="true"
                    />
                    <div class="min-w-0 flex-1 basis-64">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link v-if="item.url" :href="item.url" :class="['text-sm wrap-anywhere hover:text-brand-text', item.read ? 'text-ink-muted' : 'font-semibold text-ink']">
                                {{ item.title }}
                            </Link>
                            <span v-else :class="['text-sm wrap-anywhere', item.read ? 'text-ink-muted' : 'font-semibold text-ink']">{{ item.title }}</span>
                            <Badge v-if="item.severity !== 'info'" :tone="severityTone(item.severity)">{{ label('severity', item.severity) }}</Badge>
                            <Badge tone="neutral">{{ label('category', item.category) }}</Badge>
                            <Badge v-if="item.archived" tone="neutral">{{ t('communication.notifications.archived') }}</Badge>
                        </div>
                        <p v-if="item.body" class="mt-1 text-sm wrap-anywhere text-ink-muted">{{ item.body }}</p>
                        <p class="tabular mt-1 text-xs text-ink-subtle">{{ format.relative(item.createdAt) }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <Button v-if="item.url" size="sm" variant="ghost" :href="item.url">{{ t('communication.notifications.open') }}</Button>
                        <Button
                            v-if="!item.read"
                            icon
                            size="sm"
                            variant="ghost"
                            :aria-label="t('communication.notifications.markReadFor', { title: item.title })"
                            @click="act('read', item)"
                        >
                            <template #icon><MailOpen class="size-4" aria-hidden="true" /></template>
                        </Button>
                        <Button
                            v-else-if="!item.archived"
                            icon
                            size="sm"
                            variant="ghost"
                            :aria-label="t('communication.notifications.markUnreadFor', { title: item.title })"
                            @click="act('unread', item)"
                        >
                            <template #icon><Mail class="size-4" aria-hidden="true" /></template>
                        </Button>
                        <Button
                            v-if="!item.archived"
                            icon
                            size="sm"
                            variant="ghost"
                            :aria-label="t('communication.notifications.archiveFor', { title: item.title })"
                            @click="act('archive', item)"
                        >
                            <template #icon><Archive class="size-4" aria-hidden="true" /></template>
                        </Button>
                    </div>
                </li>
            </ul>
        </Card>
        <Pagination :meta="notifications" />
    </div>
</template>
