<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ClipboardList, Share2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import LeadLogDialog from '../../Components/LeadLogDialog.vue';
import LeadMappingDialog from '../../Components/LeadMappingDialog.vue';
import LeadPageCard from '../../Components/LeadPageCard.vue';
import {
    KNOWN_REASONS,
    LEAD_TONES,
    type LeadAdsAvailability,
    type LeadFormRow,
    type LeadLogRow,
    type LeadPageRow,
    type LeadRow,
    type MappingTarget,
} from '../../Components/leadAds';
import type { Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    availability: LeadAdsAvailability;
    pages: LeadPageRow[];
    leads: Paginated<LeadRow>;
    filters: { status: string | null; account_id: string | null; form_id: string | null };
    statuses: string[];
    selected: { lead: LeadRow; log: LeadLogRow[] } | null;
    canManage: boolean;
    targets: MappingTarget[];
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status ?? 'all');
const account = ref(props.filters.account_id ?? '');
const mapping = ref<LeadFormRow | null>(null);
const mappingOpen = ref(false);
const copied = ref(false);

const filtered = computed(() => status.value !== 'all' || account.value !== '');
const statusOptions = computed(() => [
    { value: 'all', label: t('leadAds.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`leadAds.status.${value}`) })),
]);
const accountOptions = computed(() => [{ value: '', label: t('leadAds.common.all') }, ...props.pages.map((page) => ({ value: page.account.id, label: page.account.name }))]);
const anyOn = computed(() => props.pages.some((page) => page.state === 'subscribed'));
const waitingForCrm = computed(() => props.leads.data.some((lead) => lead.status === 'waiting_for_crm') || props.pages.some((page) => page.counts.waiting > 0));

const columns = computed<Column[]>(() => [
    { key: 'when', label: t('leadAds.leads.when') },
    { key: 'status', label: t('leadAds.leads.status') },
    { key: 'page', label: t('leadAds.leads.page'), hideable: true },
    { key: 'form', label: t('leadAds.leads.form'), hideable: true },
    { key: 'crm', label: t('leadAds.leads.crm'), hideable: true },
    { key: 'actions', label: t('leadAds.leads.actions'), align: 'right', srOnly: true },
]);

function query(extra: Record<string, string | undefined> = {}): Record<string, string | undefined> {
    return {
        status: status.value === 'all' ? undefined : status.value,
        account_id: account.value || undefined,
        ...extra,
    };
}

function apply(): void {
    router.get(route('social.leadads.index'), query(), { preserveState: true, replace: true });
}

function clear(): void {
    status.value = 'all';
    account.value = '';
    apply();
}

function openLead(lead: LeadRow): void {
    router.get(route('social.leadads.index'), query({ lead: lead.id, page: undefined }), { preserveState: true, preserveScroll: true, replace: true });
}

function closeLead(open: boolean): void {
    if (!open) {
        router.get(route('social.leadads.index'), query(), { preserveState: true, preserveScroll: true, replace: true });
    }
}

function retry(lead: LeadRow): void {
    router.post(route('social.leadads.leads.retry', { lead: lead.id }), {}, { preserveScroll: true });
}

function map(form: LeadFormRow): void {
    mapping.value = form;
    mappingOpen.value = true;
}

function reason(lead: LeadRow): string | null {
    if (lead.reasonCode !== null && KNOWN_REASONS.includes(lead.reasonCode)) {
        return t(`leadAds.reasons.${lead.reasonCode}`);
    }

    return lead.reason;
}

async function copyUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.availability.webhookUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('leadAds.title')" :description="t('leadAds.description')" />

    <p v-if="availability.problem" class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="operator-problem">
        {{ t(`leadAds.problems.${availability.problem}`) }}
    </p>

    <p v-if="!availability.crmAvailable" class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="crm-missing">
        <strong class="font-medium">{{ t('leadAds.crm.title') }}</strong>
        {{ t('leadAds.crm.body') }}
    </p>
    <p v-else-if="waitingForCrm" class="mb-3 rounded-card bg-info-soft px-4 py-3 text-[0.8125rem] text-info" role="status">{{ t('leadAds.crm.catchingUp') }}</p>

    <div v-if="pages.length > 0" class="grid gap-3 xl:grid-cols-2">
        <LeadPageCard v-for="page in pages" :key="page.account.id" :page="page" :availability="availability" :can-manage="canManage" @map="map" />
    </div>
    <Card v-else>
        <EmptyState :title="t('leadAds.noPages.title')" :description="t('leadAds.noPages.body')" compact data-testid="no-pages">
            <template #icon><Share2 /></template>
            <Button size="sm" variant="primary" :href="route('social.accounts.index')">{{ t('leadAds.noPages.action') }}</Button>
        </EmptyState>
    </Card>

    <Card v-if="canManage && anyOn" class="mt-3" :title="t('leadAds.webhook.title')" :description="t('leadAds.webhook.body')">
        <div class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 flex-1 font-mono text-xs break-all text-ink-muted" data-testid="webhook-url">{{ availability.webhookUrl }}</code>
            <Button size="sm" @click="copyUrl">{{ copied ? t('leadAds.webhook.copied') : t('leadAds.webhook.copy') }}</Button>
        </div>
        <p class="mt-2 text-xs text-ink-subtle">{{ t('leadAds.webhook.verify') }}</p>
    </Card>

    <h2 class="mt-6 mb-3 text-base">{{ t('leadAds.leads.title') }}</h2>
    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Select v-model="status" :options="statusOptions" :label="t('leadAds.leads.status')" class="w-full sm:w-52" />
            <Select v-model="account" :options="accountOptions" :label="t('leadAds.leads.page')" class="w-full sm:w-52" />
            <Button type="submit" size="sm">{{ t('leadAds.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('leadAds.common.clearFilters') }}</Button>
        </form>

        <DataTable :columns="columns" :rows="leads.data" row-key="id" :caption="t('leadAds.leads.caption')" storage-key="social-lead-ads">
            <template #cell-when="{ row }">
                <span class="tabular text-ink">{{ format.date(row.receivedAt, 'datetime') }}</span>
                <span class="block text-xs text-ink-subtle">{{ t(`leadAds.leads.origin.${row.origin}`) }}</span>
            </template>
            <template #cell-status="{ row }">
                <Badge :tone="LEAD_TONES[row.status as LeadRow['status']]" dot>{{ t(`leadAds.status.${row.status}`) }}</Badge>
                <span v-if="reason(row) && row.status !== 'captured'" class="mt-1 block max-w-xs text-xs break-words text-ink-subtle">{{ reason(row) }}</span>
            </template>
            <template #cell-page="{ row }"><span class="text-ink-muted">{{ row.accountName ?? t('leadAds.common.dash') }}</span></template>
            <template #cell-form="{ row }"><span class="text-ink-muted">{{ row.formName ?? row.externalFormId ?? t('leadAds.common.dash') }}</span></template>
            <template #cell-crm="{ row }">
                <span v-if="row.crmLeadId" class="font-mono text-xs break-all text-ink-muted" :title="t('leadAds.leads.crmLead')">{{ row.crmLeadId }}</span>
                <span v-else class="text-ink-subtle">{{ t('leadAds.common.dash') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1.5">
                    <Button size="sm" variant="ghost" :data-testid="`details-${row.id}`" @click="openLead(row)">{{ t('leadAds.leads.details') }}</Button>
                    <Button v-if="canManage && row.canRetry" size="sm" variant="ghost" :data-testid="`retry-${row.id}`" @click="retry(row)">{{ t('leadAds.leads.retry') }}</Button>
                </div>
            </template>
            <template #empty>
                <EmptyState
                    :title="filtered ? t('leadAds.common.noMatches') : t('leadAds.leads.empty')"
                    :description="filtered ? t('leadAds.common.noMatchesBody') : t('leadAds.leads.emptyBody')"
                    compact
                >
                    <template #icon><ClipboardList /></template>
                </EmptyState>
            </template>
        </DataTable>
        <Pagination :meta="leads" />
    </Card>

    <p class="mt-3 text-xs text-ink-subtle">{{ t('leadAds.privacy') }}</p>

    <LeadMappingDialog v-model:open="mappingOpen" :form="mapping" :targets="targets" />
    <LeadLogDialog
        :open="selected !== null"
        :lead="selected?.lead ?? null"
        :log="selected?.log ?? []"
        :can-manage="canManage"
        @update:open="closeLead"
        @retry="retry"
    />
</template>
