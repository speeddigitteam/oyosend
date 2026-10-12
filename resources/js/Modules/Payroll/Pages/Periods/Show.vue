<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Calculator, CircleCheck, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import PayrollBadge from '../../Components/PayrollBadge.vue';
import PeriodDialog from '../../Components/PeriodDialog.vue';
import PrepareRunDialog from '../../Components/PrepareRunDialog.vue';
import type { Period, Run } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    period: Period;
    runs: Run[];
    pendingItems: number;
    hasEnded: boolean;
    abilities: { manage: boolean; prepare: boolean; viewRuns: boolean };
}>();

const { t } = useI18n();
const format = useFormat();

const editing = ref(false);
const deleting = ref(false);
const closing = ref(false);
const preparing = ref(false);
const adjusting = ref(false);
const busy = ref(false);

const regular = computed(() => props.runs.find((run) => run.kind === 'regular') ?? null);
const openRun = computed(() => props.runs.some((run) => run.status === 'draft' || run.status === 'submitted'));
const editable = computed(() => props.abilities.manage && props.period.status === 'open' && props.runs.length === 0);
const canPrepare = computed(() =>
    props.abilities.prepare && ['open', 'processing'].includes(props.period.status) && regular.value === null && props.hasEnded);
const waitingForEnd = computed(() =>
    props.abilities.prepare && ['open', 'processing'].includes(props.period.status) && regular.value === null && !props.hasEnded);
const canAdjust = computed(() =>
    props.abilities.prepare && ['approved', 'paid'].includes(props.period.status) && props.pendingItems > 0 && !openRun.value);
const canClose = computed(() => props.abilities.manage && props.period.status === 'paid');
const closeBlocked = computed(() => openRun.value || props.pendingItems > 0);

function remove(): void {
    busy.value = true;
    router.delete(route('payroll.periods.destroy', { period: props.period.id }), {
        onFinish: () => { busy.value = false; deleting.value = false; },
    });
}

function close(): void {
    busy.value = true;
    router.post(route('payroll.periods.close', { period: props.period.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; closing.value = false; },
    });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('payroll.eyebrow')"
        :title="period.name"
        :description="`${format.date(period.startsOn)} - ${format.date(period.endsOn)}`"
    >
        <template #actions>
            <Button v-if="canPrepare" variant="primary" @click="preparing = true">
                <template #icon><Calculator class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.prepare') }}
            </Button>
            <Button v-if="canAdjust" @click="adjusting = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.runs.prepareAdjustment') }}
            </Button>
            <Button v-if="canClose && !closeBlocked" variant="primary" @click="closing = true">
                <template #icon><CircleCheck class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.periods.close') }}
            </Button>
            <Button v-if="editable" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.common.edit') }}
            </Button>
            <Button v-if="editable" variant="ghost" @click="deleting = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.common.delete') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-3">
        <Card :title="t('payroll.periods.details')" class="lg:col-span-1">
            <dl class="grid gap-3 text-sm">
                <div><dt class="eyebrow">{{ t('payroll.fields.status') }}</dt><dd class="mt-1"><PayrollBadge kind="period" :value="period.status" /></dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.kind') }}</dt><dd class="mt-0.5 text-ink">{{ t(`payroll.periodKinds.${period.kind}`) }}</dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.payDate') }}</dt><dd class="tabular mt-0.5 text-ink">{{ period.payDate ? format.date(period.payDate) : t('payroll.common.dash') }}</dd></div>
                <div v-if="period.closedAt"><dt class="eyebrow">{{ t('payroll.periods.closedAt') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.date(period.closedAt, 'datetime') }}</dd></div>
                <div>
                    <dt class="eyebrow">{{ t('payroll.periods.pendingItems') }}</dt>
                    <dd class="tabular mt-0.5 text-ink" data-testid="pending-items">{{ format.number(pendingItems) }}</dd>
                </div>
            </dl>
            <p v-if="waitingForEnd" class="mt-4 text-[0.8125rem] text-ink-subtle" data-testid="waiting-for-end">{{ t('payroll.periods.waitingForEnd') }}</p>
            <p v-if="canClose && closeBlocked" class="mt-4 text-[0.8125rem] text-ink-subtle" data-testid="close-blocked">{{ t('payroll.periods.closeBlocked') }}</p>
            <p v-if="period.status === 'closed'" class="mt-4 text-[0.8125rem] text-ink-subtle">{{ t('payroll.periods.closedBody') }}</p>
        </Card>

        <Card :title="t('payroll.periods.runs')" class="lg:col-span-2">
            <p v-if="!abilities.viewRuns" class="text-sm text-ink-subtle">{{ t('payroll.periods.runsHidden') }}</p>
            <p v-else-if="runs.length === 0" class="text-sm text-ink-subtle">{{ t('payroll.periods.noRuns') }}</p>
            <ul v-else class="grid divide-y divide-line">
                <li v-for="run in runs" :key="run.id" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        <Button variant="ghost" size="sm" :href="route('payroll.runs.show', { run: run.id })" class="font-mono">
                            {{ run.number ?? t('payroll.runs.draftNumber') }}
                        </Button>
                        <p class="mt-0.5 pl-3 text-xs text-ink-subtle">{{ t('payroll.runs.employees', { count: format.number(run.employeeCount) }) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <PayrollBadge kind="runKind" :value="run.kind" />
                        <PayrollBadge kind="run" :value="run.status" />
                        <span class="tabular font-mono text-sm text-ink">{{ format.money(run.kind === 'reversal' ? -run.netAmount : run.netAmount, run.currency) }}</span>
                    </div>
                </li>
            </ul>
        </Card>
    </div>

    <PeriodDialog v-if="editable" v-model:open="editing" :period="period" :today="period.startsOn" />
    <PrepareRunDialog v-if="canPrepare" v-model:open="preparing" :periods="[period]" :period-id="period.id" />
    <PrepareRunDialog v-if="canAdjust" v-model:open="adjusting" :periods="[period]" :period-id="period.id" adjustment />
    <ConfirmDialog
        v-if="editable"
        v-model:open="deleting"
        :title="t('payroll.periods.deleteTitle')"
        :description="t('payroll.periods.deleteBody', { name: period.name })"
        :confirm-label="t('payroll.common.delete')"
        :loading="busy"
        @confirm="remove"
    />
    <ConfirmDialog
        v-if="canClose"
        v-model:open="closing"
        tone="default"
        :title="t('payroll.periods.closeTitle')"
        :description="t('payroll.periods.closeBody', { name: period.name })"
        :confirm-label="t('payroll.periods.close')"
        :loading="busy"
        @confirm="close"
    />
</template>
