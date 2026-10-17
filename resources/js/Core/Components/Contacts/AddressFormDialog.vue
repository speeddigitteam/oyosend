<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { countryName, countryOptions } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';
import { ADDRESS_TYPES } from './addresses';
import type { AddressParent, AddressRow, AddressType } from './types';

/**
 * Adds an invoice, delivery or other address to a company or a contact, or changes one. Holds every field the server
 * accepts. The first address of a type becomes its default on the server; a type always keeps one default, so the
 * default is handed over by making another address the default rather than by unticking it.
 */
const props = defineProps<{
    /** The address to change; null adds a new one to `parent`. */
    address: AddressRow | null;
    parent: AddressParent;
    /** The type a new address starts with. */
    startType?: AddressType;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const page = usePage();

const formId = useId();
const formElement = ref<HTMLFormElement | null>(null);

const form = useForm({
    // A plain string for the native select; the server accepts only the three types.
    type: 'invoice' as string,
    label: '',
    name: '',
    email: '',
    phone: '',
    address: '',
    city: '',
    postal_code: '',
    country: '',
    is_default: false,
});

const typeOptions = computed<SelectOption[]>(() => ADDRESS_TYPES.map((type) => ({ value: type, label: t(`contacts.addresses.types.${type}`) })));

const countries = computed<SelectOption[]>(() => {
    const locale = page.props.app.locale;
    const list = countryOptions(locale);
    const current = props.address?.country;

    return [
        { value: '', label: t('contacts.form.noCountry') },
        ...list,
        ...(current && !list.some((option) => option.value === current) ? [{ value: current, label: countryName(current, locale) }] : []),
    ];
});

const typeName = computed(() => t(`contacts.addresses.types.${form.type}`).toLocaleLowerCase(page.props.app.locale));
/** Already the default of the type it keeps: nothing to tick. */
const isDefaultAlready = computed(() => props.address !== null && props.address.isDefault && props.address.type === form.type);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.clearErrors();

        return;
    }

    const address = props.address;

    form.type = address?.type ?? props.startType ?? 'invoice';
    form.label = address?.label ?? '';
    form.name = address?.name ?? '';
    form.email = address?.email ?? '';
    form.phone = address?.phone ?? '';
    form.address = address?.address ?? '';
    form.city = address?.city ?? '';
    form.postal_code = address?.postalCode ?? '';
    form.country = address?.country ?? '';
    form.is_default = false;
    form.clearErrors();

    // The dialog focuses its close button by default; start in the form instead.
    await nextTick();
    requestAnimationFrame(() => formElement.value?.querySelector<HTMLElement>('select, input, textarea')?.focus());
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

    // Only a request to take the default over is sent; leaving the box empty changes nothing.
    form.transform(({ is_default: isDefault, ...fields }) => (isDefault ? { ...fields, is_default: true } : fields));

    if (props.address) {
        form.put(route('core.contacts.addresses.update', { address: props.address.id }), options);
    } else if (props.parent.kind === 'company') {
        form.post(route('core.contacts.addresses.company.store', { company: props.parent.id }), options);
    } else {
        form.post(route('core.contacts.addresses.contact.store', { contact: props.parent.id }), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="address ? t('contacts.addresses.form.editTitle', { name: parent.name }) : t('contacts.addresses.form.addTitle', { name: parent.name })"
        :description="t('contacts.addresses.form.description')"
        size="lg"
        :persistent="form.processing"
    >
        <form :id="formId" ref="formElement" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate data-address-form @submit.prevent="submit">
            <Select v-model="form.type" name="type" :label="t('contacts.addresses.fields.type')" :options="typeOptions" :error="form.errors.type" />
            <Input
                v-model="form.label"
                name="label"
                :label="t('contacts.addresses.fields.label')"
                :hint="t('contacts.addresses.fields.labelHint')"
                :error="form.errors.label"
                autocomplete="off"
                maxlength="120"
            />

            <div class="sm:col-span-2">
                <Input v-model="form.name" name="name" :label="t('contacts.addresses.fields.name')" :error="form.errors.name" autocomplete="off" maxlength="200" />
            </div>

            <Input
                v-model="form.email"
                type="email"
                name="email"
                :label="t('contacts.addresses.fields.email')"
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
                :label="t('contacts.addresses.fields.phone')"
                :error="form.errors.phone"
                autocomplete="off"
                inputmode="tel"
                maxlength="40"
            />

            <div class="sm:col-span-2">
                <Textarea
                    v-model="form.address"
                    name="address"
                    :label="t('contacts.addresses.fields.address')"
                    :error="form.errors.address"
                    :rows="2"
                    autocomplete="off"
                    maxlength="1000"
                />
            </div>

            <Input v-model="form.city" name="city" :label="t('contacts.addresses.fields.city')" :error="form.errors.city" autocomplete="off" maxlength="120" />
            <Input
                v-model="form.postal_code"
                name="postal_code"
                :label="t('contacts.addresses.fields.postalCode')"
                :error="form.errors.postal_code"
                autocomplete="off"
                maxlength="20"
            />

            <div class="sm:col-span-2">
                <Select v-model="form.country" name="country" :label="t('contacts.addresses.fields.country')" :options="countries" :error="form.errors.country" />
            </div>

            <div class="sm:col-span-2">
                <p v-if="isDefaultAlready" class="text-[0.8125rem] text-ink-muted" data-address-default-note>
                    {{ t('contacts.addresses.defaultNote', { type: typeName }) }}
                </p>
                <Checkbox v-else v-model="form.is_default" name="is_default" :label="t('contacts.addresses.fields.isDefault', { type: typeName })" />
            </div>
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing">
                {{ address ? t('common.actions.save') : t('contacts.addresses.add') }}
            </Button>
        </template>
    </Modal>
</template>
