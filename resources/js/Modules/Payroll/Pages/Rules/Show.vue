<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Pencil } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import RuleDisclaimer from '../../Components/RuleDisclaimer.vue';
import RuleSetDialog from '../../Components/RuleSetDialog.vue';
import { usePayrollFormat } from '../../Components/payrollFormat';
import { todayIn } from '../../Components/payrollMoney';
import type { RuleOptions, RuleSet, Slab } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ ruleSet: RuleSet; options: RuleOptions; currency: string; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();
const pay = usePayrollFormat();
const page = usePage();

const editing = ref(false);
const today = computed(() => todayIn(page.props.app.timezone));
const columns = computed<Column[]>(() => [
    { key: 'position', label: t('payroll.rules.bandNumber') },
    { key: 'from', label: t('payroll.fields.from'), align: 'right' },
    { key: 'to', label: t('payroll.fields.to'), align: 'right' },
    { key: 'rate', label: t('payroll.fields.rate'), align: 'right' },
    { key: 'fixed', label: t('payroll.fields.fixed'), align: 'right' },
]);
const rows = computed(() => props.ruleSet.slabs.map((slab) => ({ ...slab, id: String(slab.position) })));
</script>

<template>
    <PageHeader :eyebrow="t('payroll.rules.title')" :title="ruleSet.name" :description="ruleSet.code">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.common.edit') }}
            </Button>
        </template>
    </PageHeader>

    <RuleDisclaimer />

    <div class="grid gap-3 lg:grid-cols-3">
        <Card :title="t('payroll.rules.details')">
            <dl class="grid gap-3 text-sm">
                <div><dt class="eyebrow">{{ t('payroll.fields.status') }}</dt><dd class="mt-1"><Badge :tone="ruleSet.isActive ? 'ok' : 'neutral'" dot>{{ ruleSet.isActive ? t('payroll.common.active') : t('payroll.common.inactive') }}</Badge></dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.category') }}</dt><dd class="mt-0.5 text-ink">{{ t(`payroll.ruleCategories.${ruleSet.category}`) }}</dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.payer') }}</dt><dd class="mt-0.5 text-ink">{{ t(`payroll.rulePayers.${ruleSet.payer}`) }}</dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.basis') }}</dt><dd class="mt-0.5 text-ink">{{ t(`payroll.ruleBases.${ruleSet.basis}`) }}</dd></div>
                <div><dt class="eyebrow">{{ t('payroll.fields.ruleMethod') }}</dt><dd class="mt-0.5 text-ink">{{ t(`payroll.ruleMethods.${ruleSet.method}`) }}</dd></div>
                <div v-if="ruleSet.annualize">
                    <dt class="eyebrow">{{ t('payroll.rules.annualize') }}</dt>
                    <dd class="mt-0.5 text-ink">{{ t('payroll.rules.annualizedOver', { count: format.number(ruleSet.periodsPerYear) }) }}</dd>
                </div>
                <div><dt class="eyebrow">{{ t('payroll.fields.effectiveFrom') }}</dt><dd class="tabular mt-0.5 text-ink">{{ format.date(ruleSet.effectiveFrom) }}<template v-if="ruleSet.effectiveTo"> - {{ format.date(ruleSet.effectiveTo) }}</template></dd></div>
                <div v-if="ruleSet.minAmount !== null"><dt class="eyebrow">{{ t('payroll.fields.minAmount') }}</dt><dd class="tabular mt-0.5 font-mono text-ink">{{ pay.money(ruleSet.minAmount, currency) }}</dd></div>
                <div v-if="ruleSet.maxAmount !== null"><dt class="eyebrow">{{ t('payroll.fields.maxAmount') }}</dt><dd class="tabular mt-0.5 font-mono text-ink">{{ pay.money(ruleSet.maxAmount, currency) }}</dd></div>
                <div v-if="ruleSet.employmentTypes.length > 0">
                    <dt class="eyebrow">{{ t('payroll.fields.employmentTypes') }}</dt>
                    <dd class="mt-0.5 text-ink">{{ ruleSet.employmentTypes.map((type) => t(`payroll.employmentTypes.${type}`)).join(', ') }}</dd>
                </div>
                <div><dt class="eyebrow">{{ t('payroll.rules.version') }}</dt><dd class="tabular mt-0.5 font-mono text-ink">{{ format.number(ruleSet.version) }}</dd></div>
                <div v-if="ruleSet.notes"><dt class="eyebrow">{{ t('payroll.fields.note') }}</dt><dd class="mt-0.5 text-ink">{{ ruleSet.notes }}</dd></div>
            </dl>
        </Card>

        <Card flush :title="t('payroll.rules.slabs')" :description="t('payroll.rules.slabsShown')" class="lg:col-span-2">
            <DataTable :columns="columns" :rows="rows" row-key="id" :caption="t('payroll.rules.slabsCaption')">
                <template #cell-position="{ row }"><span class="tabular font-mono text-ink">{{ format.number((row as Slab).position) }}</span></template>
                <template #cell-from="{ row }"><span class="tabular font-mono">{{ pay.money((row as Slab).from, currency) }}</span></template>
                <template #cell-to="{ row }">
                    <span v-if="(row as Slab).to !== null" class="tabular font-mono">{{ pay.money((row as Slab).to as number, currency) }}</span>
                    <span v-else class="text-ink-muted">{{ t('payroll.rules.noUpperLimit') }}</span>
                </template>
                <template #cell-rate="{ row }"><span class="tabular font-mono">{{ pay.percent((row as Slab).rateBps) }}</span></template>
                <template #cell-fixed="{ row }"><span class="tabular font-mono">{{ pay.money((row as Slab).fixed, currency) }}</span></template>
            </DataTable>
        </Card>
    </div>

    <RuleSetDialog v-if="canManage" v-model:open="editing" :rule-set="ruleSet" :options="options" :currency="currency" :today="today" />
</template>
