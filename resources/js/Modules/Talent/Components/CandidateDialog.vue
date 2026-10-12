<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { fromMinorUnits, toMinorUnits } from './talentFormat';
import type { CandidateDetail, ConsentBasis } from './types';

/**
 * Adds or edits a candidate. The email is looked up as it is typed (the lookup
 * returns only candidates the person may see); a match asks for an explicit
 * confirmation before a second record with the same email is created.
 */
const props = defineProps<{
    open: boolean;
    candidate: CandidateDetail | null;
    consentBases: ConsentBasis[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const page = usePage();

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    headline: '',
    current_company: '',
    location: '',
    source: '',
    expected_amount: '',
    currency: '',
    summary: '',
    consent_basis: 'candidate_provided' as ConsentBasis,
    retain_until: '',
    confirm_duplicate: false,
});

const matches = ref<{ id: string; name: string; email: string | null }[]>([]);

const consentOptions = computed(() => props.consentBases.map((value) => ({ value, label: t(`talent.status.consent.${value}`) })));
const amountMinor = computed(() => (form.expected_amount.trim() === '' ? null : toMinorUnits(form.expected_amount, form.currency || 'BDT')));
const amountInvalid = computed(() => form.expected_amount.trim() !== '' && amountMinor.value === null);
const needsConfirmation = computed(() => matches.value.length > 0 || form.errors.email !== undefined);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const candidate = props.candidate;
    const currency = candidate?.currency ?? page.props.app.currency;

    form.defaults({
        first_name: candidate?.firstName ?? '',
        last_name: candidate?.lastName ?? '',
        email: candidate?.email ?? '',
        phone: candidate?.phone ?? '',
        headline: candidate?.headline ?? '',
        current_company: candidate?.currentCompany ?? '',
        location: candidate?.location ?? '',
        source: candidate?.source ?? '',
        expected_amount: candidate?.expectedAmount == null ? '' : fromMinorUnits(candidate.expectedAmount, currency),
        currency,
        summary: candidate?.summary ?? '',
        consent_basis: candidate?.consentBasis ?? 'candidate_provided',
        retain_until: candidate?.retainUntil ?? '',
        confirm_duplicate: false,
    });
    form.reset();
    form.clearErrors();
    matches.value = [];
}, { immediate: true });

async function lookup(): Promise<void> {
    const email = form.email.trim().toLowerCase();

    if (email === '' || email === props.candidate?.email) {
        matches.value = [];

        return;
    }

    try {
        const response = await fetch(route('talent.candidates.lookup', { email }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const body = (await response.json()) as { matches: { id: string; name: string; email: string | null }[] };
        matches.value = body.matches.filter((match) => match.id !== props.candidate?.id);
    } catch {
        matches.value = [];
    }
}

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form.transform((data) => ({
        first_name: data.first_name.trim(),
        last_name: blank(data.last_name),
        email: blank(data.email),
        phone: blank(data.phone),
        headline: blank(data.headline),
        current_company: blank(data.current_company),
        location: blank(data.location),
        source: blank(data.source),
        expected_amount: amountMinor.value,
        currency: amountMinor.value === null ? null : data.currency.toUpperCase(),
        summary: blank(data.summary),
        consent_basis: data.consent_basis,
        retain_until: blank(data.retain_until),
        confirm_duplicate: data.confirm_duplicate,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.candidate) {
        form.put(route('talent.candidates.update', { candidate: props.candidate.id }), options);
    } else {
        form.post(route('talent.candidates.store'), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="lg"
        :title="candidate ? t('talent.candidates.edit') : t('talent.candidates.add')"
        :description="t('talent.candidates.formHint')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="form.first_name.trim() === '' || amountInvalid || (needsConfirmation && !form.confirm_duplicate && matches.length > 0)"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.first_name" :label="t('talent.fields.firstName')" maxlength="100" :error="form.errors.first_name" required autofocus />
            <Input v-model="form.last_name" :label="t('talent.fields.lastName')" maxlength="100" optional :error="form.errors.last_name" />
            <Input v-model="form.email" type="email" :label="t('talent.fields.email')" maxlength="190" optional :error="form.errors.email" @blur="lookup" />
            <Input v-model="form.phone" type="tel" :label="t('talent.fields.phone')" maxlength="40" optional :error="form.errors.phone" />
        </div>

        <div v-if="matches.length > 0 || form.errors.email" class="rounded-control border border-line bg-warn-soft px-3 py-3 text-sm" role="status" data-testid="duplicate-warning">
            <p class="text-ink">{{ t('talent.candidates.duplicateTitle') }}</p>
            <ul v-if="matches.length > 0" class="mt-2 grid gap-1">
                <li v-for="match in matches" :key="match.id">
                    <Link :href="route('talent.candidates.show', { candidate: match.id })" class="text-brand-text hover:underline" target="_blank">{{ match.name }}</Link>
                </li>
            </ul>
            <Checkbox v-model="form.confirm_duplicate" :label="t('talent.candidates.duplicateConfirm')" class="mt-3" />
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.headline" :label="t('talent.fields.headline')" maxlength="160" optional :error="form.errors.headline" />
            <Input v-model="form.current_company" :label="t('talent.fields.currentCompany')" maxlength="160" optional :error="form.errors.current_company" />
            <Input v-model="form.location" :label="t('talent.fields.location')" maxlength="160" optional :error="form.errors.location" />
            <Input v-model="form.source" :label="t('talent.fields.source')" maxlength="60" optional :error="form.errors.source" />
            <Input
                v-model="form.expected_amount"
                inputmode="decimal"
                :label="t('talent.fields.expectedAmount')"
                :hint="t('talent.fields.amountHint', { currency: form.currency })"
                optional
                :error="form.errors.expected_amount ?? (amountInvalid ? t('talent.fields.amountInvalid') : undefined)"
            />
            <Input v-model="form.currency" :label="t('talent.fields.currency')" maxlength="3" mono :error="form.errors.currency" />
        </div>

        <Textarea v-model="form.summary" :label="t('talent.fields.summary')" maxlength="5000" optional :error="form.errors.summary" />

        <div class="grid gap-3 sm:grid-cols-2">
            <Select v-model="form.consent_basis" :options="consentOptions" :label="t('talent.fields.consentBasis')" :error="form.errors.consent_basis" />
            <Input v-model="form.retain_until" type="date" :label="t('talent.fields.retainUntil')" :hint="t('talent.fields.retainHint')" optional :error="form.errors.retain_until" />
        </div>
    </TalentFormDialog>
</template>
