<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Account, PaymentMethod } from './types';

/**
 * Where an employee is paid. The full account number is never sent to the
 * browser: only a masked tail is shown. Leaving the number blank keeps the
 * stored one; typing a new one replaces it; "Remove" clears it.
 */
const props = defineProps<{
    profileId: string;
    account: Account;
    methods: PaymentMethod[];
    canManage: boolean;
}>();

const { t } = useI18n();

const form = useForm({
    payment_method: '',
    account_holder: '',
    bank_name: '',
    bank_branch: '',
    routing_number: '',
    account_number: '',
    clear_account_number: false,
});

const methodOptions = computed(() => [
    { value: '', label: t('payroll.common.notSet') },
    ...props.methods.map((value) => ({ value, label: t(`payroll.methods.${value}`) })),
]);

function sync(): void {
    form.defaults({
        payment_method: props.account.paymentMethod ?? '',
        account_holder: props.account.accountHolder ?? '',
        bank_name: props.account.bankName ?? '',
        bank_branch: props.account.bankBranch ?? '',
        routing_number: props.account.routingNumber ?? '',
        account_number: '',
        clear_account_number: false,
    });
    form.reset();
}

watch(() => props.account, sync, { immediate: true });

function save(): void {
    form.transform((data) => ({
        payment_method: data.payment_method === '' ? null : data.payment_method,
        account_holder: data.account_holder.trim() === '' ? null : data.account_holder.trim(),
        bank_name: data.bank_name.trim() === '' ? null : data.bank_name.trim(),
        bank_branch: data.bank_branch.trim() === '' ? null : data.bank_branch.trim(),
        routing_number: data.routing_number.trim() === '' ? null : data.routing_number.trim(),
        account_number: data.account_number.trim() === '' ? null : data.account_number.trim(),
        clear_account_number: data.clear_account_number,
    })).put(route('payroll.salaries.account.update', { profile: props.profileId }), {
        preserveScroll: true,
        onSuccess: () => sync(),
    });
}
</script>

<template>
    <Card :title="t('payroll.salaries.accountTitle')" :description="t('payroll.salaries.accountBody')" data-testid="account-card">
        <dl v-if="!canManage" class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="eyebrow">{{ t('payroll.fields.method') }}</dt><dd class="mt-0.5 text-ink">{{ account.paymentMethod ? t(`payroll.methods.${account.paymentMethod}`) : t('payroll.common.notSet') }}</dd></div>
            <div><dt class="eyebrow">{{ t('payroll.fields.accountHolder') }}</dt><dd class="mt-0.5 text-ink">{{ account.accountHolder ?? t('payroll.common.notSet') }}</dd></div>
            <div><dt class="eyebrow">{{ t('payroll.fields.bankName') }}</dt><dd class="mt-0.5 text-ink">{{ account.bankName ?? t('payroll.common.notSet') }}</dd></div>
            <div><dt class="eyebrow">{{ t('payroll.fields.bankBranch') }}</dt><dd class="mt-0.5 text-ink">{{ account.bankBranch ?? t('payroll.common.notSet') }}</dd></div>
            <div><dt class="eyebrow">{{ t('payroll.fields.routingNumber') }}</dt><dd class="mt-0.5 font-mono text-ink">{{ account.routingNumber ?? t('payroll.common.notSet') }}</dd></div>
            <div><dt class="eyebrow">{{ t('payroll.fields.accountNumber') }}</dt><dd class="mt-0.5 font-mono text-ink" data-testid="account-masked">{{ account.accountNumberMasked ?? t('payroll.common.notSet') }}</dd></div>
        </dl>

        <form v-else class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <Select v-model="form.payment_method" :options="methodOptions" :label="t('payroll.fields.method')" :error="form.errors.payment_method" />
            <Input v-model="form.account_holder" :label="t('payroll.fields.accountHolder')" :error="form.errors.account_holder" maxlength="160" optional />
            <Input v-model="form.bank_name" :label="t('payroll.fields.bankName')" :error="form.errors.bank_name" maxlength="160" optional />
            <Input v-model="form.bank_branch" :label="t('payroll.fields.bankBranch')" :error="form.errors.bank_branch" maxlength="160" optional />
            <Input v-model="form.routing_number" :label="t('payroll.fields.routingNumber')" :error="form.errors.routing_number" maxlength="40" mono optional />
            <div class="grid gap-2">
                <Input
                    v-model="form.account_number"
                    :label="t('payroll.fields.accountNumber')"
                    :hint="account.hasAccountNumber ? t('payroll.salaries.accountKeep', { masked: account.accountNumberMasked ?? '' }) : undefined"
                    :error="form.errors.account_number"
                    :disabled="form.clear_account_number"
                    maxlength="60"
                    autocomplete="off"
                    mono
                    optional
                />
                <Checkbox
                    v-if="account.hasAccountNumber"
                    v-model="form.clear_account_number"
                    :label="t('payroll.salaries.accountClear')"
                />
            </div>
            <div class="sm:col-span-2">
                <Button type="submit" variant="primary" :loading="form.processing">{{ t('payroll.common.saveChanges') }}</Button>
            </div>
        </form>
    </Card>
</template>
