<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { formatDate, formatMoney, type FormatContext } from '@/Core/lib/format';

/**
 * Language, time zone and currency of a workspace, with a preview that follows
 * the three controls before anything is saved. Used by the settings page and
 * by first-run setup; it renders bare fields, so it goes inside a FormSection.
 */
const props = defineProps<{
    /** IANA identifiers, as the server validates them. */
    timezones: string[];
    /** ISO 4217 codes a workspace may choose. */
    currencies: string[];
    errors: Partial<Record<'locale' | 'timezone' | 'currency', string>>;
    /** Offer the time zone of this device when it differs from the selected one. */
    suggestDeviceTimezone?: boolean;
}>();

const locale = defineModel<string>('locale', { required: true });
const timezone = defineModel<string>('timezone', { required: true });
const currency = defineModel<string>('currency', { required: true });

const page = usePage();
const { t } = useI18n();

const languages = computed<SelectOption[]>(() =>
    Object.entries(page.props.app.locales).map(([value, label]) => ({ value, label })),
);

// Identifiers stay as they are - they are what people search for - minus the underscores.
const zoneLabel = (zone: string): string => zone.replace(/_/g, ' ');

const zones = computed<SelectOption[]>(() => {
    // A value saved before the list changed must still be shown as selected.
    const list = props.timezones.includes(timezone.value) ? props.timezones : [timezone.value, ...props.timezones];

    return list.map((zone) => ({ value: zone, label: zoneLabel(zone) }));
});

function currencyName(code: string): string {
    try {
        return new Intl.DisplayNames([page.props.app.locale], { type: 'currency' }).of(code) ?? code;
    } catch {
        return code;
    }
}

const currencyOptions = computed<SelectOption[]>(() => {
    const list = props.currencies.includes(currency.value) ? props.currencies : [currency.value, ...props.currencies];

    return list.map((code) => {
        const name = currencyName(code);

        return { value: code, label: name === code ? code : `${code} · ${name}` };
    });
});

/*
 * The preview is written the way this person reads everything else (their
 * language and numerals); only the time zone and the currency come from the form.
 */
const SAMPLE_MINOR_UNITS = 123_450;
const now = ref(new Date());
let clock: number | undefined;

onMounted(() => {
    clock = window.setInterval(() => {
        now.value = new Date();
    }, 30_000);
});

onBeforeUnmount(() => window.clearInterval(clock));

const preview = computed(() => {
    const context: FormatContext = {
        locale: page.props.app.locale,
        numerals: page.props.app.numerals,
        timezone: timezone.value,
        currency: currency.value,
    };

    // A zone or currency this browser does not know must not take the form down.
    try {
        return { time: formatDate(now.value, context, 'datetime'), money: formatMoney(SAMPLE_MINOR_UNITS, context) };
    } catch {
        return null;
    }
});

const deviceTimezone = computed(() => {
    if (!props.suggestDeviceTimezone) {
        return null;
    }

    try {
        const zone = new Intl.DateTimeFormat().resolvedOptions().timeZone;

        return zone !== timezone.value && props.timezones.includes(zone) ? zone : null;
    } catch {
        return null;
    }
});

function adoptDeviceTimezone(): void {
    if (deviceTimezone.value) {
        timezone.value = deviceTimezone.value;
    }
}
</script>

<template>
    <Select
        v-model="locale"
        name="locale"
        :options="languages"
        :label="t('workspace.fields.language')"
        :hint="t('workspace.fields.languageHint')"
        :error="errors.locale"
    />

    <Select
        v-model="currency"
        name="currency"
        :options="currencyOptions"
        :label="t('workspace.fields.currency')"
        :error="errors.currency"
    />

    <div class="flex flex-col gap-1.5 sm:col-span-2">
        <Select
            v-model="timezone"
            name="timezone"
            :options="zones"
            :label="t('workspace.fields.timezone')"
            :error="errors.timezone"
        />

        <p v-if="deviceTimezone" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.8125rem] text-ink-subtle">
            {{ t('workspace.onboarding.deviceTimezone', { timezone: zoneLabel(deviceTimezone) }) }}
            <Button size="sm" variant="ghost" @click="adoptDeviceTimezone">{{ t('workspace.onboarding.useDeviceTimezone') }}</Button>
        </p>
    </div>

    <div v-if="preview" class="glass-well rounded-control px-4 py-3.5 sm:col-span-2">
        <p class="eyebrow">{{ t('workspace.fields.preview.title') }}</p>

        <!-- Not a live region: the clock ticks on its own and would be read out every time it does. -->
        <dl class="mt-3 grid gap-x-8 gap-y-3 sm:grid-cols-2">
            <div>
                <dt class="text-[0.8125rem] text-ink-subtle">{{ t('workspace.fields.preview.time') }}</dt>
                <dd class="tabular mt-0.5 text-[0.9375rem] font-medium text-ink">{{ preview.time }}</dd>
            </div>
            <div>
                <dt class="text-[0.8125rem] text-ink-subtle">{{ t('workspace.fields.preview.money') }}</dt>
                <dd class="tabular mt-0.5 text-[0.9375rem] font-medium text-ink">{{ preview.money }}</dd>
            </div>
        </dl>
    </div>
</template>
