<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChartNoAxesCombined } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { moduleLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import type { Bounds, CatalogueGroup, SavedRow } from '../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    catalogue: CatalogueGroup[];
    saved: SavedRow[];
    canSave: boolean;
    canExport: boolean;
    bounds: Bounds;
}>();

const { t } = useI18n();
const format = useFormat();

function rangeText(row: SavedRow): string {
    if (row.rangeMode === 'fixed' && row.rangeFrom !== null && row.rangeTo !== null) {
        return t('reports.range.fixed', { from: format.date(row.rangeFrom, 'date'), to: format.date(row.rangeTo, 'date') });
    }

    return t('reports.range.lastDays', { count: format.number(row.rangeDays) });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('reports.eyebrow')"
        :title="t('reports.index.title')"
        :description="t('reports.index.description')"
    />

    <Card v-if="saved.length > 0" class="mb-3" :title="t('reports.index.savedTitle')" :description="t('reports.index.savedHint')" flush>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm" data-testid="saved-reports">
                <caption class="sr-only">{{ t('reports.index.savedTitle') }}</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('reports.index.name') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('reports.index.report') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('reports.index.range') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('reports.index.sharing') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('reports.index.owner') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('reports.index.updated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in saved" :key="row.id" class="border-b border-line last:border-b-0">
                        <th scope="row" class="px-5 py-2 font-normal">
                            <Link v-if="row.available" :href="route('reports.saved.show', { saved: row.id })" class="font-medium text-ink hover:text-brand-text">
                                {{ row.name }}
                            </Link>
                            <span v-else class="text-ink-muted">{{ row.name }}</span>
                        </th>
                        <td class="px-5 py-2 text-ink-muted">
                            <template v-if="row.available">{{ row.providerLabel }}</template>
                            <Badge v-else tone="warn">{{ t('reports.index.unavailable') }}</Badge>
                        </td>
                        <td class="px-5 py-2 text-ink-muted">{{ rangeText(row) }}</td>
                        <td class="px-5 py-2"><Badge :tone="row.visibility === 'private' ? 'neutral' : 'info'">{{ t(`reports.visibility.${row.visibility}`) }}</Badge></td>
                        <td class="px-5 py-2 text-ink-muted">{{ row.isOwner ? t('reports.index.you') : (row.ownerName ?? t('reports.common.dash')) }}</td>
                        <td class="px-5 py-2 text-ink-muted">{{ row.updatedAt ? format.date(row.updatedAt, 'datetime') : t('reports.common.dash') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="saved.some((row) => !row.available)" class="px-5 pb-3 text-[0.8125rem] text-ink-subtle">{{ t('reports.index.unavailableHint') }}</p>
    </Card>

    <EmptyState
        v-if="catalogue.length === 0"
        :title="t('reports.index.emptyTitle')"
        :description="t('reports.index.emptyBody')"
        data-testid="no-reports"
    >
        <template #icon><ChartNoAxesCombined /></template>
    </EmptyState>

    <template v-else>
        <h2 class="mb-1 text-base">{{ t('reports.index.catalogueTitle') }}</h2>
        <p class="mb-3 text-[0.8125rem] text-ink-muted">
            {{ t('reports.index.catalogueHint', { days: format.number(bounds.maxDays) }) }}
            <template v-if="canSave && saved.length === 0"> {{ t('reports.index.savedEmpty') }}</template>
        </p>

        <div class="grid gap-3 lg:grid-cols-2" data-testid="catalogue">
            <Card v-for="group in catalogue" :key="group.module" :title="moduleLabel(group.module)" flush>
                <ul class="divide-y divide-line border-t border-line">
                    <li v-for="entry in group.reports" :key="entry.key">
                        <Link
                            :href="route('reports.run', { provider: entry.key })"
                            class="block px-5 py-3 transition-colors hover:bg-hover focus-visible:bg-hover"
                        >
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-ink">{{ entry.label }}</span>
                                <Badge v-if="!entry.usesDateRange" tone="neutral">{{ t('reports.index.snapshot') }}</Badge>
                            </span>
                            <span class="mt-0.5 block text-[0.8125rem] text-ink-muted">{{ entry.description }}</span>
                        </Link>
                    </li>
                </ul>
            </Card>
        </div>
    </template>
</template>
