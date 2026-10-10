<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Building2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import TenantStatusBadge, { type TenantStatus } from './TenantStatusBadge.vue';
import WorkspaceName from './WorkspaceName.vue';

/** A row of the dashboard's `recentTenants`. */
export interface RecentTenant {
    id: string;
    name: string;
    /** The workspace's subdomain label; the dashboard is not sent a full URL. */
    slug: string;
    status: TenantStatus;
    members: number;
    createdAt: string | null;
}

defineProps<{ tenants: RecentTenant[] }>();

const { t } = useI18n();
const format = useFormat();

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('platform.tenants.columns.name'), class: 'min-w-48' },
    { key: 'slug', label: t('platform.dashboard.recent.subdomain'), class: 'whitespace-nowrap' },
    { key: 'status', label: t('platform.tenants.columns.status'), class: 'whitespace-nowrap' },
    { key: 'members', label: t('platform.tenants.columns.members'), align: 'right', class: 'whitespace-nowrap' },
    { key: 'createdAt', label: t('platform.tenants.columns.created'), align: 'right', class: 'whitespace-nowrap' },
]);
</script>

<template>
    <Card :title="t('platform.dashboard.recent.title')" flush>
        <template v-if="tenants.length > 0" #actions>
            <Button size="sm" variant="ghost" :href="route('platform.tenants.index')">
                {{ t('platform.dashboard.recent.viewAll') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <EmptyState
            v-if="tenants.length === 0"
            :title="t('platform.dashboard.recent.empty')"
            :description="t('platform.dashboard.recent.emptyBody')"
            compact
        >
            <template #icon><Building2 /></template>
        </EmptyState>

        <DataTable v-else :columns="columns" :rows="tenants" row-key="id" :caption="t('platform.dashboard.recent.caption')">
            <template #cell-name="{ row }">
                <Link
                    :href="route('platform.tenants.show', { workspace: row.id })"
                    class="inline-flex rounded-chip underline-offset-4 hover:underline"
                >
                    <WorkspaceName :name="row.name" />
                </Link>
            </template>

            <template #cell-slug="{ row }">
                <span class="font-mono text-[0.8125rem] text-ink-muted">{{ row.slug }}</span>
            </template>

            <template #cell-status="{ row }">
                <TenantStatusBadge :status="row.status" />
            </template>

            <template #cell-members="{ row }">
                <span class="tabular">{{ format.number(row.members) }}</span>
            </template>

            <template #cell-createdAt="{ row }">
                <time
                    v-if="row.createdAt"
                    :datetime="row.createdAt"
                    :title="format.date(row.createdAt, 'datetime')"
                    class="tabular text-ink-subtle"
                >
                    {{ format.relative(row.createdAt) }}
                </time>
                <span v-else class="text-ink-subtle">—</span>
            </template>
        </DataTable>
    </Card>
</template>
