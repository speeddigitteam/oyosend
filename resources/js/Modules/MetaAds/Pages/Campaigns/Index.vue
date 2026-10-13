<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pause, Pencil, Play, Target } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChangeDialog, { type ChangeMode } from '../../Components/ChangeDialog.vue';
import MetaStatusBadge from '../../Components/MetaStatusBadge.vue';
import type { Level, ObjectRow, Paginated, Range } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    objects: Paginated<ObjectRow>;
    level: Level;
    filters: { status: string; q: string; account: string | null };
    range: Range;
    accounts: { id: string; name: string; currency: string }[];
    management: { canManage: boolean; enabled: boolean; maxIncreasePercent: number };
    hasInsights: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const level = ref<Level>(props.level);
const status = ref(props.filters.status);
const account = ref(props.filters.account ?? '');
const search = ref(props.filters.q);

const dialogOpen = ref(false);
const target = ref<ObjectRow | null>(null);
const mode = ref<ChangeMode>('pause');

const hasRows = computed(() => props.objects.data.length > 0);
const showActions = computed(() => props.management.canManage);

function visit(extra: Record<string, string> = {}): void {
    router.get(
        route('meta_ads.campaigns.index'),
        {
            level: level.value,
            ...(status.value !== 'all' ? { status: status.value } : {}),
            ...(account.value ? { account: account.value } : {}),
            ...(search.value.trim() ? { q: search.value.trim() } : {}),
            ...extra,
        },
        { preserveState: true, replace: true },
    );
}

function pick(value: Level): void {
    level.value = value;
    visit();
}

function ask(object: ObjectRow, action: ChangeMode): void {
    target.value = object;
    mode.value = action;
    dialogOpen.value = true;
}

function count(value: number | null | undefined): string {
    return value === null || value === undefined ? t('metaAds.notReported') : format.number(value);
}

function money(value: number | null | undefined, currency: string): string {
    return value === null || value === undefined ? t('metaAds.notReported') : format.money(value, currency);
}

function budget(object: ObjectRow): string {
    if (object.dailyBudget !== null) {
        return t('metaAds.campaigns.perDay', { amount: format.money(object.dailyBudget, object.currency) });
    }

    if (object.lifetimeBudget !== null) {
        return t('metaAds.campaigns.lifetime', { amount: format.money(object.lifetimeBudget, object.currency) });
    }

    return t('metaAds.campaigns.noBudget');
}

const levels = computed(() => (['campaign', 'adset', 'ad'] as Level[]).map((value) => ({ value, label: t(`metaAds.levels.${value}s`) })));
const statusOptions = computed(() => ['all', 'active', 'paused', 'other'].map((value) => ({ value, label: t(`metaAds.campaigns.statusFilter.${value}`) })));
const accountOptions = computed(() => [{ value: '', label: t('metaAds.filters.allAccounts') }, ...props.accounts.map((item) => ({ value: item.id, label: item.name }))]);
</script>

<template>
    <PageHeader :eyebrow="t('metaAds.eyebrow')" :title="t('metaAds.campaigns.title')" :description="t('metaAds.campaigns.description')" />

    <p
        v-if="management.canManage && !management.enabled"
        class="mb-3 rounded-card bg-info-soft px-4 py-3 text-[0.8125rem] text-info"
        role="status"
        data-testid="management-off"
    >
        {{ t('metaAds.campaigns.managementOff') }}
    </p>

    <Card class="mb-3">
        <div class="mb-3">
            <SegmentedControl :model-value="level" :options="levels" :label="t('metaAds.campaigns.level')" @update:model-value="pick($event)" />
        </div>
        <form class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="visit()">
            <Input v-model="search" type="search" :label="t('metaAds.campaigns.search')" class="w-full sm:w-56" />
            <Select v-model="status" :label="t('metaAds.campaigns.statusLabel')" :options="statusOptions" class="w-full sm:w-40" @update:model-value="visit({ status: String($event) === 'all' ? '' : String($event) })" />
            <Select v-if="accounts.length > 1" v-model="account" :label="t('metaAds.filters.account')" :options="accountOptions" class="w-full sm:w-52" @update:model-value="visit({ account: String($event) })" />
            <Button type="submit" size="sm">{{ t('metaAds.filters.apply') }}</Button>
        </form>
        <p v-if="hasInsights" class="mt-3 text-xs text-ink-subtle">
            {{ t('metaAds.campaigns.rangeNote', { from: format.date(range.from, 'date'), to: format.date(range.to, 'date') }) }}
        </p>
        <p v-else class="mt-3 text-xs text-ink-subtle">{{ t('metaAds.campaigns.noInsightsNote') }}</p>
    </Card>

    <Card flush>
        <div v-if="hasRows" class="overflow-x-auto">
            <table class="w-full text-left text-sm" data-testid="objects">
                <caption class="sr-only">{{ t(`metaAds.levels.${level}s`) }}</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t(`metaAds.levels.${level}`) }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 font-normal">{{ t('metaAds.fields.status') }}</th>
                        <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.fields.budget') }}</th>
                        <template v-if="hasInsights">
                            <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.spend') }}</th>
                            <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.leads') }}</th>
                            <th scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.kpi.costPerLead') }}</th>
                        </template>
                        <th v-if="showActions" scope="col" class="eyebrow px-5 py-2 text-right font-normal">{{ t('metaAds.fields.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in objects.data" :key="row.id" class="border-b border-line align-top last:border-b-0">
                        <th scope="row" class="max-w-xs px-5 py-3 font-normal">
                            <span class="block truncate text-ink" :title="row.name">{{ row.name }}</span>
                            <span class="block truncate text-xs text-ink-subtle">
                                {{ row.accountName }}<template v-if="row.parentName"> · {{ row.parentName }}</template><template v-if="row.objective"> · {{ row.objective }}</template>
                            </span>
                            <span v-if="row.blockedReason" class="mt-1 block text-xs text-ink-muted">{{ row.blockedReason }}</span>
                        </th>
                        <td class="px-5 py-3"><MetaStatusBadge :status="row.effectiveStatus ?? row.status" /></td>
                        <td class="tabular px-5 py-3 text-right whitespace-nowrap text-ink-muted">{{ budget(row) }}</td>
                        <template v-if="hasInsights">
                            <td class="tabular px-5 py-3 text-right text-ink">{{ money(row.insights?.spend, row.currency) }}</td>
                            <td class="tabular px-5 py-3 text-right text-ink-muted">{{ count(row.insights?.leads) }}</td>
                            <td class="tabular px-5 py-3 text-right text-ink-muted">{{ money(row.insights?.costPerLead, row.currency) }}</td>
                        </template>
                        <td v-if="showActions" class="px-5 py-3">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <Button v-if="row.canPause" size="sm" variant="ghost" :aria-label="`${t('metaAds.change.pause.action')}: ${row.name}`" @click="ask(row, 'pause')">
                                    <template #icon><Pause class="size-3.5" aria-hidden="true" /></template>
                                    {{ t('metaAds.change.pause.action') }}
                                </Button>
                                <Button v-if="row.canResume" size="sm" variant="ghost" :aria-label="`${t('metaAds.change.resume.action')}: ${row.name}`" @click="ask(row, 'resume')">
                                    <template #icon><Play class="size-3.5" aria-hidden="true" /></template>
                                    {{ t('metaAds.change.resume.action') }}
                                </Button>
                                <Button v-if="row.canEditBudget" size="sm" variant="ghost" :aria-label="`${t('metaAds.change.budget.action')}: ${row.name}`" @click="ask(row, 'budget')">
                                    <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                                    {{ t('metaAds.change.budget.action') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :title="t('metaAds.campaigns.empty')" :description="t('metaAds.campaigns.emptyBody')">
            <template #icon><Target /></template>
        </EmptyState>
    </Card>

    <Pagination v-if="hasRows" class="mt-3" :meta="objects" />

    <ChangeDialog v-model:open="dialogOpen" :object="target" :mode="mode" :max-increase-percent="management.maxIncreasePercent" />
</template>
