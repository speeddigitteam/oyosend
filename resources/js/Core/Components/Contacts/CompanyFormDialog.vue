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
import type { CompanyRow, DirectoryOption } from './types';

/**
 * Adds a company to the directory, or changes one. Holds every field the
 * server accepts; the owner can only be chosen by someone who manages every
 * company - for everyone else a new company is simply theirs.
 */
const props = defineProps<{
    /** The company to change; null adds a new one. */
    company: CompanyRow | null;
    /** Members a company can be handed to. Empty unless this person manages every company. */
    owners: DirectoryOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const page = usePage();

const formId = useId();
const formElement = ref<HTMLFormElement | null>(null);
const firstInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({
    name: '',
    email: '',
    phone: '',
    website: '',
    industry: '',
    tax_id: '',
    address: '',
    city: '',
    country: '',
    notes: '',
    owner_id: '',
});

const picksOwner = computed(() => props.owners.length > 0);

const ownerOptions = computed<SelectOption[]>(() => {
    const current = props.company?.owner;
    // Its owner stays in the list when they can no longer be given anything (suspended, say).
    const list = current && !props.owners.some((owner) => owner.id === current.id) ? [...props.owners, current] : props.owners;

    return [
        // A new company always belongs to someone; an existing one can be handed to no one.
        ...(props.company ? [{ value: '', label: t('contacts.list.noOwner') }] : []),
        ...list.map((owner) => ({ value: owner.id, label: owner.name })),
    ];
});

const countries = computed<SelectOption[]>(() => {
    const locale = page.props.app.locale;
    const list = countryOptions(locale);
    const current = props.company?.country;

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

    const company = props.company;
    const self = page.props.auth?.membership?.id ?? '';

    form.name = company?.name ?? '';
    form.email = company?.email ?? '';
    form.phone = company?.phone ?? '';
    form.website = company?.website ?? '';
    form.industry = company?.industry ?? '';
    form.tax_id = company?.taxId ?? '';
    form.address = company?.address ?? '';
    form.city = company?.city ?? '';
    form.country = company?.country ?? '';
    form.notes = company?.notes ?? '';
    // New companies start out with the person adding them.
    form.owner_id = company ? (company.owner?.id ?? '') : props.owners.some((owner) => owner.id === self) ? self : (props.owners[0]?.id ?? '');
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

    // Whose company it is is only said by someone who may decide it.
    form.transform(({ owner_id: owner, ...fields }) => (picksOwner.value ? { ...fields, owner_id: owner } : fields));

    if (props.company) {
        form.put(route('core.contacts.companies.update', { company: props.company.id }), options);
    } else {
        form.post(route('core.contacts.companies.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="company ? t('contacts.companies.form.editTitle', { name: company.name }) : t('contacts.companies.form.addTitle')"
        :description="t('contacts.companies.form.description')"
        size="lg"
        :persistent="form.processing"
    >
        <form :id="formId" ref="formElement" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <div class="sm:col-span-2">
                <Input
                    ref="firstInput"
                    v-model="form.name"
                    name="name"
                    :label="t('contacts.fields.name')"
                    :error="form.errors.name"
                    autocomplete="off"
                    maxlength="200"
                    required
                />
            </div>

            <Input
                v-model="form.industry"
                name="industry"
                :label="t('contacts.fields.industry')"
                :error="form.errors.industry"
                autocomplete="off"
                maxlength="120"
            />
            <Input
                v-model="form.tax_id"
                name="tax_id"
                :label="t('contacts.fields.taxId')"
                :error="form.errors.tax_id"
                autocomplete="off"
                spellcheck="false"
                maxlength="60"
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

            <div class="sm:col-span-2">
                <Input
                    v-model="form.website"
                    type="url"
                    name="website"
                    :label="t('contacts.fields.website')"
                    :hint="t('contacts.companies.form.websiteHint')"
                    :error="form.errors.website"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    inputmode="url"
                    maxlength="255"
                />
            </div>

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
                {{ company ? t('common.actions.save') : t('contacts.companies.add') }}
            </Button>
        </template>
    </Modal>
</template>
