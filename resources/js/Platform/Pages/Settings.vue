<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CreditCard } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import FormRule from '@/Platform/Components/FormRule.vue';
import FormSection from '@/Platform/Components/FormSection.vue';
import { toWholeNumber } from '@/Platform/Components/Plans/amount';

defineOptions({ layout: PlatformLayout });

/** Values of App\Core\Settings\Enums\RegistrationMode. */
type RegistrationMode = 'open' | 'approval' | 'closed';

const props = defineProps<{
    settings: {
        registrationMode: RegistrationMode;
        /** Days a workspace keeps working after a payment was due. */
        graceDays: number;
        /** Days before a period or a trial ends that the next invoice is issued. */
        invoiceLeadDays: number;
        /** Shown on unpaid invoices; null when nothing is shown. */
        offlineInstructions: string | null;
    };
    /** The gateways of this build. Whether one has credentials is all the page is told about them. */
    gateways: { key: string; name: string; configured: boolean }[];
}>();

const { t } = useI18n();

const MODES: RegistrationMode[] = ['open', 'approval', 'closed'];

// The counts are text while they are edited and become whole numbers when the form is sent.
const form = useForm({
    registration_mode: props.settings.registrationMode,
    grace_days: String(props.settings.graceDays),
    invoice_lead_days: String(props.settings.invoiceLeadDays),
    offline_instructions: props.settings.offlineInstructions ?? '',
});

const modeOptions = computed<Segment<RegistrationMode>[]>(() =>
    MODES.map((mode) => ({ value: mode, label: t(`platformSettings.registration.modes.${mode}.label`) })),
);

function submit(): void {
    form.transform((data) => ({
        registration_mode: data.registration_mode,
        grace_days: toWholeNumber(data.grace_days),
        invoice_lead_days: toWholeNumber(data.invoice_lead_days),
        offline_instructions: data.offline_instructions.trim() === '' ? null : data.offline_instructions,
    })).put(route('platform.settings.update'), {
        preserveScroll: true,
        // What was saved is the new starting point: nothing is "unsaved" any more.
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader
            :eyebrow="t('shell.nav.sections.platform')"
            :title="t('shell.nav.platformSettings')"
            :description="t('platformSettings.description')"
        />

        <div class="flex flex-col gap-3">
            <form novalidate @submit.prevent="submit">
                <Card>
                    <FormSection :title="t('platformSettings.registration.title')" :description="t('platformSettings.registration.description')">
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
                        <dl class="glass-well mt-4 flex flex-col gap-2.5 rounded-control px-4 py-3.5" aria-live="polite">
                            <div
                                v-for="mode in MODES"
                                :key="mode"
                                class="grid gap-x-4 gap-y-0.5 text-[0.8125rem] sm:grid-cols-[minmax(0,7rem)_minmax(0,1fr)]"
                            >
                                <dt :class="form.registration_mode === mode ? 'font-medium text-ink' : 'text-ink-subtle'">
                                    {{ t(`platformSettings.registration.modes.${mode}.label`) }}
                                </dt>
                                <dd :class="form.registration_mode === mode ? 'text-ink' : 'text-ink-subtle'">
                                    {{ t(`platformSettings.registration.modes.${mode}.description`) }}
                                </dd>
                            </div>
                        </dl>
                    </FormSection>

                    <FormRule />

                    <FormSection :title="t('platformSettings.billing.title')" :description="t('platformSettings.billing.description')">
                        <div class="flex flex-col gap-4">
                            <div class="grid content-start gap-4 sm:grid-cols-2">
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
                                :rows="5"
                                maxlength="2000"
                                optional
                            />
                        </div>
                    </FormSection>

                    <template #footer>
                        <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
                            <p v-if="form.isDirty" class="mr-auto text-[0.8125rem] text-ink-muted">{{ t('platformSettings.unsaved') }}</p>
                            <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.isDirty">
                                {{ t('common.actions.save') }}
                            </Button>
                        </div>
                    </template>
                </Card>
            </form>

            <Card :title="t('platformSettings.gateways.title')" :description="t('platformSettings.gateways.description')" flush>
                <EmptyState
                    v-if="gateways.length === 0"
                    :title="t('platformSettings.gateways.none')"
                    :description="t('platformSettings.gateways.noneBody')"
                    compact
                >
                    <template #icon><CreditCard /></template>
                </EmptyState>

                <ul v-else>
                    <li
                        v-for="gateway in gateways"
                        :key="gateway.key"
                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 border-t border-line px-5 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium wrap-break-word text-ink">{{ gateway.name }}</p>
                            <p class="text-[0.8125rem] text-ink-subtle">
                                {{ gateway.configured ? t('platformSettings.gateways.configuredHint') : t('platformSettings.gateways.notConfiguredHint') }}
                            </p>
                        </div>
                        <Badge :tone="gateway.configured ? 'ok' : 'neutral'" dot>
                            {{ gateway.configured ? t('platformSettings.gateways.configured') : t('platformSettings.gateways.notConfigured') }}
                        </Badge>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
