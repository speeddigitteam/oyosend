<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import RegionalFields from '@/Core/Components/Settings/RegionalFields.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { countryOptions } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

/**
 * First-run setup of a workspace someone signed up for. It asks once for the
 * few things that make dates, money and documents come out right; the rest of
 * the profile lives on the workspace settings page.
 */
const props = defineProps<{
    workspace: {
        name: string;
        legalName: string | null;
        phone: string | null;
        /** ISO 3166-1 alpha-2, upper case. */
        country: string | null;
        /** Interface language code, one of the keys of `app.locales`. */
        locale: string;
        timezone: string;
        currency: string;
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
    phone: props.workspace.phone ?? '',
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
    form.post(route('core.onboarding.store'));
}
</script>

<template>
    <div>
        <PageHeader
            :eyebrow="t('workspace.onboarding.eyebrow')"
            :title="t('workspace.onboarding.title', { workspace: workspace.name })"
            :description="t('workspace.onboarding.description')"
        />

        <!-- Full width like the platform Settings page: one box per part, side by side on wide screens, one save beneath
             them. Each box lays out its own fields by its own width (container queries), not the screen's. -->
        <form novalidate class="grid gap-3 lg:grid-cols-2" @submit.prevent="submit">
            <Card :title="t('workspace.sections.company.title')" :description="t('workspace.sections.company.description')">
                <div class="@container">
                    <div class="grid content-start gap-4 @lg:grid-cols-2">
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
                        <Select
                            v-model="form.country"
                            name="country"
                            :options="countries"
                            :label="t('workspace.fields.country')"
                            :error="form.errors.country"
                            autocomplete="country"
                            optional
                        />
                    </div>
                </div>
            </Card>

            <Card :title="t('workspace.sections.regional.title')" :description="t('workspace.sections.regional.description')">
                <!-- RegionalFields widens its time zone and preview with `sm:col-span-2`, a screen breakpoint. Stacked with
                     flex (where a span means nothing) until the box itself is wide enough for two columns; from @2xl the
                     screen is always past `sm`, so the spans land in a two-column grid as intended. -->
                <div class="@container">
                    <div class="flex flex-col gap-4 @2xl:grid @2xl:grid-cols-2">
                        <RegionalFields
                            v-model:locale="form.locale"
                            v-model:timezone="form.timezone"
                            v-model:currency="form.currency"
                            :timezones="timezones"
                            :currencies="currencies"
                            :errors="form.errors"
                            suggest-device-timezone
                        />
                    </div>
                </div>
            </Card>

            <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 px-1 lg:col-span-2">
                <p class="mr-auto max-w-md text-[0.8125rem] text-ink-subtle">{{ t('workspace.onboarding.later') }}</p>
                <Button type="submit" variant="primary" :loading="form.processing">{{ t('workspace.onboarding.submit') }}</Button>
            </div>
        </form>
    </div>
</template>
