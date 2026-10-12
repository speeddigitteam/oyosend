<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: { trackClicks: boolean; sendPerMinute: number; footerAddress: string | null };
    ceiling: number;
    timezone: string;
}>();

const { t } = useI18n();

const form = useForm({
    track_clicks: props.settings.trackClicks,
    send_per_minute: String(props.settings.sendPerMinute),
    footer_address: props.settings.footerAddress ?? '',
});

const rate = computed(() => (/^\d{1,4}$/.test(form.send_per_minute.trim()) ? Number(form.send_per_minute.trim()) : null));
const rateInvalid = computed(() => rate.value === null || rate.value < 1 || rate.value > props.ceiling);

function save(): void {
    form.transform((data) => ({
        track_clicks: data.track_clicks,
        send_per_minute: rate.value,
        footer_address: data.footer_address.trim() === '' ? null : data.footer_address.trim(),
    })).put(route('email_marketing.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.settings.title')" :description="t('emailMarketing.settings.description')" />

    <Card :title="t('emailMarketing.settings.sending')" :description="t('emailMarketing.settings.timezoneNote', { timezone })">
        <form class="grid max-w-xl gap-5" novalidate @submit.prevent="save">
            <Textarea
                v-model="form.footer_address"
                :label="t('emailMarketing.settings.footerAddress')"
                :hint="t('emailMarketing.settings.footerAddressHint')"
                maxlength="500"
                :rows="3"
                :error="form.errors.footer_address"
            />
            <Input
                v-model="form.send_per_minute"
                inputmode="numeric"
                :label="t('emailMarketing.settings.perMinute')"
                :hint="t('emailMarketing.settings.perMinuteHint', { ceiling: ceiling })"
                :error="form.errors.send_per_minute ?? (rateInvalid ? t('emailMarketing.settings.perMinuteInvalid', { ceiling: ceiling }) : undefined)"
            />
            <Checkbox
                v-model="form.track_clicks"
                :label="t('emailMarketing.settings.trackClicks')"
                :description="t('emailMarketing.settings.trackClicksHint')"
            />
            <div>
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="rateInvalid">{{ t('emailMarketing.common.save') }}</Button>
            </div>
        </form>
    </Card>

    <Card class="mt-3" :title="t('emailMarketing.settings.platformTitle')">
        <p class="text-[0.8125rem] text-ink-muted">{{ t('emailMarketing.settings.platformBody') }}</p>
    </Card>
</template>
