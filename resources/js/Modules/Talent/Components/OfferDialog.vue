<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { fromMinorUnits, toMinorUnits } from './talentFormat';
import type { Offer } from './types';

/**
 * Creates or edits an offer. An offer is tracking only: no contract is
 * generated or signed. The amount is typed as an exact decimal and sent as
 * integer minor units.
 */
const props = defineProps<{ open: boolean; applicationId: string; offer: Offer | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const page = usePage();

const form = useForm({ amount: '', currency: '', proposed_start_on: '', expires_on: '', notes: '' });

const minor = computed(() => (form.amount.trim() === '' ? null : toMinorUnits(form.amount, form.currency || 'BDT')));
const invalid = computed(() => form.amount.trim() !== '' && minor.value === null);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const currency = props.offer?.currency ?? page.props.app.currency;

    form.defaults({
        amount: props.offer?.amount == null ? '' : fromMinorUnits(props.offer.amount, currency),
        currency,
        proposed_start_on: props.offer?.proposedStartOn ?? '',
        expires_on: props.offer?.expiresOn ?? '',
        notes: props.offer?.notes ?? '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        amount: minor.value,
        currency: minor.value === null ? null : data.currency.toUpperCase(),
        proposed_start_on: data.proposed_start_on === '' ? null : data.proposed_start_on,
        expires_on: data.expires_on === '' ? null : data.expires_on,
        notes: data.notes.trim() === '' ? null : data.notes.trim(),
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.offer) {
        form.put(route('talent.offers.update', { offer: props.offer.id }), options);
    } else {
        form.post(route('talent.applications.offers.store', { application: props.applicationId }), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="offer ? t('talent.offers.edit') : t('talent.offers.add')"
        :description="t('talent.offers.hint')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="invalid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <Input
                v-model="form.amount"
                inputmode="decimal"
                :label="t('talent.offers.amount')"
                :hint="t('talent.fields.amountHint', { currency: form.currency })"
                optional
                :error="form.errors.amount ?? (invalid ? t('talent.fields.amountInvalid') : undefined)"
            />
            <Input v-model="form.currency" :label="t('talent.fields.currency')" maxlength="3" mono :error="form.errors.currency" />
            <Input v-model="form.proposed_start_on" type="date" :label="t('talent.offers.proposedStart')" optional :error="form.errors.proposed_start_on" />
            <Input v-model="form.expires_on" type="date" :label="t('talent.offers.expires')" optional :error="form.errors.expires_on" />
        </div>
        <Textarea v-model="form.notes" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.notes" />
    </TalentFormDialog>
</template>
