<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { GraduationCap, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CourseDialog from '../../Components/CourseDialog.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { Course, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    courses: Paginated<Course>;
    filters: { active: string };
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const active = ref(props.filters.active);
const creating = ref(false);

const activeOptions = computed(() => [
    { value: '1', label: t('talent.courses.filterActive') },
    { value: '0', label: t('talent.courses.filterInactive') },
    { value: 'all', label: t('talent.common.all') },
]);

const columns = computed<Column[]>(() => [
    { key: 'title', label: t('talent.fields.title') },
    { key: 'provider', label: t('talent.courses.provider'), hideable: true },
    { key: 'duration', label: t('talent.courses.duration'), hideable: true },
    { key: 'validity', label: t('talent.courses.validity'), hideable: true },
    { key: 'sessions', label: t('talent.courses.upcoming'), align: 'right' },
]);

watch(active, (value) => {
    router.get(route('talent.courses.index'), { active: value === '1' ? undefined : value }, { preserveState: true, replace: true });
});
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.courses.title')" :description="t('talent.courses.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="creating = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.courses.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <div class="border-b border-line px-5 py-3">
            <Select v-model="active" :options="activeOptions" :label="t('talent.fields.status')" class="w-full sm:w-52" />
        </div>

        <DataTable :columns="columns" :rows="courses.data" row-key="id" :caption="t('talent.courses.caption')" storage-key="talent-courses">
            <template #cell-title="{ row }">
                <Link :href="route('talent.courses.show', { course: row.id })" class="text-ink hover:text-brand-text">{{ row.title }}</Link>
                <span class="mt-1 flex flex-wrap gap-1">
                    <Badge v-if="row.isMandatory" tone="warn">{{ t('talent.courses.mandatoryBadge') }}</Badge>
                    <Badge v-if="row.validityMonths !== null" tone="info">{{ t('talent.courses.certification') }}</Badge>
                    <Badge v-if="!row.isActive" tone="neutral">{{ t('talent.common.inactive') }}</Badge>
                </span>
            </template>
            <template #cell-provider="{ row }"><span class="text-ink-muted">{{ row.provider ?? t('talent.common.dash') }}</span></template>
            <template #cell-duration="{ row }">
                <span class="tabular text-ink-muted">{{ row.durationMinutes === null ? t('talent.common.dash') : talent.duration(row.durationMinutes) }}</span>
            </template>
            <template #cell-validity="{ row }">
                <span class="tabular text-ink-muted">
                    {{ row.validityMonths === null ? t('talent.common.dash') : t('talent.courses.months', { count: format.number(row.validityMonths) }) }}
                </span>
            </template>
            <template #cell-sessions="{ row }"><span class="tabular text-ink">{{ format.number(row.upcomingSessions ?? 0) }}</span></template>
            <template #empty>
                <EmptyState :title="t('talent.courses.empty')" :description="t('talent.courses.emptyBody')" compact>
                    <template #icon><GraduationCap /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="courses" />
    </Card>

    <CourseDialog v-if="canManage" v-model:open="creating" :course="null" />
</template>
