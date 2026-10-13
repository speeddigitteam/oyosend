<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: { requireApproval: boolean; accountHourlyLimit: number; aiDailyCap?: number };
    timezone: string;
    /** Comments analysed by AI today. Token usage is not shown: no public contract exposes it. */
    aiUsedToday?: number;
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({
    require_approval: props.settings.requireApproval,
    account_hourly_limit: String(props.settings.accountHourlyLimit),
    ai_daily_cap: String(props.settings.aiDailyCap ?? 200),
});

const limit = computed(() => (/^\d{1,3}$/.test(form.account_hourly_limit.trim()) ? Number(form.account_hourly_limit.trim()) : null));
const cap = computed(() => (/^\d{1,4}$/.test(form.ai_daily_cap.trim()) ? Number(form.ai_daily_cap.trim()) : null));
const capInvalid = computed(() => cap.value === null || cap.value > 5000);
const invalid = computed(() => limit.value === null || limit.value < 1 || limit.value > 100 || capInvalid.value);

function save(): void {
    form.transform((data) => ({
        require_approval: data.require_approval,
        account_hourly_limit: limit.value,
        ai_daily_cap: cap.value,
    })).put(route('social.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.settings.title')" :description="t('social.settings.description')" />

    <Card :title="t('social.settings.publishing')" :description="t('social.settings.timezoneNote', { timezone })">
        <form class="grid max-w-xl gap-5" novalidate @submit.prevent="save">
            <Checkbox
                v-model="form.require_approval"
                :label="t('social.settings.requireApproval')"
                :description="t('social.settings.requireApprovalHint')"
            />
            <Input
                v-model="form.account_hourly_limit"
                inputmode="numeric"
                :label="t('social.settings.hourlyLimit')"
                :hint="t('social.settings.hourlyLimitHint')"
                :error="form.errors.account_hourly_limit ?? (invalid ? t('social.settings.hourlyLimitInvalid') : undefined)"
            />
            <div class="grid gap-2 border-t border-line pt-4">
                <h3 class="text-sm font-semibold text-ink">{{ t('socialAi.settings.title') }}</h3>
                <p class="text-[0.8125rem] text-ink-muted">{{ t('socialAi.settings.description') }}</p>
                <Input
                    v-model="form.ai_daily_cap"
                    inputmode="numeric"
                    :label="t('socialAi.settings.dailyCap')"
                    :hint="t('socialAi.settings.dailyCapHint')"
                    :error="form.errors.ai_daily_cap ?? (capInvalid ? t('socialAi.settings.dailyCapInvalid') : undefined)"
                />
                <p v-if="aiUsedToday !== undefined" class="tabular text-xs text-ink-subtle" data-testid="ai-used-today">
                    {{ t('socialAi.settings.usedToday', { count: format.number(aiUsedToday) }) }}
                </p>
            </div>
            <div>
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="invalid">{{ t('social.common.save') }}</Button>
            </div>
        </form>
    </Card>
</template>
