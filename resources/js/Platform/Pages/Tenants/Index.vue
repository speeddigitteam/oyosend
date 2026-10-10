<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, ExternalLink, Plus, Search, SearchX, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SubscriptionStatusBadge from '@/Core/Components/Billing/SubscriptionStatusBadge.vue';
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
import TenantStatusBadge, { type TenantStatus } from '@/Platform/Components/TenantStatusBadge.vue';
import WorkspaceName from '@/Platform/Components/WorkspaceName.vue';

defineOptions({ layout: PlatformLayout });

interface TenantRow {
    id: string;
    name: string;
    slug: string;
    /** Absolute URL of the workspace. */
    url: string;
    status: TenantStatus;
    locale: string;
    currency: string;
    timezone: string;
    members: number;
    /** Name of the plan the workspace is on; null when it has no subscription. */
    plan: string | null;
    /** A SubscriptionStatus value; null when it has no subscription. */
    subscriptionStatus: string | null;
    createdAt: string | null;
}

interface Filters {
    search: string;
    status: TenantStatus | '';
}

const props = defineProps<{
    tenants: Paginated<TenantRow>;
    /** The filters the server applied to this response. */
    filters: Filters;
    /** Whether this operator may create workspaces. */
    canManage: boolean;
}>();

const page = usePage();
const { t } = useI18n();
const format = useFormat();

const STATUSES: TenantStatus[] = ['active', 'pending', 'suspended'];
const SEARCH_DELAY = 300;

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
    router.get(route('platform.tenants.index'), query, {
        only: ['tenants', 'filters'],
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

/** Not one workspace on the platform - as opposed to none that match. */
const untouched = computed(() => props.tenants.total === 0 && !filtered.value);

const count = computed(() => {
    const total = props.tenants.total;
    const key = filtered.value ? 'platform.tenants.found' : total === 1 ? 'platform.tenants.totalOne' : 'platform.tenants.total';

    return t(key, { count: format.number(total) });
});

const statusOptions = computed<SelectOption[]>(() => [
    { value: '', label: t('platform.tenants.allStatuses') },
    ...STATUSES.map((value) => ({ value, label: t(`platform.tenants.status.${value}`) })),
]);

// Language, currency and timezone start out visible only where the table has room for them without scrolling.
const roomy = typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 90rem)').matches;

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('platform.tenants.columns.name'), class: 'min-w-48' },
    { key: 'url', label: t('platform.tenants.columns.address'), class: 'whitespace-nowrap' },
    { key: 'status', label: t('platform.tenants.columns.status'), class: 'whitespace-nowrap' },
    { key: 'plan', label: t('platform.tenants.columns.plan'), class: 'whitespace-nowrap' },
    { key: 'members', label: t('platform.tenants.columns.members'), align: 'right', class: 'whitespace-nowrap' },
    {
        key: 'locale',
        label: t('platform.tenants.columns.locale'),
        class: 'whitespace-nowrap',
        hideable: true,
        hiddenByDefault: !roomy,
    },
    {
        key: 'currency',
        label: t('platform.tenants.columns.currency'),
        class: 'whitespace-nowrap',
        hideable: true,
        hiddenByDefault: !roomy,
    },
    {
        key: 'timezone',
        label: t('platform.tenants.columns.timezone'),
        class: 'whitespace-nowrap',
        hideable: true,
        hiddenByDefault: !roomy,
    },
    { key: 'createdAt', label: t('platform.tenants.columns.created'), class: 'whitespace-nowrap' },
]);

/** The address as people type it: host, and port when there is one. */
function host(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.platform')"
        :title="t('shell.nav.tenants')"
        :description="t('platform.tenants.description')"
    >
        <template v-if="canManage" #actions>
            <Button variant="primary" :href="route('platform.tenants.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('platform.tenants.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card v-if="untouched">
        <EmptyState :title="t('platform.tenants.empty.title')" :description="t('platform.tenants.empty.body')">
            <template #icon><Building2 /></template>
            <template v-if="canManage" #default>
                <Button size="sm" :href="route('platform.tenants.create')">{{ t('platform.tenants.create') }}</Button>
            </template>
        </EmptyState>
    </Card>

    <Card v-else flush :aria-busy="loading || undefined">
        <DataTable
            :columns="columns"
            :rows="tenants.data"
            row-key="id"
            :caption="t('platform.tenants.caption')"
            storage-key="platform-tenants"
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
                            :aria-label="t('platform.tenants.search')"
                            :placeholder="t('platform.tenants.searchPlaceholder')"
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
                        <Select v-model="status" :options="statusOptions" name="status" :aria-label="t('platform.tenants.statusFilter')" />
                    </div>

                    <p class="eyebrow tabular px-1" role="status">{{ count }}</p>
                </form>
            </template>

            <!-- The name is the way in: every row leads to the workspace's own page. -->
            <template #cell-name="{ row }">
                <Link
                    :href="route('platform.tenants.show', { workspace: row.id })"
                    class="inline-flex rounded-chip underline-offset-4 hover:underline"
                >
                    <WorkspaceName :name="row.name" />
                </Link>
            </template>

            <template #cell-url="{ row }">
                <a
                    :href="row.url"
                    target="_blank"
                    rel="noopener"
                    class="group/address inline-flex items-center gap-1.5 rounded-chip font-mono text-[0.8125rem] text-ink-muted transition-colors hover:text-brand-text"
                >
                    <span class="underline-offset-4 group-hover/address:underline">{{ host(row.url) }}</span>
                    <ExternalLink class="size-3.5 shrink-0 text-ink-subtle group-hover/address:text-brand-text" aria-hidden="true" />
                    <span class="sr-only">({{ t('platform.tenants.opensNewTab') }})</span>
                </a>
            </template>

            <template #cell-status="{ row }">
                <TenantStatusBadge :status="row.status" />
            </template>

            <template #cell-plan="{ row }">
                <span v-if="row.plan" class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="text-ink">{{ row.plan }}</span>
                    <SubscriptionStatusBadge :status="row.subscriptionStatus" />
                </span>
                <span v-else class="text-ink-subtle">{{ t('billing.terms.subscriptionStatus.none') }}</span>
            </template>

            <template #cell-members="{ row }">
                <span class="tabular">{{ format.number(row.members) }}</span>
            </template>

            <template #cell-locale="{ row }">
                <span class="font-mono text-[0.8125rem] text-ink-muted" :title="page.props.app.locales[row.locale]">{{ row.locale }}</span>
            </template>

            <template #cell-currency="{ row }">
                <span class="font-mono text-[0.8125rem] text-ink-muted">{{ row.currency }}</span>
            </template>

            <template #cell-timezone="{ row }">
                <span class="font-mono text-[0.8125rem] text-ink-muted">{{ row.timezone }}</span>
            </template>

            <template #cell-createdAt="{ row }">
                <time
                    v-if="row.createdAt"
                    :datetime="row.createdAt"
                    :title="format.date(row.createdAt, 'datetime')"
                    class="tabular text-ink-muted"
                >
                    {{ format.date(row.createdAt) }}
                </time>
                <span v-else class="text-ink-subtle">—</span>
            </template>

            <template #empty>
                <EmptyState
                    :title="filtered ? t('common.table.emptyFiltered') : t('common.table.empty')"
                    :description="filtered ? t('platform.tenants.noMatch.body') : undefined"
                >
                    <template #icon><SearchX /></template>
                    <template v-if="filtered" #default>
                        <Button size="sm" @click="clearFilters">{{ t('platform.tenants.noMatch.clear') }}</Button>
                    </template>
                </EmptyState>
            </template>
        </DataTable>

        <Pagination :meta="tenants" />
    </Card>
</template>
