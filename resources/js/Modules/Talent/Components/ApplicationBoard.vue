<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarClock, GripVertical } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import TalentBadge from './TalentBadge.vue';
import type { ApplicationRow, Stage } from './types';

/**
 * Applications by pipeline stage. A card moves by drag and drop with a
 * pointer, or - for keyboards, touch and screen readers - with the "Move to"
 * list on the card, which offers the same stages. Only open applications move,
 * and only between active stages: hiring and rejecting have their own steps.
 */
const props = defineProps<{
    applications: ApplicationRow[];
    stages: Stage[];
    canMove: boolean;
}>();

const emit = defineEmits<{ move: [application: ApplicationRow, stage: Stage] }>();

const { t } = useI18n();
const format = useFormat();

const dragging = ref<string | null>(null);
const over = ref<string | null>(null);
const picks = reactive<Record<string, string>>({});

const columns = computed(() => [...props.stages].sort((a, b) => (a.position ?? 0) - (b.position ?? 0)));
const activeStages = computed(() => columns.value.filter((stage) => stage.type === 'active'));

function inStage(stage: Stage): ApplicationRow[] {
    return props.applications.filter((application) => application.stage.id === stage.id);
}

function movable(application: ApplicationRow): boolean {
    return props.canMove && application.status === 'open';
}

function targets(application: ApplicationRow): { value: string; label: string }[] {
    return activeStages.value.filter((stage) => stage.id !== application.stage.id).map((stage) => ({ value: stage.id, label: stage.name }));
}

function requestMove(application: ApplicationRow, stageId: string): void {
    const stage = activeStages.value.find((candidate) => candidate.id === stageId);

    picks[application.id] = '';

    if (stage && stage.id !== application.stage.id && movable(application)) {
        emit('move', application, stage);
    }
}

function onDrop(stage: Stage): void {
    const application = props.applications.find((candidate) => candidate.id === dragging.value);

    dragging.value = null;
    over.value = null;

    if (application && stage.type === 'active') {
        requestMove(application, stage.id);
    }
}

function columnLabel(stage: Stage): string {
    return `${stage.name} (${format.number(inStage(stage).length)})`;
}
</script>

<template>
    <div class="overflow-x-auto pb-2">
        <div role="region" :aria-label="t('talent.applications.boardLabel')" class="grid auto-cols-[16.5rem] grid-flow-col gap-3" data-testid="application-board">
            <section
                v-for="stage in columns"
                :key="stage.id"
                class="glass-well min-w-0 rounded-card p-2"
                :class="over === stage.id ? 'outline-2 outline-brand' : ''"
                :aria-labelledby="`stage-${stage.id}`"
                :data-stage="stage.id"
                @dragover.prevent="stage.type === 'active' && dragging !== null ? (over = stage.id) : undefined"
                @dragleave="over = null"
                @drop.prevent="onDrop(stage)"
            >
                <header class="mb-2 flex items-center justify-between gap-2 px-1 py-1">
                    <h3 :id="`stage-${stage.id}`" class="min-w-0 truncate text-sm font-semibold text-ink" :title="columnLabel(stage)">{{ stage.name }}</h3>
                    <span class="tabular shrink-0 rounded-chip bg-hover px-2 text-xs text-ink-muted">{{ format.number(inStage(stage).length) }}</span>
                </header>

                <ul class="grid gap-2">
                    <li
                        v-for="application in inStage(stage)"
                        :key="application.id"
                        class="glass rounded-control p-3"
                        :draggable="movable(application)"
                        :data-application="application.id"
                        @dragstart="dragging = application.id"
                        @dragend="dragging = null; over = null"
                    >
                        <div class="flex items-start gap-1.5">
                            <GripVertical v-if="movable(application)" class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <Link :href="route('talent.applications.show', { application: application.id })" class="block text-sm font-semibold text-ink hover:text-brand-text">
                                    {{ application.candidate.name ?? t('talent.candidates.erased') }}
                                </Link>
                                <p v-if="application.candidate.headline" class="mt-0.5 truncate text-xs text-ink-subtle">{{ application.candidate.headline }}</p>
                            </div>
                        </div>
                        <p class="mt-2 flex items-center gap-1.5 text-xs text-ink-subtle">
                            <CalendarClock class="size-3.5" aria-hidden="true" />
                            {{ t('talent.applications.appliedOn', { date: format.date(application.appliedOn) }) }}
                        </p>
                        <div v-if="application.status !== 'open'" class="mt-2">
                            <TalentBadge kind="application" :value="application.status" />
                        </div>
                        <Select
                            v-if="movable(application) && targets(application).length > 0"
                            class="mt-3"
                            :model-value="picks[application.id] ?? ''"
                            :options="targets(application)"
                            :placeholder="t('talent.applications.moveTo')"
                            :aria-label="t('talent.applications.moveCard', { name: application.candidate.name ?? t('talent.candidates.erased') })"
                            @update:model-value="requestMove(application, String($event ?? ''))"
                        />
                    </li>
                    <li v-if="inStage(stage).length === 0" class="rounded-control border border-dashed border-line px-3 py-5 text-center text-xs text-ink-subtle">
                        {{ t('talent.applications.emptyStage') }}
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
