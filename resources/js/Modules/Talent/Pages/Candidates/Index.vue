<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Plus, UserSearch } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CandidateDialog from '../../Components/CandidateDialog.vue';
import type { CandidateRow, ConsentBasis, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    candidates: Paginated<CandidateRow>;
    filters: { q: string; include_anonymized: boolean };
    consentBases: ConsentBasis[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const search = ref(props.filters.q);
const includeErased = ref(props.filters.include_anonymized);
const adding = ref(false);

const filtered = computed(() => search.value.trim() !== '' || includeErased.value);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('talent.fields.name') },
    { key: 'email', label: t('talent.fields.email'), hideable: true },
    { key: 'phone', label: t('talent.fields.phone'), hideable: true, hiddenByDefault: true },
    { key: 'source', label: t('talent.fields.source'), hideable: true },
    { key: 'applications', label: t('talent.candidates.applications'), align: 'right' },
    { key: 'retainUntil', label: t('talent.fields.retainUntil'), hideable: true, hiddenByDefault: true },
]);

function apply(): void {
    router.get(route('talent.candidates.index'), {
        q: search.value.trim() || undefined,
        include_anonymized: includeErased.value ? 1 : undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    search.value = '';
    includeErased.value = false;
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.candidates.title')" :description="t('talent.candidates.description')">
        <template v-if="canCreate" #actions>
            <Button variant="primary" @click="adding = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.candidates.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('talent.common.search')" maxlength="100" class="w-full sm:w-64" />
            <Checkbox v-model="includeErased" :label="t('talent.candidates.includeErased')" class="pb-2" />
            <Button type="submit" size="sm">{{ t('talent.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('talent.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="candidates.data" row-key="id" :caption="t('talent.candidates.caption')" storage-key="talent-candidates">
            <template #cell-name="{ row }">
                <Link :href="route('talent.candidates.show', { candidate: row.id })" class="text-ink hover:text-brand-text">
                    {{ row.name ?? t('talent.candidates.erased') }}
                </Link>
                <span v-if="row.headline" class="block text-xs text-ink-subtle">{{ row.headline }}</span>
                <Badge v-if="row.isAnonymized" tone="neutral" class="mt-1">{{ t('talent.candidates.erasedBadge') }}</Badge>
            </template>
            <template #cell-email="{ row }"><span class="text-ink-muted">{{ row.email ?? t('talent.common.dash') }}</span></template>
            <template #cell-phone="{ row }"><span class="text-ink-muted">{{ row.phone ?? t('talent.common.dash') }}</span></template>
            <template #cell-source="{ row }"><span class="text-ink-muted">{{ row.source ?? t('talent.common.dash') }}</span></template>
            <template #cell-applications="{ row }">
                <span class="tabular text-ink">{{ format.number(row.applications ?? 0) }}</span>
                <span v-if="(row.openApplications ?? 0) > 0" class="block text-xs text-ink-subtle">{{ t('talent.candidates.openCount', { count: format.number(row.openApplications ?? 0) }) }}</span>
            </template>
            <template #cell-retainUntil="{ row }"><span class="text-ink-muted">{{ row.retainUntil ? format.date(row.retainUntil) : t('talent.common.dash') }}</span></template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('talent.common.noMatches') : t('talent.candidates.empty')"
                    :description="filtered ? t('talent.common.noMatchesBody') : t('talent.candidates.emptyBody')"
                    compact
                >
                    <template #icon><UserSearch /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="candidates" />
    </Card>

    <CandidateDialog v-if="canCreate" v-model:open="adding" :candidate="null" :consent-bases="consentBases" />
</template>
