<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Plus, Scale } from 'lucide-vue-next';
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
import RuleDisclaimer from '../../Components/RuleDisclaimer.vue';
import RuleSetDialog from '../../Components/RuleSetDialog.vue';
import { todayIn } from '../../Components/payrollMoney';
import type { RuleOptions, RuleSet } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ ruleSets: RuleSet[]; options: RuleOptions; currency: string; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

const creating = ref(false);
const today = computed(() => todayIn(page.props.app.timezone));
const columns = computed<Column[]>(() => [
    { key: 'name', label: t('payroll.fields.name') },
    { key: 'category', label: t('payroll.fields.category'), hideable: true },
    { key: 'payer', label: t('payroll.fields.payer') },
    { key: 'basis', label: t('payroll.fields.basis'), hideable: true },
    { key: 'effective', label: t('payroll.fields.effectiveFrom'), hideable: true },
    { key: 'version', label: t('payroll.rules.version'), align: 'right', hideable: true },
    { key: 'status', label: t('payroll.fields.status') },
    { key: 'actions', label: t('payroll.common.actions'), srOnly: true, align: 'right' },
]);
</script>

<template>
    <PageHeader :eyebrow="t('payroll.eyebrow')" :title="t('payroll.rules.title')" :description="t('payroll.rules.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="creating = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('payroll.rules.create') }}
            </Button>
        </template>
    </PageHeader>

    <RuleDisclaimer />

    <Card flush>
        <DataTable :columns="columns" :rows="ruleSets" row-key="id" :caption="t('payroll.rules.caption')">
            <template #cell-name="{ row }">
                <Button variant="ghost" size="sm" :href="route('payroll.rules.show', { ruleSet: row.id })">{{ row.name }}</Button>
                <span class="ml-1 font-mono text-xs text-ink-subtle">{{ row.code }}</span>
            </template>
            <template #cell-category="{ row }"><span class="text-ink-muted">{{ t(`payroll.ruleCategories.${row.category}`) }}</span></template>
            <template #cell-payer="{ row }">
                <Badge :tone="row.payer === 'employer' ? 'info' : 'neutral'">{{ t(`payroll.rulePayers.${row.payer}`) }}</Badge>
            </template>
            <template #cell-basis="{ row }"><span class="text-ink-muted">{{ t(`payroll.ruleBases.${row.basis}`) }}</span></template>
            <template #cell-effective="{ row }">
                <span class="tabular text-ink-muted">{{ format.date(row.effectiveFrom) }}<template v-if="row.effectiveTo"> - {{ format.date(row.effectiveTo) }}</template></span>
            </template>
            <template #cell-version="{ row }"><span class="tabular font-mono text-ink-muted">{{ format.number(row.version) }}</span></template>
            <template #cell-status="{ row }">
                <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('payroll.common.active') : t('payroll.common.inactive') }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" :href="route('payroll.rules.show', { ruleSet: row.id })">{{ t('payroll.common.view') }}</Button>
            </template>
            <template #empty>
                <EmptyState :title="t('payroll.rules.empty')" :description="t(canManage ? 'payroll.rules.emptyBody' : 'payroll.rules.emptyViewOnly')">
                    <template #icon><Scale /></template>
                    <Button v-if="canManage" variant="primary" @click="creating = true">{{ t('payroll.rules.create') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <RuleSetDialog v-if="canManage" v-model:open="creating" :rule-set="null" :options="options" :currency="currency" :today="today" />
</template>
