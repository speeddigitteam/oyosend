<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronDown, Pencil, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AddCandidateDialog from '../../Components/AddCandidateDialog.vue';
import OpeningForm from '../../Components/OpeningForm.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import type { ApplicationRow, OpeningForm as OpeningFormOptions, OpeningRow, OpeningStatus, PipelineDetail } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    opening: OpeningRow;
    pipeline: PipelineDetail;
    applications: ApplicationRow[];
    statusOptions: OpeningStatus[];
    abilities: { edit: boolean; changeStatus: boolean; viewApplications: boolean; addApplication: boolean; viewCandidates: boolean };
    form: OpeningFormOptions | null;
}>();

const { t } = useI18n();
const format = useFormat();

const editing = ref(false);
const adding = ref(false);
const confirmClose = ref(false);
const busy = ref(false);

const stageCounts = computed(() => props.pipeline.stages.map((stage) => ({
    stage,
    count: props.applications.filter((application) => application.stage.id === stage.id).length,
})));

const columns = computed<Column[]>(() => [
    { key: 'candidate', label: t('talent.fields.candidate') },
    { key: 'stage', label: t('talent.fields.stage') },
    { key: 'status', label: t('talent.fields.status') },
    { key: 'appliedOn', label: t('talent.applications.appliedLabel'), hideable: true },
]);

const interactive = computed(() => props.abilities.edit || props.abilities.changeStatus || props.abilities.addApplication);

function setStatus(status: OpeningStatus): void {
    if (status === 'closed') {
        confirmClose.value = true;

        return;
    }

    post(status);
}

function post(status: OpeningStatus): void {
    busy.value = true;
    router.post(route('talent.openings.status', { opening: props.opening.id }), { status }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; confirmClose.value = false; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="opening.title">
        <template v-if="interactive" #actions>
            <Button v-if="abilities.addApplication && abilities.viewCandidates" variant="secondary" @click="adding = true">
                <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.openings.addCandidate') }}
            </Button>
            <Button v-if="abilities.edit && form" variant="secondary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('talent.common.edit') }}
            </Button>
            <Dropdown v-if="abilities.changeStatus && statusOptions.length > 0">
                <template #trigger>
                    <Button variant="secondary" :loading="busy">
                        {{ t('talent.openings.changeStatus') }}
                        <ChevronDown class="size-4" aria-hidden="true" />
                    </Button>
                </template>
                <DropdownItem v-for="status in statusOptions" :key="status" :tone="status === 'closed' ? 'danger' : 'default'" @select="setStatus(status)">
                    {{ t(`talent.openings.statusAction.${status}`) }}
                </DropdownItem>
            </Dropdown>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-8">
            <Card :title="t('talent.openings.pipeline')" :description="pipeline.name">
                <ol class="flex flex-wrap gap-2" data-testid="stage-counts">
                    <li v-for="item in stageCounts" :key="item.stage.id" class="glass-well flex min-w-28 flex-col rounded-control px-3 py-2">
                        <span class="eyebrow">{{ item.stage.name }}</span>
                        <span class="figure tabular mt-1 text-xl text-ink">{{ format.number(item.count) }}</span>
                    </li>
                </ol>
            </Card>

            <Card v-if="abilities.viewApplications" flush :title="t('talent.openings.applications')">
                <DataTable :columns="columns" :rows="applications" row-key="id" :caption="t('talent.openings.applicationsCaption')">
                    <template #cell-candidate="{ row }">
                        <Link :href="route('talent.applications.show', { application: row.id })" class="text-ink hover:text-brand-text">
                            {{ row.candidate.name ?? t('talent.candidates.erased') }}
                        </Link>
                        <span v-if="row.candidate.headline" class="block text-xs text-ink-subtle">{{ row.candidate.headline }}</span>
                    </template>
                    <template #cell-stage="{ row }"><span class="text-ink-muted">{{ row.stage.name }}</span></template>
                    <template #cell-status="{ row }"><TalentBadge kind="application" :value="row.status" /></template>
                    <template #cell-appliedOn="{ row }"><span class="text-ink-muted">{{ format.date(row.appliedOn) }}</span></template>
                    <template #empty>
                        <EmptyState :title="t('talent.openings.noApplications')" :description="t('talent.openings.noApplicationsBody')" compact />
                    </template>
                </DataTable>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-4">
            <Card :title="t('talent.common.details')">
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                        <dd class="mt-1"><TalentBadge kind="opening" :value="opening.status" /></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.openings.headcount') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ t('talent.openings.hiresOf', { hired: format.number(opening.hired), total: format.number(opening.headcount) }) }}</dd>
                    </div>
                    <div v-if="opening.hiringManager">
                        <dt class="eyebrow">{{ t('talent.fields.hiringManager') }}</dt>
                        <dd class="mt-1 text-ink">{{ opening.hiringManager.name }}</dd>
                    </div>
                    <div v-if="opening.department">
                        <dt class="eyebrow">{{ t('talent.fields.department') }}</dt>
                        <dd class="mt-1 text-ink">{{ opening.department.name }}</dd>
                    </div>
                    <div v-if="opening.branch">
                        <dt class="eyebrow">{{ t('talent.fields.branch') }}</dt>
                        <dd class="mt-1 text-ink">{{ opening.branch.name }}</dd>
                    </div>
                    <div v-if="opening.employmentType">
                        <dt class="eyebrow">{{ t('talent.fields.employmentType') }}</dt>
                        <dd class="mt-1 text-ink">{{ t(`talent.status.employmentType.${opening.employmentType}`) }}</dd>
                    </div>
                    <div v-if="opening.location">
                        <dt class="eyebrow">{{ t('talent.fields.location') }}</dt>
                        <dd class="mt-1 text-ink">{{ opening.location }}</dd>
                    </div>
                    <div v-if="opening.opensOn">
                        <dt class="eyebrow">{{ t('talent.openings.opensOn') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(opening.opensOn) }}</dd>
                    </div>
                    <div v-if="opening.closesOn">
                        <dt class="eyebrow">{{ t('talent.openings.closesOn') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(opening.closesOn) }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="opening.description" :title="t('talent.openings.descriptionLabel')">
                <p class="text-sm whitespace-pre-line text-ink">{{ opening.description }}</p>
            </Card>
            <Card v-if="opening.requirements" :title="t('talent.openings.requirements')">
                <p class="text-sm whitespace-pre-line text-ink">{{ opening.requirements }}</p>
            </Card>
        </div>
    </div>

    <Modal v-if="form" v-model:open="editing" size="lg" :title="t('talent.openings.edit')">
        <OpeningForm :options="form" :opening="opening" @saved="editing = false" @cancel="editing = false" />
    </Modal>

    <AddCandidateDialog v-if="abilities.addApplication && abilities.viewCandidates" v-model:open="adding" :opening-id="opening.id" />

    <ConfirmDialog
        v-model:open="confirmClose"
        :title="t('talent.openings.closeTitle')"
        :description="t('talent.openings.closeBody')"
        :confirm-label="t('talent.openings.close')"
        :loading="busy"
        @confirm="post('closed')"
    />
</template>
