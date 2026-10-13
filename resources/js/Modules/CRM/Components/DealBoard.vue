<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, CalendarClock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { Deal, Pipeline, PipelineStage } from './types';

const props = defineProps<{
    pipelines: Pipeline[];
    boardDeals: Deal[];
    selectedPipelineId: string | null;
}>();

const { t } = useI18n();
const format = useFormat();

const pipeline = computed(() => props.pipelines.find((p) => p.id === props.selectedPipelineId) ?? props.pipelines[0] ?? null);
const grouped = computed(() => (pipeline.value?.stages ?? []).map((stage) => ({ stage, deals: props.boardDeals.filter((deal) => deal.stage.id === stage.id) })));

const draggingDealId = ref<string | null>(null);
const draggingOverStageId = ref<string | null>(null);
const saving = ref(false);
const error = ref('');
const pendingClose = ref<{ deal: Deal; stage: PipelineStage } | null>(null);
const closeNote = ref<string | null>('');
const closeOpen = computed({
    get: () => pendingClose.value !== null,
    set: (open: boolean) => { if (!open && !saving.value) pendingClose.value = null; },
});

function onDragStart(event: DragEvent, deal: Deal): void {
    draggingDealId.value = deal.id;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', deal.id);
    }
}

function onDragOver(event: DragEvent, stageId: string): void {
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    draggingOverStageId.value = stageId;
}

function onDragLeave(event: DragEvent, stageId: string): void {
    const related = event.relatedTarget as Element | null;
    if (related && (event.currentTarget as Element).contains(related)) return;
    if (draggingOverStageId.value === stageId) draggingOverStageId.value = null;
}

function onDrop(event: DragEvent, stageId: string): void {
    event.preventDefault();
    const dealId = draggingDealId.value;
    draggingOverStageId.value = null;
    draggingDealId.value = null;
    if (!dealId || saving.value) return;
    const deal = props.boardDeals.find((d) => d.id === dealId);
    const stage = pipeline.value?.stages.find((s) => s.id === stageId);
    if (!deal || !stage || deal.stage.id === stageId || !deal.editable) return;
    // Won and lost stages close the deal for good, so they are confirmed first, as on the deal page.
    if (stage.won || stage.lost) {
        error.value = '';
        closeNote.value = '';
        pendingClose.value = { deal, stage };
        return;
    }
    moveDeal(deal.id, stage.id, null);
}

function moveDeal(dealId: string, stageId: string, note: string | null): void {
    error.value = '';
    saving.value = true;
    router.post(route('crm.deals.stage', { deal: dealId }), { stage_id: stageId, note }, {
        preserveScroll: true,
        onSuccess: () => { pendingClose.value = null; },
        onError: (errors) => { error.value = Object.values(errors)[0] ?? ''; },
        onFinish: () => { saving.value = false; },
    });
}

function confirmClose(): void {
    if (!pendingClose.value || saving.value) return;
    moveDeal(pendingClose.value.deal.id, pendingClose.value.stage.id, closeNote.value?.trim() || null);
}

function onDragEnd(): void {
    draggingDealId.value = null;
    draggingOverStageId.value = null;
}
</script>

<template>
    <div v-if="pipeline">
        <p v-if="error && !pendingClose" role="alert" class="mb-3 text-sm text-danger">{{ error }}</p>
        <div class="overflow-x-auto pb-2">
            <div
                role="region"
                class="grid gap-3"
                :style="`grid-template-columns: repeat(${pipeline.stages.length}, minmax(14rem, 1fr)); min-width: ${pipeline.stages.length * 14}rem`"
                :aria-label="t('crm.deals.title')"
            >
                <section
                    v-for="{ stage, deals } in grouped"
                    :key="stage.id"
                    class="glass-well min-w-0 rounded-card p-2 transition-shadow"
                    :class="draggingOverStageId === stage.id ? 'ring-2 ring-inset ring-brand' : ''"
                    :aria-labelledby="`deal-col-${stage.id}`"
                    @dragover="onDragOver($event, stage.id)"
                    @dragleave="onDragLeave($event, stage.id)"
                    @drop="onDrop($event, stage.id)"
                >
                    <header class="mb-2 flex items-center justify-between gap-2 px-1 py-1">
                        <h3 :id="`deal-col-${stage.id}`" class="truncate text-sm font-semibold text-ink">{{ stage.name }}</h3>
                        <span class="shrink-0 rounded-chip bg-hover px-2 font-mono text-xs text-ink-muted">{{ format.number(deals.length) }}</span>
                    </header>
                    <div class="grid gap-2">
                        <Link
                            v-for="deal in deals"
                            :key="deal.id"
                            :href="route('crm.deals.show', { deal: deal.id })"
                            class="glass block rounded-control p-3 text-left transition hover:border-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                            :class="[draggingDealId === deal.id ? 'opacity-40' : '', deal.editable ? 'cursor-grab active:cursor-grabbing' : '']"
                            :draggable="deal.editable ? 'true' : 'false'"
                            @dragstart="deal.editable ? onDragStart($event, deal) : $event.preventDefault()"
                            @dragend="onDragEnd"
                        >
                            <span class="block text-sm font-semibold text-ink">{{ deal.title }}</span>
                            <span class="mt-2 block font-mono text-sm font-semibold text-brand-text">{{ format.money(deal.amount, deal.currency) }}</span>
                            <span v-if="deal.company || deal.contact" class="mt-2 block truncate text-xs text-ink-muted">{{ deal.company?.name ?? deal.contact?.name }}</span>
                            <span v-if="deal.expectedCloseDate" class="mt-1 flex items-center gap-1 text-xs text-ink-subtle"><CalendarClock class="size-3.5 shrink-0" aria-hidden="true" />{{ format.date(deal.expectedCloseDate) }}</span>
                        </Link>
                        <p v-if="deals.length === 0" class="rounded-control border border-dashed border-line px-3 py-5 text-center text-xs text-ink-subtle">{{ t('crm.deals.boardEmpty') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <EmptyState v-else :title="t('crm.deals.boardNoPipeline')" compact>
        <template #icon><BriefcaseBusiness /></template>
    </EmptyState>

    <Modal v-model:open="closeOpen" :title="pendingClose ? t('crm.deals.moveTitle', { name: pendingClose.deal.title }) : t('crm.deals.move')" :persistent="saving">
        <form v-if="pendingClose" id="crm-board-close-form" class="grid gap-4" @submit.prevent="confirmClose">
            <p class="text-sm text-ink-muted">{{ t('crm.deals.closeWarning', { stage: pendingClose.stage.name }) }}</p>
            <Textarea v-model="closeNote" :label="t('crm.fields.note')" :hint="pendingClose.stage.lost ? t('crm.deals.lostReasonHint') : undefined" optional />
            <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="saving" @click="closeOpen = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-board-close-form" type="submit" variant="primary" :loading="saving">{{ t('crm.deals.move') }}</Button>
        </template>
    </Modal>
</template>
