<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ReceiptText, Search, SearchX, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceStatusBadge from '@/Core/Components/Billing/InvoiceStatusBadge.vue';
import { useBillingTerms } from '@/Core/Components/Billing/terms';
import type { InvoiceStatus, InvoiceSummary } from '@/Core/Components/Billing/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';

defineOptions({ layout: PlatformLayout });

interface InvoiceRow extends InvoiceSummary {
    /** Null when the workspace has been deleted since. */
    workspace: { id: string; name: string; slug: string } | null;
}

/** A sum of invoices in one currency. Currencies are never added together. */
interface CurrencyTotal {
    currency: string;
    amount: number;
    count: number;
}

interface Filters {
    search: string;
    status: InvoiceStatus | '';
}

const props = defineProps<{
    invoices: Paginated<InvoiceRow>;
    /** The filters the server applied to this response. */
    filters: Filters;
    /** What is owed and what came in this month, per currency, whatever the filters. */
    totals: { open: CurrencyTotal[]; paidThisMonth: CurrencyTotal[] };
    /** Used on the invoice's own page, where a payment is recorded. */
    offlineMethods: string[];
    canManage: boolean;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const terms = useBillingTerms();

const STATUSES: InvoiceStatus[] = ['open', 'paid', 'void'];
const SEARCH_DELAY = 300;

// A link nobody may follow is not shown; the server checks the ability again.
const canOpenWorkspaces = computed(() => (page.props.auth?.abilities ?? []).includes('platform.tenants.view'));

/*
 * The controls start from what the server applied and are not written back
 * from later responses: an answer to an earlier keystroke must never replace
 * what has been typed since.
 */
const search = ref(props.filters.search);
const status = ref<string>(props.filters.status);
const searchInput = ref<InstanceType<typeof Input> | null>(null);
const loading = ref(false);

// The filters of the latest request, answered or not; guards against asking for the same list twice.
let requested: { search: string; status: string } = { search: props.filters.search, status: props.filters.status };
let timer: ReturnType<typeof setTimeout> | undefined;
let latestVisit = 0;

function apply(): void {
    clearTimeout(timer);

    const next = { search: search.value.trim(), status: status.value };

    if (next.search === requested.search && next.status === requested.status) {
        return;
    }

    requested = next;

    const visit = ++latestVisit;
    const query: Record<string, string> = {};

    if (next.search !== '') {
        query.search = next.search;
    }

    if (next.status !== '') {
        query.status = next.status;
    }

    // No `page` in the query: a changed filter always starts again from the first page.
    router.get(route('platform.invoices.index'), query, {
        only: ['invoices', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => {
            // A newer visit cancels this one; only the latest may settle things.
            if (visit !== latestVisit) {
                return;
            }

            loading.value = false;

            // What the server applied - still the old filters if the request failed, so it can be sent again.
            requested = { search: props.filters.search, status: props.filters.status };
        },
    });
}

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, SEARCH_DELAY);
});

watch(status, apply);

onBeforeUnmount(() => clearTimeout(timer));

function clearSearch(): void {
    search.value = '';
    apply();
    searchInput.value?.focus();
}

function clearFilters(): void {
    search.value = '';
    status.value = '';
    apply();
    searchInput.value?.focus();
}

const filtered = computed(() => props.filters.search !== '' || props.filters.status !== '');

/** Not one invoice on the platform - as opposed to none that match. */
const untouched = computed(() => props.invoices.total === 0 && !filtered.value);

const count = computed(() => {
    const total = props.invoices.total;
    const key = filtered.value
        ? 'platformBilling.invoices.found'
        : total === 1
          ? 'platformBilling.invoices.total.one'
          : 'platformBilling.invoices.total.other';

    return t(key, { count: format.number(total) });
});

const statusOptions = computed<SelectOption[]>(() => [
    { value: '', label: t('platformBilling.invoices.allStatuses') },
    ...STATUSES.map((value) => ({ value, label: terms.invoiceStatus(value) })),
]);

const totalGroups = computed(() => [
    {
        key: 'open',
        label: t('platformBilling.invoices.totals.open'),
        rows: props.totals.open,
        none: t('platformBilling.invoices.totals.openNone'),
        counted: (invoices: number): string =>
            t(invoices === 1 ? 'platformBilling.invoices.totals.openCount.one' : 'platformBilling.invoices.totals.openCount.other', {
                count: format.number(invoices),
            }),
        // The one figure on this page that asks for something to be done.
        accent: props.totals.open.length > 0,
    },
    {
        key: 'paid',
        label: t('platformBilling.invoices.totals.paid'),
        rows: props.totals.paidThisMonth,
        none: t('platformBilling.invoices.totals.paidNone'),
        counted: (invoices: number): string =>
            t(invoices === 1 ? 'platformBilling.invoices.totals.paidCount.one' : 'platformBilling.invoices.totals.paidCount.other', {
                count: format.number(invoices),
            }),
        accent: false,
    },
]);

const columns = computed<Column[]>(() => [
    { key: 'number', label: t('platformBilling.invoices.columns.number'), class: 'whitespace-nowrap' },
    { key: 'workspace', label: t('platformBilling.invoices.columns.workspace'), class: 'min-w-44' },
    { key: 'kind', label: t('platformBilling.invoices.columns.kind'), class: 'whitespace-nowrap', hideable: true },
    { key: 'issuedAt', label: t('platformBilling.invoices.columns.issued'), class: 'whitespace-nowrap' },
    { key: 'dueAt', label: t('platformBilling.invoices.columns.due'), class: 'whitespace-nowrap', hideable: true },
    { key: 'totalAmount', label: t('platformBilling.invoices.columns.total'), align: 'right', class: 'whitespace-nowrap' },
    { key: 'status', label: t('platformBilling.invoices.columns.status'), class: 'whitespace-nowrap' },
]);
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.platform')"
        :title="t('platformBilling.invoices.title')"
        :description="t('platformBilling.invoices.description')"
    />

    <div class="flex flex-col gap-3">
        <div class="grid gap-3 sm:grid-cols-2">
            <section v-for="group in totalGroups" :key="group.key" class="glass relative rounded-card px-5 py-4">
                <span
                    v-if="group.accent"
                    class="absolute top-4 right-4 size-2 rounded-full bg-accent shadow-[0_0_0_4px_var(--accent-soft)]"
                    aria-hidden="true"
                />
                <h2 class="eyebrow">{{ group.label }}</h2>

                <p v-if="group.rows.length === 0" class="mt-3 text-ink-muted">{{ group.none }}</p>

                <ul v-else class="mt-3 flex flex-col gap-2.5">
                    <li v-for="row in group.rows" :key="row.currency" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="figure text-[1.75rem] text-ink">{{ format.money(row.amount, row.currency) }}</span>
                        <span class="tabular text-[0.8125rem] text-ink-subtle">{{ group.counted(row.count) }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <Card v-if="untouched">
            <EmptyState :title="t('platformBilling.invoices.empty.title')" :description="t('platformBilling.invoices.empty.body')">
                <template #icon><ReceiptText /></template>
            </EmptyState>
        </Card>

        <Card v-else flush :aria-busy="loading || undefined">
            <DataTable
                :columns="columns"
                :rows="invoices.data"
                row-key="id"
                :caption="t('platformBilling.invoices.caption')"
                storage-key="platform-invoices"
                class="pt-4"
            >
                <template #toolbar>
                    <form role="search" class="flex w-full flex-wrap items-center gap-2" @submit.prevent="apply">
                        <div class="w-full sm:w-72">
                            <Input
                                ref="searchInput"
                                v-model="search"
                                name="search"
                                role="searchbox"
                                inputmode="search"
                                enterkeyhint="search"
                                autocomplete="off"
                                autocapitalize="none"
                                spellcheck="false"
                                maxlength="100"
                                :aria-label="t('platformBilling.invoices.search')"
                                :placeholder="t('platformBilling.invoices.searchPlaceholder')"
                            >
                                <template #leading><Search class="size-4" aria-hidden="true" /></template>
                                <template v-if="loading || search !== ''" #trailing>
                                    <Spinner v-if="loading" class="mr-2 text-ink-subtle" />
                                    <Button v-else size="sm" variant="ghost" icon @click="clearSearch">
                                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                                        {{ t('common.actions.clear') }}
                                    </Button>
                                </template>
                            </Input>
                        </div>

                        <div class="w-full sm:w-44">
                            <Select
                                v-model="status"
                                :options="statusOptions"
                                name="status"
                                :aria-label="t('platformBilling.invoices.statusFilter')"
                            />
                        </div>

                        <p class="eyebrow tabular px-1" role="status">{{ count }}</p>
                    </form>
                </template>

                <template #cell-number="{ row }">
                    <Link
                        :href="route('platform.invoices.show', { invoice: row.id })"
                        class="rounded-chip font-mono text-[0.8125rem] font-medium text-ink underline-offset-4 hover:text-brand-text hover:underline"
                        :aria-label="t('platformBilling.invoices.open', { number: row.number })"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell-workspace="{ row }">
                    <template v-if="row.workspace">
                        <Link
                            v-if="canOpenWorkspaces"
                            :href="route('platform.tenants.show', { workspace: row.workspace.id })"
                            class="rounded-chip font-medium text-ink underline-offset-4 hover:text-brand-text hover:underline"
                        >
                            {{ row.workspace.name }}
                        </Link>
                        <span v-else class="font-medium text-ink">{{ row.workspace.name }}</span>
                        <span class="block font-mono text-xs text-ink-subtle">{{ row.workspace.slug }}</span>
                    </template>
                    <span v-else class="text-ink-subtle">{{ t('platformBilling.invoices.workspaceGone') }}</span>
                </template>

                <template #cell-kind="{ row }">
                    <span class="text-ink-muted">{{ terms.invoiceKind(row.kind) }}</span>
                </template>

                <template #cell-issuedAt="{ row }">
                    <time :datetime="row.issuedAt" :title="format.date(row.issuedAt, 'datetime')" class="tabular text-ink-muted">
                        {{ format.date(row.issuedAt) }}
                    </time>
                </template>

                <template #cell-dueAt="{ row }">
                    <time
                        v-if="row.dueAt"
                        :datetime="row.dueAt"
                        :class="['tabular', row.overdue ? 'font-medium text-danger' : 'text-ink-muted']"
                    >
                        {{ format.date(row.dueAt) }}
                    </time>
                    <span v-else class="text-ink-subtle">—</span>
                </template>

                <template #cell-totalAmount="{ row }">
                    <span class="tabular font-medium text-ink">{{ format.money(row.totalAmount, row.currency) }}</span>
                </template>

                <template #cell-status="{ row }">
                    <InvoiceStatusBadge :status="row.status" :overdue="row.overdue" />
                </template>

                <template #empty>
                    <EmptyState
                        :title="filtered ? t('common.table.emptyFiltered') : t('common.table.empty')"
                        :description="filtered ? t('platformBilling.invoices.noMatch.body') : undefined"
                    >
                        <template #icon><SearchX /></template>
                        <template v-if="filtered" #default>
                            <Button size="sm" @click="clearFilters">{{ t('platformBilling.invoices.noMatch.clear') }}</Button>
                        </template>
                    </EmptyState>
                </template>
            </DataTable>

            <Pagination :meta="invoices" />
        </Card>
    </div>
</template>
