<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, Info, Lock, Plus, SearchX } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import CompanyFormDialog from '@/Core/Components/Contacts/CompanyFormDialog.vue';
import DetailsDialog from '@/Core/Components/Contacts/DetailsDialog.vue';
import DirectorySearch from '@/Core/Components/Contacts/DirectorySearch.vue';
import DirectoryTabs from '@/Core/Components/Contacts/DirectoryTabs.vue';
import { useDirectoryFilters } from '@/Core/Components/Contacts/filters';
import { ADD_BUTTON, actionsSelector, mailHref, peopleOfCompanyUrl, telHref } from '@/Core/Components/Contacts/links';
import RowActions from '@/Core/Components/Contacts/RowActions.vue';
import { screenAtLeast } from '@/Core/Components/Contacts/screen';
import type { CompanyRow, DetailItem, DirectoryOption } from '@/Core/Components/Contacts/types';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { countryName } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    /** By name; 25 to a page. Only the companies this person's permission reaches. */
    companies: Paginated<CompanyRow>;
    /** The filter the server applied to this response. */
    filters: { search: string };
    /** Members a company can be handed to. Empty unless the person manages every company. */
    owners: DirectoryOption[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

const workspace = computed(() => page.props.tenant?.name ?? '');

/*
 * Search is applied on the server.
 */
const searchBox = ref<InstanceType<typeof DirectorySearch> | null>(null);

const { search, loading, apply } = useDirectoryFilters({
    url: () => route('core.contacts.companies.index'),
    applied: () => ({ search: props.filters.search }),
    list: () => props.companies,
    only: ['companies', 'filters'],
});

function clearSearch(): void {
    search.value = '';
    apply();
    searchBox.value?.focus();
}

const searched = computed(() => props.filters.search !== '');

/** Not one company this person can see - as opposed to none that match. */
const untouched = computed(() => props.companies.total === 0 && !searched.value);

// A very long query would push the empty state out of its card.
const shownQuery = computed(() => {
    const query = props.filters.search;

    return query.length > 40 ? `${query.slice(0, 40)}…` : query;
});

const count = computed(() => {
    const total = props.companies.total;
    const key = searched.value ? 'contacts.list.found' : 'contacts.companies.count';

    return t(`${key}.${total === 1 ? 'one' : 'other'}`, { count: format.number(total) });
});

/** Says why some rows, or all of them, cannot be changed. */
const accessNote = computed(() => {
    if (!props.canCreate) {
        return t('contacts.companies.access.viewOnly');
    }

    // No one to hand a company to: the person manages some companies, not all of them.
    return props.owners.length === 0 ? t('contacts.companies.access.partial') : null;
});

function peopleCount(company: CompanyRow): string {
    return t(company.contacts === 1 ? 'contacts.companies.people.one' : 'contacts.companies.people.other', {
        count: format.number(company.contacts),
    });
}

function location(company: CompanyRow): string {
    return [company.city, countryName(company.country, page.props.app.locale)].filter((part) => part).join(', ');
}

/*
 * Columns start out visible only where they fit beside the sidebar without
 * scrolling sideways (see `screenAtLeast`). On a phone that is the name alone,
 * with the essentials stacked under it. Every column can be switched from the
 * Columns menu.
 */
const tablet = screenAtLeast('48rem');
const desktop = screenAtLeast('72rem');
const roomy = screenAtLeast('90rem');

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('contacts.fields.name') },
    { key: 'contacts', label: t('contacts.fields.people'), align: 'right', class: 'w-px whitespace-nowrap', hideable: true, hiddenByDefault: !tablet },
    { key: 'email', label: t('contacts.fields.email'), hideable: true, hiddenByDefault: !tablet },
    { key: 'phone', label: t('contacts.fields.phone'), class: 'whitespace-nowrap', hideable: true, hiddenByDefault: !desktop },
    { key: 'location', label: t('contacts.fields.location'), hideable: true, hiddenByDefault: !roomy },
    { key: 'owner', label: t('contacts.fields.owner'), hideable: true, hiddenByDefault: !roomy },
    { key: 'actions', label: t('contacts.list.actions'), srOnly: true, align: 'right', class: 'w-px' },
]);

const LINK = 'rounded-chip underline-offset-4 transition-colors hover:text-brand-text hover:underline';

/*
 * Details: everything recorded about one company, read only.
 */
const detailsTarget = ref<CompanyRow | null>(null);
const detailsOpen = ref(false);

function openDetails(company: CompanyRow): void {
    detailsTarget.value = company;
    detailsOpen.value = true;
}

useFocusReturn(detailsOpen, () => actionsSelector(detailsTarget.value?.id));

const details = computed<DetailItem[]>(() => {
    const company = detailsTarget.value;

    if (!company) {
        return [];
    }

    return [
        { key: 'industry', label: t('contacts.fields.industry'), value: company.industry },
        { key: 'taxId', label: t('contacts.fields.taxId'), value: company.taxId },
        { key: 'email', label: t('contacts.fields.email'), value: company.email, href: mailHref(company.email), link: 'contact' },
        { key: 'phone', label: t('contacts.fields.phone'), value: company.phone, href: telHref(company.phone), link: 'contact' },
        { key: 'website', label: t('contacts.fields.website'), value: company.website, href: company.website ?? undefined, link: 'external' },
        { key: 'people', label: t('contacts.fields.people'), value: peopleCount(company), href: peopleOfCompanyUrl(company.id), link: 'page' },
        { key: 'address', label: t('contacts.fields.address'), value: company.address, multiline: true },
        { key: 'city', label: t('contacts.fields.city'), value: company.city },
        { key: 'country', label: t('contacts.fields.country'), value: countryName(company.country, page.props.app.locale) },
        { key: 'notes', label: t('contacts.fields.notes'), value: company.notes, multiline: true },
        { key: 'owner', label: t('contacts.fields.owner'), value: company.owner?.name ?? t('contacts.list.noOwner') },
        { key: 'added', label: t('contacts.fields.added'), value: format.date(company.createdAt) },
    ];
});

/*
 * Adding and changing share one form.
 */
const formTarget = ref<CompanyRow | null>(null);
const formOpen = ref(false);

function openCreate(): void {
    formTarget.value = null;
    formOpen.value = true;
}

function openEdit(company: CompanyRow): void {
    formTarget.value = company;

    if (detailsOpen.value) {
        // One dialog at a time: let the details close before the form takes the focus.
        detailsOpen.value = false;
        void nextTick(() => {
            formOpen.value = true;
        });
    } else {
        formOpen.value = true;
    }
}

useFocusReturn(formOpen, () => (formTarget.value ? actionsSelector(formTarget.value.id) : ADD_BUTTON));

/*
 * Deleting takes a company out of the directory for everyone, so it is
 * confirmed. Its people stay, without a company - the dialog says so.
 */
const deleteTarget = ref<CompanyRow | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function askDelete(company: CompanyRow): void {
    deleteTarget.value = company;
    deleteError.value = null;
    deleteOpen.value = true;
}

useFocusReturn(deleteOpen, () => actionsSelector(deleteTarget.value?.id));

function destroy(): void {
    if (!deleteTarget.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = null;

    router.delete(route('core.contacts.companies.destroy', { company: deleteTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
        onError: (errors) => {
            deleteError.value = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.workspace')"
        :title="t('contacts.companies.title')"
        :description="t('contacts.companies.description', { workspace })"
    >
        <template v-if="canCreate" #actions>
            <Button variant="primary" data-directory-add @click="openCreate">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.companies.add') }}
            </Button>
        </template>
    </PageHeader>

    <DirectoryTabs current="companies" class="mb-3" />

    <Card v-if="untouched">
        <EmptyState
            :title="t('contacts.companies.empty.title')"
            :description="canCreate ? t('contacts.companies.empty.body') : t('contacts.companies.empty.bodyViewOnly')"
        >
            <template #icon><Building2 /></template>
            <template v-if="canCreate" #default>
                <Button size="sm" @click="openCreate">{{ t('contacts.companies.add') }}</Button>
            </template>
        </EmptyState>
    </Card>

    <Card v-else flush :aria-busy="loading || undefined">
        <DataTable :columns="columns" :rows="companies.data" row-key="id" :caption="t('contacts.companies.caption')" storage-key="companies" class="pt-4">
            <template #toolbar>
                <DirectorySearch ref="searchBox" v-model="search" :label="t('contacts.companies.search')" :loading="loading" @submit="apply()" @clear="apply()" />
                <p class="eyebrow tabular px-1" role="status">{{ count }}</p>
            </template>

            <template #cell-name="{ row }">
                <!-- Asks the column for 11rem and then fills whatever it gets, so long names truncate instead of widening the table. -->
                <div class="w-44 min-w-full">
                    <p class="truncate text-sm font-medium text-ink" :title="row.name">{{ row.name }}</p>

                    <!-- Small screens: the other columns start hidden, so the essentials sit here. -->
                    <ul class="mt-0.5 flex flex-col gap-0.5 text-[0.8125rem] text-ink-muted md:hidden">
                        <li class="truncate">
                            <Link :href="peopleOfCompanyUrl(row.id)" :class="[LINK, 'tabular']">{{ peopleCount(row) }}</Link>
                        </li>
                        <li v-if="row.email" class="truncate">
                            <a :href="mailHref(row.email)" :class="LINK">{{ row.email }}</a>
                        </li>
                        <li v-if="row.phone" class="truncate">
                            <a v-if="telHref(row.phone)" :href="telHref(row.phone)" :class="LINK">{{ row.phone }}</a>
                            <template v-else>{{ row.phone }}</template>
                        </li>
                    </ul>
                </div>
            </template>

            <template #cell-contacts="{ row }">
                <Link
                    :href="peopleOfCompanyUrl(row.id)"
                    :aria-label="t('contacts.companies.showPeople', { company: row.name, people: peopleCount(row) })"
                    :title="t('contacts.companies.showPeople', { company: row.name, people: peopleCount(row) })"
                    :class="[LINK, 'tabular', row.contacts > 0 ? 'text-ink' : 'text-ink-subtle']"
                >
                    {{ format.number(row.contacts) }}
                </Link>
            </template>

            <template #cell-email="{ row }">
                <a v-if="row.email" :href="mailHref(row.email)" :title="row.email" :class="[LINK, 'block w-40 min-w-full truncate text-ink-muted']">
                    {{ row.email }}
                </a>
                <span v-else class="text-ink-subtle" aria-hidden="true">—</span>
            </template>

            <template #cell-phone="{ row }">
                <a v-if="telHref(row.phone)" :href="telHref(row.phone)" :class="[LINK, 'text-ink-muted']">{{ row.phone }}</a>
                <span v-else-if="row.phone" class="text-ink-muted">{{ row.phone }}</span>
                <span v-else class="text-ink-subtle" aria-hidden="true">—</span>
            </template>

            <template #cell-location="{ row }">
                <span v-if="location(row)" class="block w-32 min-w-full truncate text-ink-muted" :title="location(row)">{{ location(row) }}</span>
                <span v-else class="text-ink-subtle" aria-hidden="true">—</span>
            </template>

            <template #cell-owner="{ row }">
                <span v-if="row.owner" class="block w-28 min-w-full truncate text-ink-muted" :title="row.owner.name">{{ row.owner.name }}</span>
                <span v-else class="whitespace-nowrap text-ink-subtle">{{ t('contacts.list.noOwner') }}</span>
            </template>

            <template #cell-actions="{ row }">
                <RowActions
                    :record-id="row.id"
                    :name="row.name"
                    :editable="row.editable"
                    :deletable="row.deletable"
                    @view="openDetails(row)"
                    @edit="openEdit(row)"
                    @delete="askDelete(row)"
                />
            </template>

            <template #empty>
                <EmptyState v-if="searched" :title="t('contacts.list.noMatch.title', { query: shownQuery })" :description="t('contacts.list.noMatch.body')">
                    <template #icon><SearchX /></template>
                    <Button size="sm" @click="clearSearch">{{ t('contacts.list.clearSearch') }}</Button>
                </EmptyState>
                <EmptyState v-else :title="t('common.table.empty')">
                    <template #icon><Building2 /></template>
                </EmptyState>
            </template>
        </DataTable>

        <Pagination :meta="companies" />

        <template v-if="accessNote" #footer>
            <p class="flex items-start gap-2 text-[0.8125rem] text-ink-subtle">
                <Lock v-if="!canCreate" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                <Info v-else class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {{ accessNote }}
            </p>
        </template>
    </Card>

    <DetailsDialog
        v-model:open="detailsOpen"
        :title="detailsTarget?.name ?? ''"
        :description="t('contacts.companies.details')"
        :items="details"
        :editable="detailsTarget?.editable"
        @edit="detailsTarget && openEdit(detailsTarget)"
    />

    <CompanyFormDialog v-if="canCreate" v-model:open="formOpen" :company="formTarget" :owners="owners" />

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('contacts.companies.delete.title', { name: deleteTarget?.name })"
        :description="t('contacts.companies.delete.body', { name: deleteTarget?.name, workspace })"
        :confirm-label="t('contacts.companies.delete.confirm')"
        :loading="deleting"
        @confirm="destroy"
    >
        <p class="text-sm text-ink-muted">{{ t('contacts.companies.delete.peopleStay') }}</p>
        <p v-if="deleteError" class="mt-3 text-[0.8125rem] text-danger" role="alert">{{ deleteError }}</p>
    </ConfirmDialog>
</template>
