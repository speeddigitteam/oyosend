<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { fromMinorUnits, toMinorUnits } from '@/Core/lib/minorUnits';
import { route } from '@/Core/lib/route';
import ChannelNotice from '../../Components/ChannelNotice.vue';
import type { ChannelState, SettingsValues } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: SettingsValues;
    senders: { id: string; label: string; value: string }[];
    channel: ChannelState;
    timezone: string;
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({
    daily_cap: String(props.settings.dailyCap),
    throttle_per_minute: String(props.settings.throttlePerMinute),
    opt_out_wording: props.settings.optOutWording ?? '',
    segment_cost: fromMinorUnits(props.settings.segmentCostAmount, props.settings.currency),
    default_sender_id: props.settings.defaultSenderId ?? '',
});

const senderOptions = computed(() => [
    { value: '', label: t('smsMarketing.form.noSender') },
    ...props.senders.map((sender) => ({ value: sender.id, label: `${sender.label} (${sender.value})` })),
]);

function whole(value: string): number | null {
    return /^\d{1,7}$/.test(value.trim()) ? Number(value.trim()) : null;
}

/** A money amount typed in major units as exact integer minor units; null when blank, undefined when malformed. */
function minorUnits(value: string): number | null | undefined {
    if (value.trim() === '') {
        return null;
    }

    return toMinorUnits(value, props.settings.currency) ?? undefined;
}

const cap = computed(() => whole(form.daily_cap));
const rate = computed(() => whole(form.throttle_per_minute));
const cost = computed(() => minorUnits(form.segment_cost));
const [capMin, capMax] = props.settings.bounds.dailyCap as [number, number];
const [rateMin, rateMax] = props.settings.bounds.throttlePerMinute as [number, number];
const capInvalid = computed(() => cap.value === null || cap.value < capMin || cap.value > capMax);
const rateInvalid = computed(() => rate.value === null || rate.value < rateMin || rate.value > rateMax);
const costInvalid = computed(() => cost.value === undefined);
const invalid = computed(() => capInvalid.value || rateInvalid.value || costInvalid.value);

function save(): void {
    form
        .transform((data) => ({
            daily_cap: cap.value,
            throttle_per_minute: rate.value,
            opt_out_wording: data.opt_out_wording.trim() === '' ? null : data.opt_out_wording.trim(),
            segment_cost_amount: cost.value ?? null,
            default_sender_id: data.default_sender_id === '' ? null : data.default_sender_id,
        }))
        .put(route('sms_marketing.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="t('smsMarketing.settings.title')" :description="t('smsMarketing.settings.description')" />

    <ChannelNotice class="mb-3" :channel="channel" />

    <form class="grid max-w-2xl gap-3" novalidate @submit.prevent="save">
        <Card :title="t('smsMarketing.settings.sending')" :description="t('smsMarketing.settings.timezoneNote', { timezone })">
            <div class="grid gap-5">
                <Input
                    v-model="form.daily_cap"
                    inputmode="numeric"
                    :label="t('smsMarketing.settings.dailyCap')"
                    :hint="t('smsMarketing.settings.dailyCapHint', { min: format.number(capMin), max: format.number(capMax) })"
                    :error="form.errors.daily_cap ?? (capInvalid ? t('smsMarketing.settings.outOfRange') : undefined)"
                />
                <Input
                    v-model="form.throttle_per_minute"
                    inputmode="numeric"
                    :label="t('smsMarketing.settings.throttle')"
                    :hint="t('smsMarketing.settings.throttleHint', { min: format.number(rateMin), max: format.number(rateMax) })"
                    :error="form.errors.throttle_per_minute ?? (rateInvalid ? t('smsMarketing.settings.outOfRange') : undefined)"
                />
                <Select
                    v-model="form.default_sender_id"
                    :options="senderOptions"
                    :label="t('smsMarketing.settings.defaultSender')"
                    :error="form.errors.default_sender_id"
                />
            </div>
        </Card>

        <Card :title="t('smsMarketing.settings.messages')">
            <div class="grid gap-5">
                <Input
                    v-model="form.opt_out_wording"
                    :label="t('smsMarketing.settings.optOut')"
                    :hint="t('smsMarketing.settings.optOutHint', { default: settings.optOutDefault })"
                    maxlength="60"
                    optional
                    :error="form.errors.opt_out_wording"
                />
                <Input
                    v-model="form.segment_cost"
                    inputmode="decimal"
                    :label="t('smsMarketing.settings.cost', { currency: settings.currency })"
                    :hint="t('smsMarketing.settings.costHint')"
                    optional
                    :error="(form.errors as Record<string, string | undefined>).segment_cost_amount ?? (costInvalid ? t('smsMarketing.settings.costInvalid') : undefined)"
                />
            </div>
        </Card>

        <div>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="invalid">{{ t('smsMarketing.common.save') }}</Button>
        </div>
    </form>
</template>
