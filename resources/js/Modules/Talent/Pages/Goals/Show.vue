<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BarMeter from '../../Components/BarMeter.vue';
import GoalDialog from '../../Components/GoalDialog.vue';
import ProgressDialog from '../../Components/ProgressDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { Goal, GoalUpdateRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    goal: Goal;
    updates: GoalUpdateRow[];
    abilities: { edit: boolean; recordProgress: boolean; close: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

type Closing = 'achieved' | 'missed' | 'cancelled';

const editing = ref(false);
const recording = ref(false);
const closing = ref<Closing | null>(null);
const closeOpen = ref(false);
const busy = ref(false);

const CLOSINGS: Closing[] = ['achieved', 'missed', 'cancelled'];
const hasActions = computed(() => props.abilities.edit || props.abilities.recordProgress || props.abilities.close);

function ask(status: Closing): void {
    closing.value = status;
    closeOpen.value = true;
}

function close(): void {
    if (closing.value === null) {
        return;
    }

    busy.value = true;
    router.post(route('talent.goals.status', { goal: props.goal.id }), { status: closing.value }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; closeOpen.value = false; closing.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.goals.title')" :title="goal.title" :description="goal.employee?.name">
        <template v-if="hasActions" #actions>
            <Button v-if="abilities.recordProgress" variant="primary" @click="recording = true">
                <template #icon><TrendingUp class="size-4" aria-hidden="true" /></template>
                {{ t('talent.goals.recordProgress') }}
            </Button>
            <Button v-if="abilities.edit" variant="secondary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('talent.common.edit') }}
            </Button>
            <Dropdown v-if="abilities.close">
                <template #trigger>
                    <Button variant="secondary">{{ t('talent.goals.closeGoal') }}</Button>
                </template>
                <DropdownItem v-for="status in CLOSINGS" :key="status" :tone="status === 'cancelled' ? 'danger' : 'default'" @select="ask(status)">
                    {{ t(`talent.goals.close.${status}`) }}
                </DropdownItem>
            </Dropdown>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-7">
            <Card :title="t('talent.goals.progress')">
                <div class="grid gap-3">
                    <BarMeter :bp="goal.progressBp" :label="t('talent.goals.progressOf', { title: goal.title })" />
                    <dl class="grid grid-cols-3 gap-3 text-sm">
                        <div>
                            <dt class="eyebrow">{{ t('talent.goals.startValue') }}</dt>
                            <dd class="figure tabular mt-1 text-lg text-ink">{{ talent.value(goal.startValue, goal.unit) }}</dd>
                        </div>
                        <div>
                            <dt class="eyebrow">{{ t('talent.goals.currentValue') }}</dt>
                            <dd class="figure tabular mt-1 text-lg text-ink" data-testid="goal-current">{{ talent.value(goal.currentValue, goal.unit) }}</dd>
                        </div>
                        <div>
                            <dt class="eyebrow">{{ t('talent.goals.targetValue') }}</dt>
                            <dd class="figure tabular mt-1 text-lg text-ink">{{ talent.value(goal.targetValue, goal.unit) }}</dd>
                        </div>
                    </dl>
                </div>
            </Card>

            <Card :title="t('talent.goals.updates')">
                <EmptyState v-if="updates.length === 0" :title="t('talent.goals.noUpdates')" compact />
                <ol v-else class="grid gap-3" data-testid="goal-updates">
                    <li v-for="update in updates" :key="update.id" class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                        <span class="text-ink">
                            <span class="tabular">{{ talent.value(update.value, goal.unit) }}</span>
                            <span v-if="update.note" class="text-ink-muted"> - {{ update.note }}</span>
                        </span>
                        <span class="text-xs text-ink-subtle">{{ format.date(update.recordedOn) }}</span>
                    </li>
                </ol>
            </Card>
        </div>

        <Card class="min-w-0 lg:col-span-5" :title="t('talent.common.details')">
            <dl class="grid gap-3 text-sm">
                <div>
                    <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                    <dd class="mt-1 flex items-center gap-2">
                        <TalentBadge kind="goal" :value="goal.status" />
                        <span class="text-ink-muted">{{ t(`talent.status.goalKind.${goal.kind}`) }}</span>
                    </dd>
                </div>
                <div v-if="goal.dueOn">
                    <dt class="eyebrow">{{ t('talent.fields.dueOn') }}</dt>
                    <dd class="mt-1 text-ink">{{ format.date(goal.dueOn) }}</dd>
                </div>
                <div v-if="goal.achievedAt">
                    <dt class="eyebrow">{{ t('talent.goals.achievedOn') }}</dt>
                    <dd class="mt-1 text-ink">{{ format.date(goal.achievedAt) }}</dd>
                </div>
            </dl>
            <p v-if="goal.description" class="mt-4 border-t border-line pt-3 text-sm whitespace-pre-line text-ink">{{ goal.description }}</p>
        </Card>
    </div>

    <GoalDialog
        v-if="abilities.edit"
        v-model:open="editing"
        :goal="goal"
        :kinds="[]"
        :employees="[]"
        :cycles="[]"
        :own-employee-id="null"
    />
    <ProgressDialog v-if="abilities.recordProgress" v-model:open="recording" :goal="goal" />
    <ConfirmDialog
        v-model:open="closeOpen"
        :title="closing ? t(`talent.goals.confirm.${closing}.title`) : ''"
        :description="closing ? t(`talent.goals.confirm.${closing}.body`) : ''"
        :confirm-label="closing ? t(`talent.goals.close.${closing}`) : ''"
        :tone="closing === 'cancelled' ? 'danger' : 'default'"
        :loading="busy"
        @confirm="close"
    />
</template>
