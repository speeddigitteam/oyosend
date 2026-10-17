<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Globe, Mail, MapPin, Pencil, Phone, Plus, ReceiptText, Trash2, UserRound, UsersRound } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import AddressList from '@/Core/Components/Contacts/AddressList.vue';
import AddressManager from '@/Core/Components/Contacts/AddressManager.vue';
import CompanyFormDialog from '@/Core/Components/Contacts/CompanyFormDialog.vue';
import ContactAddresses from '@/Core/Components/Contacts/ContactAddresses.vue';
import ContactCard from '@/Core/Components/Contacts/ContactCard.vue';
import ContactFormDialog from '@/Core/Components/Contacts/ContactFormDialog.vue';
import ContactMergeDialog from '@/Core/Components/Contacts/ContactMergeDialog.vue';
import { contactDetails } from '@/Core/Components/Contacts/details';
import DetailsDialog from '@/Core/Components/Contacts/DetailsDialog.vue';
import { actionsSelector, mailHref, peopleOfCompanyUrl, telHref } from '@/Core/Components/Contacts/links';
import type { AddressParent, CompanyRow, ContactRow, DirectoryOption } from '@/Core/Components/Contacts/types';
import { initialsOf } from '@/Core/Components/Contacts/view';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { countryName } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

/**
 * A company's own page, like Odoo's company form: what is recorded about it, its invoice, delivery and other addresses
 * (ADR 0039), and its people as cards under "Contacts", where another person is added with the company already chosen.
 */
const props = defineProps<{
    company: CompanyRow;
    /** Its people this viewer can see, by name; at most 60 (`company.contacts` is how many there are). */
    people: ContactRow[];
    /** Companies a contact can be put in. Empty unless the person may add or change contacts. */
    companies: DirectoryOption[];
    /** Members a record can be handed to. Empty unless the person manages every record. */
    owners: DirectoryOption[];
    canCreate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

const workspace = computed(() => page.props.tenant?.name ?? '');
const country = computed(() => countryName(props.company.country, page.props.app.locale) || null);
const place = computed(() => [props.company.address, props.company.city, country.value].filter((part): part is string => Boolean(part)).join(', '));
const subtitle = computed(() => [props.company.industry, props.company.city].filter(Boolean).join(' · '));
const self = computed<DirectoryOption>(() => ({ id: props.company.id, name: props.company.name }));
const hiddenPeople = computed(() => Math.max(0, props.company.contacts - props.people.length));

const LINK = 'min-w-0 truncate rounded-chip underline-offset-4 transition-colors hover:text-brand-text hover:underline';

/* ---- the company itself ---------------------------------------------------------------------------------------- */

const companyFormOpen = ref(false);
const companyDeleteOpen = ref(false);
const companyDeleting = ref(false);
const companyDeleteError = ref<string | null>(null);

function destroyCompany(): void {
    companyDeleting.value = true;
    companyDeleteError.value = null;

    // The server answers with the companies list: this page no longer exists afterwards.
    router.delete(route('core.contacts.companies.destroy', { company: props.company.id }), {
        onError: (errors) => {
            companyDeleteError.value = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            companyDeleting.value = false;
        },
    });
}

/* ---- its addresses, and those of its people ------------------------------------------------------------------- */

const addressManager = ref<InstanceType<typeof AddressManager> | null>(null);
const companyParent = computed<AddressParent>(() => ({ kind: 'company', id: props.company.id, name: props.company.name }));
const companyAddresses = computed(() => props.company.addresses ?? []);

/** A person's address dialogs open from their details: those close first, one dialog at a time. */
function fromDetails(action: (manager: InstanceType<typeof AddressManager>, parent: AddressParent) => void): void {
    const contact = detailsTarget.value;
    const manager = addressManager.value;

    if (!contact || !manager) return;

    detailsOpen.value = false;
    void nextTick(() => action(manager, { kind: 'contact', id: contact.id, name: contact.name }));
}

/* ---- its people ------------------------------------------------------------------------------------------------- */

const detailsTarget = ref<ContactRow | null>(null);
const detailsOpen = ref(false);
const details = computed(() => (detailsTarget.value ? contactDetails(detailsTarget.value, t, page.props.app.locale, (value) => format.date(value)) : []));

function openDetails(contact: ContactRow): void {
    detailsTarget.value = contact;
    detailsOpen.value = true;
}

useFocusReturn(detailsOpen, () => actionsSelector(detailsTarget.value?.id));

const formTarget = ref<ContactRow | null>(null);
const formOpen = ref(false);

function openCreate(): void {
    formTarget.value = null;
    formOpen.value = true;
}

function openEdit(contact: ContactRow): void {
    formTarget.value = contact;

    if (detailsOpen.value) {
        detailsOpen.value = false;
        void nextTick(() => {
            formOpen.value = true;
        });
    } else {
        formOpen.value = true;
    }
}

useFocusReturn(formOpen, () => (formTarget.value ? actionsSelector(formTarget.value.id) : '[data-company-add-person]'));

const mergeTarget = ref<ContactRow | null>(null);
const mergeOpen = ref(false);

function openDuplicates(contact: ContactRow): void {
    mergeTarget.value = contact;
    mergeOpen.value = true;
}

const deleteTarget = ref<ContactRow | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function askDelete(contact: ContactRow): void {
    deleteTarget.value = contact;
    deleteError.value = null;
    deleteOpen.value = true;
}

function destroyPerson(): void {
    if (!deleteTarget.value) return;

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
    <div class="mb-3">
        <Link :href="route('core.contacts.companies.index')" class="inline-flex items-center gap-1.5 rounded-chip text-[0.8125rem] text-ink-muted hover:text-ink">
            <ArrowLeft class="size-3.5 rtl:rotate-180" aria-hidden="true" />
            {{ t('contacts.company.back') }}
        </Link>
    </div>

    <PageHeader :eyebrow="t('contacts.companies.title')" :title="company.name" :description="subtitle || undefined">
        <template #actions>
            <Button v-if="company.editable" variant="secondary" data-company-edit @click="companyFormOpen = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.company.edit') }}
            </Button>
            <Button v-if="company.deletable" variant="ghost" @click="companyDeleteOpen = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.companies.delete.confirm') }}
            </Button>
        </template>
    </PageHeader>

    <!-- What is recorded about the company, as Odoo's form shows it. -->
    <Card class="mb-4">
        <div class="flex flex-col gap-5 sm:flex-row">
            <Avatar :initials="initialsOf(company.name)" :name="company.name" size="lg" square />

            <dl class="grid min-w-0 flex-1 gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                <div class="flex min-w-0 items-start gap-2.5">
                    <Mail class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <dt class="sr-only">{{ t('contacts.fields.email') }}</dt>
                    <dd class="min-w-0">
                        <a v-if="company.email" :href="mailHref(company.email)" :class="[LINK, 'block text-ink']">{{ company.email }}</a>
                        <span v-else class="text-ink-subtle">{{ t('contacts.company.notRecorded', { field: t('contacts.fields.email') }) }}</span>
                    </dd>
                </div>
                <div class="flex min-w-0 items-start gap-2.5">
                    <Phone class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <dt class="sr-only">{{ t('contacts.fields.phone') }}</dt>
                    <dd class="min-w-0">
                        <a v-if="telHref(company.phone)" :href="telHref(company.phone)" :class="[LINK, 'tabular block text-ink']">{{ company.phone }}</a>
                        <span v-else-if="company.phone" class="tabular text-ink">{{ company.phone }}</span>
                        <span v-else class="text-ink-subtle">{{ t('contacts.company.notRecorded', { field: t('contacts.fields.phone') }) }}</span>
                    </dd>
                </div>
                <div class="flex min-w-0 items-start gap-2.5">
                    <Globe class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <dt class="sr-only">{{ t('contacts.fields.website') }}</dt>
                    <dd class="min-w-0">
                        <a v-if="company.website" :href="company.website" target="_blank" rel="noopener noreferrer nofollow" :class="[LINK, 'block text-ink']">
                            {{ company.website }}
                        </a>
                        <span v-else class="text-ink-subtle">{{ t('contacts.company.notRecorded', { field: t('contacts.fields.website') }) }}</span>
                    </dd>
                </div>
                <div class="flex min-w-0 items-start gap-2.5">
                    <ReceiptText class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <dt class="sr-only">{{ t('contacts.fields.taxId') }}</dt>
                    <dd class="min-w-0">
                        <span v-if="company.taxId" class="tabular text-ink">{{ t('contacts.company.taxId', { id: company.taxId }) }}</span>
                        <span v-else class="text-ink-subtle">{{ t('contacts.company.notRecorded', { field: t('contacts.fields.taxId') }) }}</span>
                    </dd>
                </div>
                <div class="flex min-w-0 items-start gap-2.5">
                    <MapPin class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <dt class="sr-only">{{ t('contacts.fields.address') }}</dt>
                    <dd class="min-w-0 whitespace-pre-line text-ink">
                        <template v-if="place">{{ place }}</template>
                        <span v-else class="text-ink-subtle">{{ t('contacts.company.notRecorded', { field: t('contacts.fields.address') }) }}</span>
                    </dd>
                </div>
                <div class="flex min-w-0 items-start gap-2.5">
                    <UserRound class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <dt class="sr-only">{{ t('contacts.fields.owner') }}</dt>
                    <dd class="min-w-0 text-ink">{{ company.owner?.name ?? t('contacts.list.noOwner') }}</dd>
                </div>
                <div v-if="company.notes" class="min-w-0 sm:col-span-2">
                    <dt class="eyebrow mb-1">{{ t('contacts.fields.notes') }}</dt>
                    <dd class="whitespace-pre-line text-ink-muted">{{ company.notes }}</dd>
                </div>
            </dl>
        </div>
    </Card>

    <!-- Its addresses, Odoo's "Contacts & Addresses": invoice, delivery and other, each with its own person. -->
    <Card flush class="mb-4" data-company-addresses>
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-3">
            <div class="min-w-0">
                <h2 class="text-[0.9375rem] font-semibold text-ink">{{ t('contacts.addresses.title') }}</h2>
                <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ t('contacts.addresses.description') }}</p>
            </div>
            <Button v-if="company.editable" size="sm" data-company-add-address @click="addressManager?.add(companyParent)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.addresses.add') }}
            </Button>
        </div>

        <div v-if="companyAddresses.length" class="border-t border-line p-3 sm:p-4">
            <AddressList
                :addresses="companyAddresses"
                @edit="(address) => addressManager?.edit(companyParent, address)"
                @make-default="(address) => addressManager?.makeDefault(address)"
                @delete="(address) => addressManager?.remove(companyParent, address)"
            />
        </div>
        <div v-else class="border-t border-line">
            <EmptyState
                :title="t('contacts.addresses.empty.title')"
                :description="company.editable ? t('contacts.addresses.empty.body') : t('contacts.addresses.empty.bodyViewOnly')"
            >
                <template #icon><MapPin /></template>
            </EmptyState>
        </div>
    </Card>

    <!-- Its people, Odoo's "Contacts & Addresses" tab: cards, and the way to add one more. -->
    <Card flush>
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-3">
            <div class="min-w-0">
                <h2 class="text-[0.9375rem] font-semibold text-ink">{{ t('contacts.company.people') }}</h2>
                <p class="eyebrow tabular mt-0.5" role="status">
                    {{ t(`contacts.companies.people.${company.contacts === 1 ? 'one' : 'other'}`, { count: format.number(company.contacts) }) }}
                </p>
            </div>
            <Button v-if="canCreate" size="sm" data-company-add-person @click="openCreate">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.company.addPerson') }}
            </Button>
        </div>

        <ul v-if="people.length" class="grid gap-3 border-t border-line p-3 sm:grid-cols-2 sm:p-4 xl:grid-cols-3" :aria-label="t('contacts.company.people')">
            <li v-for="person in people" :key="person.id" class="min-w-0">
                <ContactCard
                    :contact="person"
                    :country-label="countryName(person.country, page.props.app.locale) || null"
                    hide-company
                    @view="openDetails(person)"
                    @edit="openEdit(person)"
                    @duplicates="openDuplicates(person)"
                    @delete="askDelete(person)"
                />
            </li>
        </ul>
        <div v-else class="border-t border-line">
            <EmptyState :title="t('contacts.company.noPeople.title')" :description="canCreate ? t('contacts.company.noPeople.body') : t('contacts.company.noPeople.bodyViewOnly')">
                <template #icon><UsersRound /></template>
                <Button v-if="canCreate" size="sm" @click="openCreate">{{ t('contacts.company.addPerson') }}</Button>
            </EmptyState>
        </div>

        <template v-if="hiddenPeople > 0" #footer>
            <Link :href="peopleOfCompanyUrl(company.id)" :class="[LINK, 'text-[0.8125rem] text-brand-text']">
                {{ t('contacts.company.showAll', { count: format.number(company.contacts) }) }}
            </Link>
        </template>
    </Card>

    <CompanyFormDialog v-if="company.editable" v-model:open="companyFormOpen" :company="company" :owners="owners" />

    <ConfirmDialog
        v-model:open="companyDeleteOpen"
        :title="t('contacts.companies.delete.title', { name: company.name })"
        :description="t('contacts.companies.delete.body', { name: company.name, workspace })"
        :confirm-label="t('contacts.companies.delete.confirm')"
        :loading="companyDeleting"
        @confirm="destroyCompany"
    >
        <p class="text-sm text-ink-muted">{{ t('contacts.companies.delete.peopleStay') }}</p>
        <p v-if="companyDeleteError" class="mt-3 text-[0.8125rem] text-danger" role="alert">{{ companyDeleteError }}</p>
    </ConfirmDialog>

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

    <ContactFormDialog v-if="canCreate" v-model:open="formOpen" :contact="formTarget" :companies="companies" :owners="owners" :start-company="self" />

    <ContactMergeDialog v-model:open="mergeOpen" :contact="mergeTarget" />

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('contacts.people.delete.title', { name: deleteTarget?.name })"
        :description="t('contacts.people.delete.body', { name: deleteTarget?.name, workspace })"
        :confirm-label="t('contacts.people.delete.confirm')"
        :loading="deleting"
        @confirm="destroyPerson"
    >
        <p v-if="deleteError" class="text-[0.8125rem] text-danger" role="alert">{{ deleteError }}</p>
    </ConfirmDialog>
</template>
