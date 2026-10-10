<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { countryName, countryOptions } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';
import type { ContactRow, DirectoryOption } from './types';

/**
 * Adds a person to the directory, or changes one. Holds every field the
 * server accepts; the owner can only be chosen by someone who manages every
 * contact - for everyone else a new contact is simply theirs.
 */
const props = defineProps<{
    /** The contact to change; null adds a new one. */
    contact: ContactRow | null;
    /** Companies this person can put a contact in. */
    companies: DirectoryOption[];
    /** Members a contact can be handed to. Empty unless this person manages every contact. */
    owners: DirectoryOption[];
    /** Where a new contact starts out: the company the list is narrowed to. */
    startCompany?: DirectoryOption | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const page = usePage();

const formId = useId();
const formElement = ref<HTMLFormElement | null>(null);
const firstInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    job_title: '',
    company_id: '',
    address: '',
    city: '',
    country: '',
    notes: '',
    owner_id: '',
});

const picksOwner = computed(() => props.owners.length > 0);

/** `extra` joins the list when it is not in it: a value the form holds must always have its option. */
function options(list: DirectoryOption[], extra: DirectoryOption | null | undefined): SelectOption[] {
    const all = extra && !list.some((item) => item.id === extra.id) ? [...list, extra] : list;

    return all.map((item) => ({ value: item.id, label: item.name }));
}

const companyOptions = computed<SelectOption[]>(() => [
    { value: '', label: t('contacts.people.form.noCompany') },
    // The company it is in already, even one this person cannot pick for anyone else.
    ...options(props.companies, props.contact ? props.contact.company : props.startCompany),
]);

const ownerOptions = computed<SelectOption[]>(() => [
    // A new contact always belongs to someone; an existing one can be handed to no one.
    ...(props.contact ? [{ value: '', label: t('contacts.list.noOwner') }] : []),
    // Its owner stays in the list when they can no longer be given anything (suspended, say).
    ...options(props.owners, props.contact?.owner),
]);

const countries = computed<SelectOption[]>(() => {
    const locale = page.props.app.locale;
    const list = countryOptions(locale);
    const current = props.contact?.country;

    return [
        { value: '', label: t('contacts.form.noCountry') },
        ...list,
        ...(current && !list.some((option) => option.value === current) ? [{ value: current, label: countryName(current, locale) }] : []),
    ];
});

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.clearErrors();

        return;
    }

    const contact = props.contact;
    const self = page.props.auth?.membership?.id ?? '';

    form.first_name = contact?.firstName ?? '';
    form.last_name = contact?.lastName ?? '';
    form.email = contact?.email ?? '';
    form.phone = contact?.phone ?? '';
    form.job_title = contact?.jobTitle ?? '';
    form.company_id = contact ? (contact.company?.id ?? '') : (props.startCompany?.id ?? '');
    form.address = contact?.address ?? '';
    form.city = contact?.city ?? '';
    form.country = contact?.country ?? '';
    form.notes = contact?.notes ?? '';
    // New contacts start out with the person adding them.
    form.owner_id = contact ? (contact.owner?.id ?? '') : props.owners.some((owner) => owner.id === self) ? self : (props.owners[0]?.id ?? '');
    form.clearErrors();

    // The dialog focuses its close button by default; start in the form instead.
    await nextTick();
    requestAnimationFrame(() => firstInput.value?.focus());
});

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        // The field at fault may be scrolled out of the dialog: go to it.
        onError: () => {
            void nextTick(() => formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
        },
    };

    // Whose contact it is is only said by someone who may decide it.
    form.transform(({ owner_id: owner, ...fields }) => (picksOwner.value ? { ...fields, owner_id: owner } : fields));

    if (props.contact) {
        form.put(route('core.contacts.update', { contact: props.contact.id }), options);
    } else {
        form.post(route('core.contacts.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="contact ? t('contacts.people.form.editTitle', { name: contact.name }) : t('contacts.people.form.addTitle')"
        :description="t('contacts.people.form.description')"
        size="lg"
        :persistent="form.processing"
    >
        <form :id="formId" ref="formElement" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <Input
                ref="firstInput"
                v-model="form.first_name"
                name="first_name"
                :label="t('contacts.fields.firstName')"
                :error="form.errors.first_name"
                autocomplete="off"
                maxlength="100"
                required
            />
            <Input
                v-model="form.last_name"
                name="last_name"
                :label="t('contacts.fields.lastName')"
                :error="form.errors.last_name"
                autocomplete="off"
                maxlength="100"
            />

            <Input
                v-model="form.email"
                type="email"
                name="email"
                :label="t('contacts.fields.email')"
                :error="form.errors.email"
                autocomplete="off"
                autocapitalize="none"
                spellcheck="false"
                inputmode="email"
                maxlength="255"
            />
            <Input
                v-model="form.phone"
                type="tel"
                name="phone"
                :label="t('contacts.fields.phone')"
                :error="form.errors.phone"
                autocomplete="off"
                inputmode="tel"
                maxlength="40"
            />

            <Input
                v-model="form.job_title"
                name="job_title"
                :label="t('contacts.fields.jobTitle')"
                :error="form.errors.job_title"
                autocomplete="off"
                maxlength="120"
            />
            <Select
                v-model="form.company_id"
                name="company_id"
                :label="t('contacts.fields.company')"
                :options="companyOptions"
                :hint="companyOptions.length === 1 ? t('contacts.people.form.noCompanies') : undefined"
                :error="form.errors.company_id"
            />

            <div class="sm:col-span-2">
                <Textarea
                    v-model="form.address"
                    name="address"
                    :label="t('contacts.fields.address')"
                    :error="form.errors.address"
                    :rows="2"
                    autocomplete="off"
                    maxlength="500"
                />
            </div>

            <Input v-model="form.city" name="city" :label="t('contacts.fields.city')" :error="form.errors.city" autocomplete="off" maxlength="120" />
            <Select v-model="form.country" name="country" :label="t('contacts.fields.country')" :options="countries" :error="form.errors.country" />

            <div class="sm:col-span-2">
                <Textarea
                    v-model="form.notes"
                    name="notes"
                    :label="t('contacts.fields.notes')"
                    :hint="t('contacts.form.notesHint')"
                    :error="form.errors.notes"
                    :rows="3"
                    maxlength="5000"
                />
            </div>

            <div v-if="picksOwner" class="sm:col-span-2">
                <Select
                    v-model="form.owner_id"
                    name="owner_id"
                    :label="t('contacts.fields.owner')"
                    :options="ownerOptions"
                    :hint="t('contacts.form.ownerHint')"
                    :error="form.errors.owner_id"
                />
            </div>
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing">
                {{ contact ? t('common.actions.save') : t('contacts.people.add') }}
            </Button>
        </template>
    </Modal>
</template>
