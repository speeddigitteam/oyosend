<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Pencil, Plus, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BarMeter from '../../Components/BarMeter.vue';
import CycleDialog from '../../Components/CycleDialog.vue';
import ReviewFormDialog from '../../Components/ReviewFormDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import type { CycleCompletion, CycleRow, NamedRef, ReviewFormDetail } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    cycles: CycleRow[];
    forms: ReviewFormDetail[];
    completion: CycleCompletion[];
    departments: NamedRef[];
}>();

const { t } = useI18n();
const format = useFormat();

const cycleOpen = ref(false);
const editingCycle = ref<CycleRow | null>(null);
const formOpen = ref(false);
const editingForm = ref<ReviewFormDetail | null>(null);

const completionById = computed(() => new Map(props.completion.map((item) => [item.id, item])));

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('talent.fields.name') },
    { key: 'period', label: t('talent.cycles.period') },
    { key: 'reviews', label: t('talent.cycles.reviews'), align: 'right' },
    { key: 'completion', label: t('talent.cycles.completion'), hideable: true },
    { key: 'status', label: t('talent.fields.status') },
    { key: 'actions', label: t('talent.common.actions'), srOnly: true, align: 'right' },
]);

/** A form used by a cycle that has been launched keeps its items and scale. */
function locked(form: ReviewFormDetail): boolean {
    return props.cycles.some((cycle) => cycle.formId === form.id && cycle.status !== 'draft');
}

function openCycle(cycle: CycleRow | null): void {
    editingCycle.value = cycle;
    cycleOpen.value = true;
}

function openForm(form: ReviewFormDetail | null): void {
    editingForm.value = form;
    formOpen.value = true;
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.cycles.title')" :description="t('talent.cycles.description')">
        <template #actions>
            <Button variant="primary" @click="openCycle(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.cycles.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush class="mb-3">
        <DataTable :columns="columns" :rows="cycles" row-key="id" :caption="t('talent.cycles.caption')">
            <template #cell-name="{ row }">
                <Link :href="route('talent.cycles.show', { cycle: row.id })" class="text-ink hover:text-brand-text">{{ row.name }}</Link>
            </template>
            <template #cell-period="{ row }">
                <span class="tabular text-ink-muted">{{ format.date(row.startsOn) }} - {{ format.date(row.endsOn) }}</span>
            </template>
            <template #cell-reviews="{ row }"><span class="tabular text-ink">{{ format.number(row.reviews) }}</span></template>
            <template #cell-completion="{ row }">
                <BarMeter
                    v-if="completionById.has(row.id)"
                    :bp="completionById.get(row.id)?.completionBp ?? 0"
                    :label="t('talent.cycles.completionOf', { name: row.name })"
                    compact
                />
                <span v-else class="text-ink-subtle">{{ t('talent.common.dash') }}</span>
            </template>
            <template #cell-status="{ row }"><TalentBadge kind="cycle" :value="row.status" /></template>
            <template #cell-actions="{ row }">
                <Button v-if="row.status === 'draft'" size="sm" variant="ghost" @click="openCycle(row)">{{ t('talent.common.edit') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="t('talent.cycles.empty')" :description="t('talent.cycles.emptyBody')" compact>
                    <template #icon><RefreshCw /></template>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Card :title="t('talent.forms.title')" :description="t('talent.forms.description')">
        <template #actions>
            <Button size="sm" variant="secondary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.forms.add') }}
            </Button>
        </template>
        <EmptyState v-if="forms.length === 0" :title="t('talent.forms.empty')" :description="t('talent.forms.emptyBody')" compact />
        <ul v-else class="divide-y divide-line" data-testid="review-forms">
            <li v-for="form in forms" :key="form.id" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0">
                    <p class="text-sm text-ink">{{ form.name }}</p>
                    <p class="text-xs text-ink-subtle">
                        {{ t('talent.forms.summary', { items: format.number(form.items.length), scale: format.number(form.scaleMax) }) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Badge v-if="!form.isActive" tone="neutral">{{ t('talent.common.inactive') }}</Badge>
                    <Badge v-if="locked(form)" tone="info">{{ t('talent.forms.locked') }}</Badge>
                    <Button size="sm" variant="ghost" @click="openForm(form)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.common.edit') }}
                    </Button>
                </div>
            </li>
        </ul>
    </Card>

    <CycleDialog v-model:open="cycleOpen" :cycle="editingCycle" :forms="forms" :departments="departments" />
    <ReviewFormDialog v-model:open="formOpen" :review-form="editingForm" :locked="editingForm !== null && locked(editingForm)" />
</template>
