<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, Info, LayoutGrid, List, Lock, Plus, UsersRound, X } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Avatar from '@/Core/Components/Avatar.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import AddressManager from '@/Core/Components/Contacts/AddressManager.vue';
import ContactAddresses from '@/Core/Components/Contacts/ContactAddresses.vue';
import ContactCard from '@/Core/Components/Contacts/ContactCard.vue';
import ContactMergeDialog from '@/Core/Components/Contacts/ContactMergeDialog.vue';
import ContactFormDialog from '@/Core/Components/Contacts/ContactFormDialog.vue';
import DetailsDialog from '@/Core/Components/Contacts/DetailsDialog.vue';
import DirectorySearch from '@/Core/Components/Contacts/DirectorySearch.vue';
import DirectoryTabs from '@/Core/Components/Contacts/DirectoryTabs.vue';
import { useDirectoryFilters } from '@/Core/Components/Contacts/filters';
import { contactDetails } from '@/Core/Components/Contacts/details';
import { ADD_BUTTON, actionsSelector, mailHref, peopleOfCompanyUrl, telHref } from '@/Core/Components/Contacts/links';
import PeopleEmpty from '@/Core/Components/Contacts/PeopleEmpty.vue';
import RowActions from '@/Core/Components/Contacts/RowActions.vue';
import { screenAtLeast } from '@/Core/Components/Contacts/screen';
import type { AddressParent, ContactRow, DetailItem, DirectoryOption } from '@/Core/Components/Contacts/types';
import { type DirectoryView, initialsOf, SORT_COLUMNS, sortKeyOf, storedView, storeView } from '@/Core/Components/Contacts/view';
import DataTable, { type Column, type SortState } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { countryName } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    /** By first name, then last name; 25 to a page. Only the contacts this person's permission reaches. */
    contacts: Paginated<ContactRow>;
    /** The filters the server applied to this response. */
    filters: { search: string; company: DirectoryOption | null; sort: string; direction: 'asc' | 'desc' };
    /** Companies a contact can be put in. Empty unless the person may add or change contacts. */
    companies: DirectoryOption[];
    /** Members a contact can be handed to. Empty unless the person manages every contact. */
    owners: DirectoryOption[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

const workspace = computed(() => page.props.tenant?.name ?? '');

/*
 * Search and the company filter are applied on the server.
 */
const searchBox = ref<InstanceType<typeof DirectorySearch> | null>(null);

const { search, loading, apply } = useDirectoryFilters({
    url: () => route('core.contacts.index'),
    applied: () => ({ search: props.filters.search, company: props.filters.company?.id ?? '', sort: props.filters.sort, direction: props.filters.direction }),
    list: () => props.contacts,
    only: ['contacts', 'filters'],
});

function clearCompany(): void {
    apply({ company: '' });
    // The button that was pressed goes away with the filter.
    searchBox.value?.focus();
}

function clearFilters(): void {
    search.value = '';
    apply({ company: '' });
    searchBox.value?.focus();
}

const searched = computed(() => props.filters.search !== '');
const filtered = computed(() => searched.value || props.filters.company !== null);

/** Not one contact this person can see - as opposed to none that match. */
const untouched = computed(() => props.contacts.total === 0 && !filtered.value);

// A very long query would push the empty state out of its card.
const shownQuery = computed(() => {
    const query = props.filters.search;

    return query.length > 40 ? `${query.slice(0, 40)}…` : query;
});

const count = computed(() => {
    const total = props.contacts.total;
    const key = filtered.value ? 'contacts.list.found' : 'contacts.people.count';

    return t(`${key}.${total === 1 ? 'one' : 'other'}`, { count: format.number(total) });
});

/*
 * List or cards (Odoo's list and kanban views), remembered in this browser.
 */
const view = ref<DirectoryView>(storedView());
watch(view, storeView);

const viewOptions = computed(() => [
    { value: 'list' as const, label: t('contacts.views.list'), icon: List },
    { value: 'grid' as const, label: t('contacts.views.grid'), icon: LayoutGrid },
]);

/*
 * Sorting happens on the server, so it holds across pages. The list sorts from its column headers, the grid from
 * a select with every choice the headers can make, so switching views never leaves the select blank.
 */
const sortState = computed<SortState>(() => ({ key: SORT_COLUMNS[props.filters.sort] ?? 'name', direction: props.filters.direction }));

function sortBy(state: SortState | null): void {
    const key = state ? sortKeyOf(state.key) : null;

    apply(key ? { sort: key, direction: state?.direction ?? 'asc' } : { sort: 'name', direction: 'asc' });
}

const sortOptions = computed(() => [
    { value: 'name:asc', label: t('contacts.sort.nameAsc') },
    { value: 'name:desc', label: t('contacts.sort.nameDesc') },
    { value: 'company:asc', label: t('contacts.sort.company') },
    { value: 'company:desc', label: t('contacts.sort.companyDesc') },
    { value: 'email:asc', label: t('contacts.sort.email') },
    { value: 'email:desc', label: t('contacts.sort.emailDesc') },
    { value: 'city:asc', label: t('contacts.sort.city') },
    { value: 'city:desc', label: t('contacts.sort.cityDesc') },
    { value: 'created:desc', label: t('contacts.sort.newest') },
    { value: 'created:asc', label: t('contacts.sort.oldest') },
]);

const gridSort = computed({
    get: () => `${props.filters.sort}:${props.filters.direction}`,
    set: (value: string | number | null) => {
        const [sort, direction] = String(value).split(':');
        apply({ sort: sort ?? 'name', direction: direction === 'desc' ? 'desc' : 'asc' });
    },
});

const countryLabel = (code: string | null): string | null => countryName(code, page.props.app.locale) || null;

/** Says why some rows, or all of them, cannot be changed. */
const accessNote = computed(() => {
    if (!props.canCreate) {
        return t('contacts.people.access.viewOnly');
    }

    // No one to hand a contact to: the person manages some contacts, not all of them.
    return props.owners.length === 0 ? t('contacts.people.access.partial') : null;
});

/*
 * Columns start out visible only where they fit beside the sidebar without
 * scrolling sideways (see `screenAtLeast`). On a phone that is the name alone,
 * with the ways to reach someone stacked under it. Every column can be
 * switched from the Columns menu.
 */
const tablet = screenAtLeast('48rem');
const desktop = screenAtLeast('72rem');
const roomy = screenAtLeast('90rem');

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('contacts.fields.name'), sortable: true },
    { key: 'company', label: t('contacts.fields.company'), sortable: true, hideable: true, hiddenByDefault: !tablet },
    { key: 'email', label: t('contacts.fields.email'), sortable: true, hideable: true, hiddenByDefault: !tablet },
    { key: 'phone', label: t('contacts.fields.phone'), class: 'whitespace-nowrap', hideable: true, hiddenByDefault: !desktop },
    { key: 'jobTitle', label: t('contacts.fields.jobTitle'), hideable: true, hiddenByDefault: !roomy },
    { key: 'location', label: t('contacts.fields.location'), sortable: true, hideable: true, hiddenByDefault: !roomy },
    { key: 'owner', label: t('contacts.fields.owner'), hideable: true, hiddenByDefault: !roomy },
    { key: 'actions', label: t('contacts.list.actions'), srOnly: true, align: 'right', class: 'w-px' },
]);

const LINK = 'rounded-chip underline-offset-4 transition-colors hover:text-brand-text hover:underline';

/*
 * Details: everything recorded about one person, read only.
 */
const detailsTarget = ref<ContactRow | null>(null);
const detailsOpen = ref(false);

function openDetails(contact: ContactRow): void {
    detailsTarget.value = contact;
    detailsOpen.value = true;
}

useFocusReturn(detailsOpen, () => actionsSelector(detailsTarget.value?.id));

const details = computed<DetailItem[]>(() =>
    detailsTarget.value ? contactDetails(detailsTarget.value, t, page.props.app.locale, (value) => format.date(value)) : [],
);

/*
 * A contact's typed addresses (ADR 0039) are read and changed from its details: those close first, one dialog at a time.
 */
const addressManager = ref<InstanceType<typeof AddressManager> | null>(null);

function fromDetails(action: (manager: InstanceType<typeof AddressManager>, parent: AddressParent) => void): void {
    const contact = detailsTarget.value;
    const manager = addressManager.value;

    if (!contact || !manager) return;

    detailsOpen.value = false;
    void nextTick(() => action(manager, { kind: 'contact', id: contact.id, name: contact.name }));
}

/*
 * Adding and changing share one form.
 */
const formTarget = ref<ContactRow | null>(null);
const formOpen = ref(false);

function openCreate(): void {
    formTarget.value = null;
    formOpen.value = true;
}

function openEdit(contact: ContactRow): void {
    formTarget.value = contact;

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
 * Duplicate matching is fetched only when requested. A merge changes the
 * canonical contact and soft-deletes the duplicate, so both row permissions
 * are required before the action is offered.
 */
const mergeTarget = ref<ContactRow | null>(null);
const mergeOpen = ref(false);

function openDuplicates(contact: ContactRow): void {
    mergeTarget.value = contact;
    mergeOpen.value = true;
}

useFocusReturn(mergeOpen, () => actionsSelector(mergeTarget.value?.id));

/*
 * Deleting takes a contact out of the directory for everyone, so it is confirmed.
 */
const deleteTarget = ref<ContactRow | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function askDelete(contact: ContactRow): void {
    deleteTarget.value = contact;
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

    router.delete(route('core.contacts.destroy', { contact: deleteTarget.value.id }), {
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
        :title="t('contacts.people.title')"
        :description="t('contacts.people.description', { workspace })"
    >
        <template #actions>
            <SegmentedControl v-if="!untouched" v-model="view" :options="viewOptions" :label="t('contacts.views.label')" icon-only />
            <Button v-if="canCreate" variant="primary" data-directory-add @click="openCreate">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.people.add') }}
            </Button>
        </template>
    </PageHeader>

    <DirectoryTabs current="people" class="mb-3" />

    <Card v-if="untouched">
        <EmptyState
            :title="t('contacts.people.empty.title')"
            :description="canCreate ? t('contacts.people.empty.body') : t('contacts.people.empty.bodyViewOnly')"
        >
            <template #icon><UsersRound /></template>
            <template v-if="canCreate" #default>
                <Button size="sm" @click="openCreate">{{ t('contacts.people.add') }}</Button>
            </template>
        </EmptyState>
    </Card>

    <Card v-else flush :aria-busy="loading || undefined">
        <DataTable
            v-if="view === 'list'"
            :columns="columns"
            :rows="contacts.data"
            row-key="id"
            :caption="t('contacts.people.caption')"
            storage-key="contacts"
            sort-mode="server"
            :sort="sortState"
            @update:sort="sortBy"
        >
            <template #toolbar>
                <DirectorySearch ref="searchBox" v-model="search" :label="t('contacts.people.search')" :loading="loading" @submit="apply()" @clear="apply()" />

                <!-- The list is narrowed to one company: say which, and offer the way back. -->
                <p
                    v-if="filters.company"
                    class="glass-well flex max-w-full min-w-0 items-center gap-2 rounded-control py-0.5 pr-0.5 pl-2.5 text-[0.8125rem] text-ink"
                >
                    <Building2 class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <span class="sr-only">{{ t('contacts.fields.company') }}:</span>
                    <span class="min-w-0 truncate" :title="filters.company.name">{{ filters.company.name }}</span>
                    <Button icon size="sm" variant="ghost" @click="clearCompany">
                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                        {{ t('contacts.people.clearCompany') }}
                    </Button>
                </p>

                <p class="eyebrow tabular px-1" role="status">{{ count }}</p>
            </template>

            <template #cell-name="{ row }">
                <!-- Asks the column for 13rem and then fills whatever it gets, so long names truncate instead of widening the table. -->
                <div class="flex w-52 min-w-full items-start gap-2.5 md:items-center">
                    <Avatar :initials="initialsOf(row.name)" :name="row.name" size="sm" />
                    <div class="min-w-0 flex-1">
                        <!-- The name opens the details, as a row does in Odoo's list. -->
                        <button
                            type="button"
                            class="block max-w-full truncate rounded-chip text-left text-sm font-medium text-ink underline-offset-4 hover:text-brand-text hover:underline"
                            :title="row.name"
                            @click="openDetails(row)"
                        >
                            {{ row.name }}
                        </button>

                        <!-- Small screens: the other columns start hidden, so the ways to reach someone sit here. -->
                        <ul v-if="row.company || row.email || row.phone" class="mt-0.5 flex flex-col gap-0.5 text-[0.8125rem] text-ink-muted md:hidden">
                            <li v-if="row.company" class="truncate">
                                <Link :href="peopleOfCompanyUrl(row.company.id)" :class="LINK">{{ row.company.name }}</Link>
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
                </div>
            </template>

            <template #cell-company="{ row }">
                <Link
                    v-if="row.company"
                    :href="peopleOfCompanyUrl(row.company.id)"
                    :title="t('contacts.people.showCompany', { company: row.company.name })"
                    :class="[LINK, 'block w-32 min-w-full truncate text-ink']"
                >
                    {{ row.company.name }}
                </Link>
                <span v-else class="text-ink-subtle" aria-hidden="true">—</span>
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

            <template #cell-jobTitle="{ row }">
                <span v-if="row.jobTitle" class="block w-28 min-w-full truncate text-ink-muted" :title="row.jobTitle">{{ row.jobTitle }}</span>
                <span v-else class="text-ink-subtle" aria-hidden="true">—</span>
            </template>

            <template #cell-location="{ row }">
                <span v-if="row.city || row.country" class="block w-32 min-w-full truncate text-ink-muted">
                    {{ [row.city, countryLabel(row.country)].filter(Boolean).join(', ') }}
                </span>
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
                    :can-merge="row.editable && row.deletable"
                    @view="openDetails(row)"
                    @edit="openEdit(row)"
                    @duplicates="openDuplicates(row)"
                    @delete="askDelete(row)"
                />
            </template>

            <template #empty>
                <PeopleEmpty :query="shownQuery" :company="filters.company" :can-create="canCreate" @clear-filters="clearFilters" @clear-company="clearCompany" @create="openCreate" />
            </template>
        </DataTable>

        <!-- Cards (Odoo's kanban): the same people, page and filters as the list. -->
        <div v-else class="pt-4">
            <div class="flex flex-wrap items-center gap-2 px-5 pb-3">
                <DirectorySearch ref="searchBox" v-model="search" :label="t('contacts.people.search')" :loading="loading" @submit="apply()" @clear="apply()" />
                <p
                    v-if="filters.company"
                    class="glass-well flex max-w-full min-w-0 items-center gap-2 rounded-control py-0.5 pr-0.5 pl-2.5 text-[0.8125rem] text-ink"
                >
                    <Building2 class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <span class="sr-only">{{ t('contacts.fields.company') }}:</span>
                    <span class="min-w-0 truncate" :title="filters.company.name">{{ filters.company.name }}</span>
                    <Button icon size="sm" variant="ghost" @click="clearCompany">
                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                        {{ t('contacts.people.clearCompany') }}
                    </Button>
                </p>
                <p class="eyebrow tabular px-1" role="status">{{ count }}</p>
                <Select v-model="gridSort" :options="sortOptions" :aria-label="t('contacts.sort.label')" class="ml-auto w-full sm:w-52" />
            </div>

            <ul v-if="contacts.data.length" class="grid gap-3 border-t border-line p-3 sm:grid-cols-2 sm:p-4 xl:grid-cols-3 2xl:grid-cols-4" :aria-label="t('contacts.people.caption')">
                <li v-for="contact in contacts.data" :key="contact.id" class="min-w-0">
                    <ContactCard
                        :contact="contact"
                        :country-label="countryLabel(contact.country)"
                        @view="openDetails(contact)"
                        @edit="openEdit(contact)"
                        @duplicates="openDuplicates(contact)"
                        @delete="askDelete(contact)"
                    />
                </li>
            </ul>
            <div v-else class="border-t border-line">
                <PeopleEmpty :query="shownQuery" :company="filters.company" :can-create="canCreate" @clear-filters="clearFilters" @clear-company="clearCompany" @create="openCreate" />
            </div>
        </div>

        <Pagination :meta="contacts" />

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
        :description="t('contacts.people.details')"
        :items="details"
        :editable="detailsTarget?.editable"
        @edit="detailsTarget && openEdit(detailsTarget)"
    >
        <ContactAddresses
            v-if="detailsTarget?.addresses"
            :addresses="detailsTarget.addresses"
            :editable="detailsTarget.editable"
            @add="fromDetails((manager, parent) => manager.add(parent))"
            @edit="(address) => fromDetails((manager, parent) => manager.edit(parent, address))"
            @make-default="(address) => fromDetails((manager) => manager.makeDefault(address))"
            @delete="(address) => fromDetails((manager, parent) => manager.remove(parent, address))"
        />
    </DetailsDialog>

    <AddressManager ref="addressManager" />

    <ContactFormDialog
        v-if="canCreate"
        v-model:open="formOpen"
        :contact="formTarget"
        :companies="companies"
        :owners="owners"
        :start-company="filters.company"
    />

    <ContactMergeDialog v-model:open="mergeOpen" :contact="mergeTarget" />

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('contacts.people.delete.title', { name: deleteTarget?.name })"
        :description="t('contacts.people.delete.body', { name: deleteTarget?.name, workspace })"
        :confirm-label="t('contacts.people.delete.confirm')"
        :loading="deleting"
        @confirm="destroy"
    >
        <p v-if="deleteError" class="text-[0.8125rem] text-danger" role="alert">{{ deleteError }}</p>
    </ConfirmDialog>
</template>
