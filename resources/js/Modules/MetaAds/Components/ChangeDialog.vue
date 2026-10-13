<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { minorToDecimal, newRequestId } from './metaAdsFormat';
import type { ObjectRow } from './types';

export type ChangeMode = 'pause' | 'resume' | 'budget';

/**
 * Confirms one change to Meta and shows exactly what will happen: the item, the current value and the new one.
 * Nothing is sent until the person presses the confirm button; every opening has its own request id, so
 * a double click or a retry can never apply the same change twice.
 */
const props = defineProps<{ object: ObjectRow | null; mode: ChangeMode; maxIncreasePercent: number }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const format = useFormat();

const form = useForm({ action: 'pause', expected: '', amount: '', request_id: '' });

const isBudget = computed(() => props.mode === 'budget');
const current = computed(() => (props.object?.budgetField === 'lifetime_budget' ? props.object.lifetimeBudget : props.object?.dailyBudget) ?? null);

watch(
    () => [open.value, props.object?.id, props.mode] as const,
    ([isOpen]) => {
        if (!isOpen || !props.object) {
            return;
        }

        form.clearErrors();
        form.request_id = newRequestId();

        if (props.mode === 'budget') {
            form.expected = String(current.value ?? '');
            form.amount = current.value === null ? '' : minorToDecimal(current.value, props.object.currency);
        } else {
            form.action = props.mode;
            form.expected = props.mode === 'pause' ? 'ACTIVE' : 'PAUSED';
        }
    },
    { immediate: true },
);

const typed = computed(() => /^\d{1,15}([.,]\d{1,3})?$/.test(form.amount.trim().replace(/,/g, '')) ? form.amount.trim() : null);

// The server reports a refused change under `change` or `budget`, which are not form fields.
const errors = computed(() => form.errors as Record<string, string | undefined>);
const error = computed(() => errors.value.change ?? errors.value.action ?? errors.value.request_id ?? errors.value.expected ?? null);

function submit(): void {
    if (!props.object || form.processing) {
        return;
    }

    const name = isBudget.value ? 'meta_ads.campaigns.budget' : 'meta_ads.campaigns.status';
    const data = isBudget.value
        ? { amount: form.amount.trim(), expected: form.expected, request_id: form.request_id }
        : { action: form.action, expected: form.expected, request_id: form.request_id };

    form.transform(() => data).post(route(name, { object: props.object.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t(`metaAds.change.${mode}.title`)" :description="t(`metaAds.change.${mode}.description`)" size="sm" :persistent="form.processing">
        <div v-if="object" class="grid gap-4" data-testid="change-dialog">
            <dl class="grid gap-2 rounded-card bg-hover px-4 py-3 text-[0.8125rem]">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-muted">{{ t(`metaAds.levels.${object.level}`) }}</dt>
                    <dd class="min-w-0 truncate font-medium text-ink">{{ object.name }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-muted">{{ t('metaAds.fields.account') }}</dt>
                    <dd class="min-w-0 truncate text-ink">{{ object.accountName }}</dd>
                </div>
            </dl>

            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3" data-testid="before-after">
                <div>
                    <p class="eyebrow">{{ t('metaAds.change.before') }}</p>
                    <p class="figure mt-1 text-lg text-ink" data-testid="before">
                        <template v-if="isBudget">{{ current === null ? t('metaAds.notReported') : format.money(current, object.currency) }}</template>
                        <template v-else>{{ t(`metaAds.status.${mode === 'pause' ? 'ACTIVE' : 'PAUSED'}`) }}</template>
                    </p>
                </div>
                <ArrowRight class="size-4 text-ink-subtle" aria-hidden="true" />
                <div>
                    <p class="eyebrow">{{ t('metaAds.change.after') }}</p>
                    <p class="figure mt-1 text-lg text-brand-text" data-testid="after">
                        <template v-if="isBudget">{{ typed === null ? t('metaAds.notReported') : `${object.currency} ${typed}` }}</template>
                        <template v-else>{{ t(`metaAds.status.${mode === 'pause' ? 'PAUSED' : 'ACTIVE'}`) }}</template>
                    </p>
                </div>
            </div>

            <template v-if="isBudget">
                <Input
                    v-model="form.amount"
                    inputmode="decimal"
                    autocomplete="off"
                    :label="t(`metaAds.change.budget.${object.budgetField === 'lifetime_budget' ? 'lifetimeLabel' : 'dailyLabel'}`, { currency: object.currency })"
                    :hint="t('metaAds.change.budget.limits', {
                        ceiling: object.ceiling === null ? '' : format.money(object.ceiling, object.currency),
                        percent: format.number(maxIncreasePercent),
                    })"
                    :error="errors.budget ?? errors.amount"
                />
            </template>

            <p class="text-xs text-ink-muted">{{ t('metaAds.change.audited') }}</p>

            <p v-if="error" class="rounded-card bg-danger-soft px-3 py-2 text-[0.8125rem] text-danger" role="alert">{{ error }}</p>
        </div>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" :disabled="isBudget && typed === null" @click="submit">
                {{ t(`metaAds.change.${mode}.confirm`) }}
            </Button>
        </template>
    </Modal>
</template>
