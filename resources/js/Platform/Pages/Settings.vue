<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CreditCard } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import { toWholeNumber } from '@/Platform/Components/Plans/amount';

defineOptions({ layout: PlatformLayout });

/** Values of App\Core\Settings\Enums\RegistrationMode. */
type RegistrationMode = 'open' | 'approval' | 'closed';

/** A payment gateway's status. Secrets are never sent to the page. */
type Gateway = {
    key: string;
    name: string;
    /** The secret fields the gateway needs, in display order. */
    fields: string[];
    configured: boolean;
    /** True when the operator set credentials in the panel (rather than the environment). */
    managed: boolean;
    source: 'panel' | 'environment' | 'none';
    /** Fields that already hold a stored secret; their inputs may be left blank to keep them. */
    storedFields: string[];
    sandbox: boolean;
    enabled: boolean;
    updatedAt: string | null;
};

const props = defineProps<{
    settings: {
        registrationMode: RegistrationMode;
        /** Days a workspace keeps working after a payment was due. */
        graceDays: number;
        /** Days before a period or a trial ends that the next invoice is issued. */
        invoiceLeadDays: number;
        /** Shown on unpaid invoices; null when nothing is shown. */
        offlineInstructions: string | null;
        /** Prefix on platform invoice numbers, e.g. "INV". */
        invoicePrefix: string;
        issuerName: string | null;
        issuerAddress: string | null;
        issuerEmail: string | null;
        issuerTaxId: string | null;
    };
    gateways: Gateway[];
}>();

const { t } = useI18n();

const MODES: RegistrationMode[] = ['open', 'approval', 'closed'];

// The counts are text while they are edited and become whole numbers when the form is sent.
const form = useForm({
    registration_mode: props.settings.registrationMode,
    grace_days: String(props.settings.graceDays),
    invoice_lead_days: String(props.settings.invoiceLeadDays),
    offline_instructions: props.settings.offlineInstructions ?? '',
    invoice_prefix: props.settings.invoicePrefix,
    issuer_name: props.settings.issuerName ?? '',
    issuer_address: props.settings.issuerAddress ?? '',
    issuer_email: props.settings.issuerEmail ?? '',
    issuer_tax_id: props.settings.issuerTaxId ?? '',
});

const modeOptions = computed<Segment<RegistrationMode>[]>(() =>
    MODES.map((mode) => ({ value: mode, label: t(`platformSettings.registration.modes.${mode}.label`) })),
);

type GatewayForm = { sandbox: boolean; enabled: boolean; secrets: Record<string, string> };

// One write-only credential form per gateway. Secret fields start blank: a blank field keeps the
// stored value, so the operator can change the sandbox flag without re-typing the password.
const gatewayForms: Record<string, ReturnType<typeof useForm<GatewayForm>>> = {};
props.gateways.forEach((gateway) => {
    gatewayForms[gateway.key] = useForm<GatewayForm>({
        sandbox: gateway.sandbox,
        enabled: gateway.enabled,
        secrets: Object.fromEntries(gateway.fields.map((field) => [field, ''])),
    });
});

function fieldLabel(field: string): string {
    return t(`platformSettings.gateways.fields.${field}`);
}

function fieldPlaceholder(gateway: Gateway, field: string): string | undefined {
    // Already stored: the operator may leave it blank to keep it.
    return gateway.storedFields.includes(field) ? t('platformSettings.gateways.secretStored') : undefined;
}

function submit(): void {
    form.transform((data) => ({
        registration_mode: data.registration_mode,
        grace_days: toWholeNumber(data.grace_days),
        invoice_lead_days: toWholeNumber(data.invoice_lead_days),
        offline_instructions: data.offline_instructions.trim() === '' ? null : data.offline_instructions,
        invoice_prefix: data.invoice_prefix.trim(),
        issuer_name: data.issuer_name.trim() === '' ? null : data.issuer_name.trim(),
        issuer_address: data.issuer_address.trim() === '' ? null : data.issuer_address.trim(),
        issuer_email: data.issuer_email.trim() === '' ? null : data.issuer_email.trim(),
        issuer_tax_id: data.issuer_tax_id.trim() === '' ? null : data.issuer_tax_id.trim(),
    })).put(route('platform.settings.update'), {
        preserveScroll: true,
        // What was saved is the new starting point: nothing is "unsaved" any more.
        onSuccess: () => form.defaults(),
    });
}

function saveGateway(gateway: Gateway): void {
    gatewayForms[gateway.key].put(route('platform.settings.gateways.update', gateway.key), {
        preserveScroll: true,
        onSuccess: () => {
            // Clear the secret inputs again; they were write-only.
            gateway.fields.forEach((field) => {
                gatewayForms[gateway.key].secrets[field] = '';
            });
            gatewayForms[gateway.key].defaults();
        },
    });
}

function clearGateway(gateway: Gateway): void {
    router.delete(route('platform.settings.gateways.clear', gateway.key), { preserveScroll: true });
}
</script>

<template>
    <div>
        <PageHeader
            :eyebrow="t('shell.nav.sections.platform')"
            :title="t('shell.nav.platformSettings')"
            :description="t('platformSettings.description')"
        />

        <div class="flex flex-col gap-3">
            <!-- The page uses the full width like the dashboard: one box per part, side by side on wide screens.
                 Each box lays out its own fields by its own width (container queries), not the screen's. -->
            <form novalidate class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3" @submit.prevent="submit">
                <Card :title="t('platformSettings.registration.title')" :description="t('platformSettings.registration.description')">
                    <div class="@container flex flex-col gap-4">
                        <Field :label="t('platformSettings.registration.mode')" :error="form.errors.registration_mode">
                            <div>
                                <SegmentedControl
                                    v-model="form.registration_mode"
                                    :options="modeOptions"
                                    :label="t('platformSettings.registration.mode')"
                                />
                            </div>
                        </Field>

                        <!-- All three are spelt out; the one that is chosen is the one that stands out. -->
                        <dl class="glass-well flex flex-col gap-2.5 rounded-control px-4 py-3.5" aria-live="polite">
                            <div
                                v-for="mode in MODES"
                                :key="mode"
                                class="grid gap-x-4 gap-y-0.5 text-[0.8125rem] @sm:grid-cols-[minmax(0,6rem)_minmax(0,1fr)]"
                            >
                                <dt :class="form.registration_mode === mode ? 'font-medium text-ink' : 'text-ink-subtle'">
                                    {{ t(`platformSettings.registration.modes.${mode}.label`) }}
                                </dt>
                                <dd :class="form.registration_mode === mode ? 'text-ink' : 'text-ink-subtle'">
                                    {{ t(`platformSettings.registration.modes.${mode}.description`) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <Card :title="t('platformSettings.billing.title')" :description="t('platformSettings.billing.description')">
                    <div class="@container flex flex-col gap-4">
                        <div class="grid content-start gap-4 @lg:grid-cols-2">
                            <Input
                                v-model="form.grace_days"
                                name="grace_days"
                                inputmode="numeric"
                                autocomplete="off"
                                :label="t('platformSettings.billing.graceDays')"
                                :hint="t('platformSettings.billing.graceDaysHint')"
                                :error="form.errors.grace_days"
                                maxlength="2"
                                required
                            />
                            <Input
                                v-model="form.invoice_lead_days"
                                name="invoice_lead_days"
                                inputmode="numeric"
                                autocomplete="off"
                                :label="t('platformSettings.billing.invoiceLeadDays')"
                                :hint="t('platformSettings.billing.invoiceLeadDaysHint')"
                                :error="form.errors.invoice_lead_days"
                                maxlength="2"
                                required
                            />
                        </div>

                        <Textarea
                            v-model="form.offline_instructions"
                            name="offline_instructions"
                            :label="t('platformSettings.billing.offlineInstructions')"
                            :hint="t('platformSettings.billing.offlineInstructionsHint')"
                            :error="form.errors.offline_instructions"
                            :rows="4"
                            maxlength="2000"
                            optional
                        />
                    </div>
                </Card>

                <Card
                    class="lg:col-span-2 xl:col-span-1"
                    :title="t('platformSettings.issuer.title')"
                    :description="t('platformSettings.issuer.description')"
                >
                    <div class="@container flex flex-col gap-4">
                        <div class="grid content-start gap-4 @lg:grid-cols-2">
                            <Input
                                v-model="form.invoice_prefix"
                                name="invoice_prefix"
                                autocomplete="off"
                                :label="t('platformSettings.issuer.invoicePrefix')"
                                :hint="t('platformSettings.issuer.invoicePrefixHint')"
                                :error="form.errors.invoice_prefix"
                                maxlength="20"
                                required
                            />
                            <Input
                                v-model="form.issuer_name"
                                name="issuer_name"
                                autocomplete="off"
                                :label="t('platformSettings.issuer.name')"
                                :error="form.errors.issuer_name"
                                maxlength="150"
                                optional
                            />
                        </div>

                        <Textarea
                            v-model="form.issuer_address"
                            name="issuer_address"
                            :label="t('platformSettings.issuer.address')"
                            :error="form.errors.issuer_address"
                            :rows="3"
                            maxlength="500"
                            optional
                        />

                        <div class="grid content-start gap-4 @lg:grid-cols-2">
                            <Input
                                v-model="form.issuer_email"
                                name="issuer_email"
                                type="email"
                                autocomplete="off"
                                :label="t('platformSettings.issuer.email')"
                                :error="form.errors.issuer_email"
                                maxlength="150"
                                optional
                            />
                            <Input
                                v-model="form.issuer_tax_id"
                                name="issuer_tax_id"
                                autocomplete="off"
                                :label="t('platformSettings.issuer.taxId')"
                                :error="form.errors.issuer_tax_id"
                                maxlength="60"
                                optional
                            />
                        </div>
                    </div>
                </Card>

                <!-- One save for the three boxes above. -->
                <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 px-1 lg:col-span-2 xl:col-span-3">
                    <p v-if="form.isDirty" class="mr-auto text-[0.8125rem] text-ink-muted">{{ t('platformSettings.unsaved') }}</p>
                    <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.isDirty">
                        {{ t('common.actions.save') }}
                    </Button>
                </div>
            </form>

            <Card :title="t('platformSettings.gateways.title')" :description="t('platformSettings.gateways.description')">
                <EmptyState
                    v-if="gateways.length === 0"
                    :title="t('platformSettings.gateways.none')"
                    :description="t('platformSettings.gateways.noneBody')"
                    compact
                >
                    <template #icon><CreditCard /></template>
                </EmptyState>

                <!-- Each gateway is a box of its own, like the messaging accounts. -->
                <ul v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="gateway in gateways" :key="gateway.key" class="glass-well grid gap-4 rounded-control p-4" data-testid="gateway">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text">
                                    <CreditCard class="size-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium wrap-break-word text-ink">{{ gateway.name }}</p>
                                    <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">
                                        {{ t(`platformSettings.gateways.source.${gateway.source}`) }}
                                    </p>
                                </div>
                            </div>
                            <Badge class="shrink-0" :tone="gateway.configured ? 'ok' : 'neutral'" dot>
                                {{ gateway.configured ? t('platformSettings.gateways.configured') : t('platformSettings.gateways.notConfigured') }}
                            </Badge>
                        </div>

                        <form novalidate class="flex flex-col gap-4 border-t border-line pt-4" @submit.prevent="saveGateway(gateway)">
                            <div class="grid content-start gap-4">
                                <Field
                                    v-for="field in gateway.fields"
                                    :key="field"
                                    :label="fieldLabel(field)"
                                    :error="gatewayForms[gateway.key].errors[`secrets.${field}` as keyof GatewayForm]"
                                >
                                    <Input
                                        v-model="gatewayForms[gateway.key].secrets[field]"
                                        :name="`${gateway.key}.${field}`"
                                        type="password"
                                        autocomplete="new-password"
                                        :placeholder="fieldPlaceholder(gateway, field)"
                                    />
                                </Field>
                            </div>

                            <div class="flex flex-col gap-2.5">
                                <Checkbox v-model="gatewayForms[gateway.key].sandbox" :label="t('platformSettings.gateways.sandbox')" />
                                <Checkbox v-model="gatewayForms[gateway.key].enabled" :label="t('platformSettings.gateways.enabled')" />
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                                <Button
                                    v-if="gateway.managed"
                                    type="button"
                                    variant="ghost"
                                    @click="clearGateway(gateway)"
                                >
                                    {{ t('platformSettings.gateways.clear') }}
                                </Button>
                                <Button type="submit" variant="primary" :loading="gatewayForms[gateway.key].processing">
                                    {{ t('platformSettings.gateways.save') }}
                                </Button>
                            </div>
                        </form>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
