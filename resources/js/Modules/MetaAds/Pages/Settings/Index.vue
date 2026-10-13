<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { CeilingRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: { managementEnabled: boolean; lookbackDays: number };
    managementAvailable: boolean;
    bounds: { minLookback: number; maxLookback: number; maxIncreasePercent: number; maxChangesPerDay: number };
    ceilings: CeilingRow[];
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm({
    management_enabled: props.settings.managementEnabled,
    lookback_days: String(props.settings.lookbackDays),
    ceilings: props.ceilings.map((row) => ({ account_id: row.accountId, daily: row.daily, lifetime: row.lifetime })),
});

const days = computed(() => (/^\d{1,3}$/.test(form.lookback_days.trim()) ? Number(form.lookback_days.trim()) : null));
const invalid = computed(() => days.value === null || days.value < props.bounds.minLookback || days.value > props.bounds.maxLookback);

function save(): void {
    form.transform((data) => ({ ...data, lookback_days: days.value })).put(route('meta_ads.settings.update'), { preserveScroll: true });
}

function ceilingError(index: number, field: 'daily' | 'lifetime'): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`ceilings.${index}.${field}`];
}
</script>

<template>
    <PageHeader :eyebrow="t('metaAds.eyebrow')" :title="t('metaAds.settings.title')" :description="t('metaAds.settings.description')" />

    <form class="grid gap-3" novalidate @submit.prevent="save">
        <Card :title="t('metaAds.settings.sync.title')" :description="t('metaAds.settings.sync.description')">
            <Input
                v-model="form.lookback_days"
                inputmode="numeric"
                :label="t('metaAds.settings.sync.lookback')"
                :hint="t('metaAds.settings.sync.lookbackHint', { min: format.number(bounds.minLookback), max: format.number(bounds.maxLookback) })"
                :error="form.errors.lookback_days"
                class="max-w-xs"
            />
        </Card>

        <Card :title="t('metaAds.settings.management.title')" :description="t('metaAds.settings.management.description')">
            <p v-if="!managementAvailable" class="text-[0.8125rem] text-ink-muted" data-testid="management-unavailable">{{ t('metaAds.settings.management.unavailable') }}</p>
            <template v-else>
                <Checkbox v-model="form.management_enabled" :label="t('metaAds.settings.management.enable')" :description="t('metaAds.settings.management.enableHint')" />
                <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-ink-muted">
                    <li>{{ t('metaAds.settings.management.rule1') }}</li>
                    <li>{{ t('metaAds.settings.management.rule2', { percent: format.number(bounds.maxIncreasePercent) }) }}</li>
                    <li>{{ t('metaAds.settings.management.rule3', { count: format.number(bounds.maxChangesPerDay) }) }}</li>
                    <li>{{ t('metaAds.settings.management.rule4') }}</li>
                </ul>
            </template>
        </Card>

        <Card :title="t('metaAds.settings.ceilings.title')" :description="t('metaAds.settings.ceilings.description')">
            <EmptyState v-if="ceilings.length === 0" :title="t('metaAds.settings.ceilings.empty')" :description="t('metaAds.settings.ceilings.emptyBody')" compact />
            <div v-else class="grid gap-4" data-testid="ceilings">
                <fieldset v-for="(row, index) in ceilings" :key="row.accountId" class="grid gap-2 rounded-card border border-line p-4">
                    <legend class="px-1 text-sm font-medium text-ink">{{ row.name }} <span class="text-xs font-normal text-ink-subtle">{{ row.externalId }} · {{ row.currency }}</span></legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Input
                            v-model="form.ceilings[index]!.daily"
                            inputmode="decimal"
                            optional
                            :label="t('metaAds.settings.ceilings.daily', { currency: row.currency })"
                            :error="ceilingError(index, 'daily')"
                        />
                        <Input
                            v-model="form.ceilings[index]!.lifetime"
                            inputmode="decimal"
                            optional
                            :label="t('metaAds.settings.ceilings.lifetime', { currency: row.currency })"
                            :error="ceilingError(index, 'lifetime')"
                        />
                    </div>
                </fieldset>
                <p class="text-xs text-ink-subtle">{{ t('metaAds.settings.ceilings.hint') }}</p>
            </div>
        </Card>

        <div>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="invalid">{{ t('metaAds.settings.save') }}</Button>
        </div>
    </form>
</template>
