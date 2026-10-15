<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, Lock } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import FormSection from '@/Core/Components/Settings/FormSection.vue';
import RegionalFields from '@/Core/Components/Settings/RegionalFields.vue';
import WorkspaceLogoCard from '@/Core/Components/Settings/WorkspaceLogoCard.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { countryOptions } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    workspace: {
        name: string;
        legalName: string | null;
        email: string | null;
        phone: string | null;
        website: string | null;
        address: string | null;
        city: string | null;
        postalCode: string | null;
        /** ISO 3166-1 alpha-2, upper case. */
        country: string | null;
        taxId: string | null;
        /** Interface language code, one of the keys of `app.locales`. */
        locale: string;
        timezone: string;
        currency: string;
        /** Where the workspace lives. Fixed: it is shown, never edited. */
        url: string;
        /** Short-lived signed URL, or null when the workspace has no logo. */
        logoUrl: string | null;
    };
    /** Every IANA time zone identifier the server accepts. */
    timezones: string[];
    /** ISO 4217 codes a workspace may choose. */
    currencies: string[];
}>();

const page = usePage();
const { t } = useI18n();

// Empty strings, not nulls: an emptied field is saved as "not set" by the server.
const form = useForm({
    name: props.workspace.name,
    legal_name: props.workspace.legalName ?? '',
    tax_id: props.workspace.taxId ?? '',
    email: props.workspace.email ?? '',
    phone: props.workspace.phone ?? '',
    website: props.workspace.website ?? '',
    address: props.workspace.address ?? '',
    city: props.workspace.city ?? '',
    postal_code: props.workspace.postalCode ?? '',
    country: props.workspace.country ?? '',
    locale: props.workspace.locale,
    timezone: props.workspace.timezone,
    currency: props.workspace.currency,
});

const countries = computed<SelectOption[]>(() => [
    { value: '', label: t('workspace.fields.noCountry') },
    ...countryOptions(page.props.app.locale),
]);

function submit(): void {
    form.put(route('core.settings.workspace.update'), { preserveScroll: true });
}

/*
 * The address is for telling colleagues where to sign in, so it can be copied.
 * Shown without the scheme, the way people say and type it.
 */
const host = computed(() => {
    try {
        return new URL(props.workspace.url).host;
    } catch {
        return props.workspace.url;
    }
});

const canCopy = typeof navigator !== 'undefined' && typeof navigator.clipboard?.writeText === 'function';
const copied = ref(false);
let copiedTimer: number | undefined;

async function copyAddress(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.workspace.url);
    } catch {
        return;
    }

    copied.value = true;

    window.clearTimeout(copiedTimer);
    copiedTimer = window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}

onBeforeUnmount(() => window.clearTimeout(copiedTimer));
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.manage')"
        :title="t('workspace.settings.title')"
        :description="t('workspace.settings.description', { workspace: workspace.name })"
    />

    <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_21rem]">
        <!-- One form, one save: the server takes the profile and the regional settings together. -->
        <form novalidate @submit.prevent="submit">
            <Card>
                <FormSection :title="t('workspace.sections.company.title')" :description="t('workspace.sections.company.description')">
                    <Input
                        v-model="form.name"
                        name="name"
                        :label="t('workspace.fields.name')"
                        :hint="t('workspace.fields.nameHint')"
                        :error="form.errors.name"
                        autocomplete="organization"
                        maxlength="255"
                        required
                    />
                    <Input
                        v-model="form.legal_name"
                        name="legal_name"
                        :label="t('workspace.fields.legalName')"
                        :hint="t('workspace.fields.legalNameHint')"
                        :error="form.errors.legal_name"
                        autocomplete="off"
                        maxlength="255"
                        optional
                    />
                    <Input
                        v-model="form.tax_id"
                        name="tax_id"
                        :label="t('workspace.fields.taxId')"
                        :hint="t('workspace.fields.taxIdHint')"
                        :error="form.errors.tax_id"
                        autocomplete="off"
                        spellcheck="false"
                        maxlength="60"
                        optional
                    />
                </FormSection>

                <FormSection :title="t('workspace.sections.contact.title')" :description="t('workspace.sections.contact.description')" divided>
                    <Input
                        v-model="form.email"
                        type="email"
                        name="email"
                        :label="t('workspace.fields.email')"
                        :error="form.errors.email"
                        autocomplete="email"
                        inputmode="email"
                        maxlength="255"
                        optional
                    />
                    <Input
                        v-model="form.phone"
                        type="tel"
                        name="phone"
                        :label="t('workspace.fields.phone')"
                        :error="form.errors.phone"
                        autocomplete="tel"
                        inputmode="tel"
                        maxlength="40"
                        optional
                    />
                    <div class="sm:col-span-2">
                        <Input
                            v-model="form.website"
                            type="url"
                            name="website"
                            :label="t('workspace.fields.website')"
                            :hint="t('workspace.fields.websiteHint')"
                            :error="form.errors.website"
                            autocomplete="url"
                            inputmode="url"
                            autocapitalize="none"
                            spellcheck="false"
                            maxlength="255"
                            optional
                        />
                    </div>
                </FormSection>

                <FormSection :title="t('workspace.sections.address.title')" :description="t('workspace.sections.address.description')" divided>
                    <div class="sm:col-span-2">
                        <Textarea
                            v-model="form.address"
                            name="address"
                            :label="t('workspace.fields.street')"
                            :error="form.errors.address"
                            :rows="2"
                            autocomplete="street-address"
                            maxlength="500"
                            optional
                        />
                    </div>
                    <Input
                        v-model="form.city"
                        name="city"
                        :label="t('workspace.fields.city')"
                        :error="form.errors.city"
                        autocomplete="address-level2"
                        maxlength="120"
                        optional
                    />
                    <Input
                        v-model="form.postal_code"
                        name="postal_code"
                        :label="t('workspace.fields.postalCode')"
                        :error="form.errors.postal_code"
                        autocomplete="postal-code"
                        maxlength="20"
                        optional
                    />
                    <Select
                        v-model="form.country"
                        name="country"
                        :options="countries"
                        :label="t('workspace.fields.country')"
                        :error="form.errors.country"
                        autocomplete="country"
                        optional
                    />
                </FormSection>

                <FormSection :title="t('workspace.sections.regional.title')" :description="t('workspace.sections.regional.description')" divided>
                    <RegionalFields
                        v-model:locale="form.locale"
                        v-model:timezone="form.timezone"
                        v-model:currency="form.currency"
                        :timezones="timezones"
                        :currencies="currencies"
                        :errors="form.errors"
                    />
                </FormSection>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
                        <p v-if="form.isDirty" class="mr-auto text-[0.8125rem] text-ink-muted">{{ t('workspace.settings.unsaved') }}</p>
                        <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.isDirty">
                            {{ t('common.actions.save') }}
                        </Button>
                    </div>
                </template>
            </Card>
        </form>

        <div class="flex flex-col gap-3">
            <WorkspaceLogoCard :logo-url="workspace.logoUrl" :workspace="workspace.name" />

            <Card :title="t('workspace.settings.url.title')" :description="t('workspace.settings.url.description')">
                <Input
                    :model-value="host"
                    name="workspace_address"
                    :aria-label="t('workspace.settings.url.title')"
                    :hint="t('workspace.settings.url.hint')"
                    control-class="text-ink-muted"
                    mono
                    readonly
                >
                    <template #leading><Lock class="size-3.5" aria-hidden="true" /></template>
                    <template v-if="canCopy" #trailing>
                        <Button icon size="sm" variant="ghost" @click="copyAddress">
                            <template #icon>
                                <Check v-if="copied" class="size-4 text-ok" aria-hidden="true" />
                                <Copy v-else class="size-4" aria-hidden="true" />
                            </template>
                            {{ copied ? t('workspace.settings.url.copied') : t('workspace.settings.url.copy') }}
                        </Button>
                    </template>
                </Input>
                <!-- The button's new label alone is not announced. -->
                <span class="sr-only" role="status">{{ copied ? t('workspace.settings.url.copied') : '' }}</span>
            </Card>
        </div>
    </div>
</template>
