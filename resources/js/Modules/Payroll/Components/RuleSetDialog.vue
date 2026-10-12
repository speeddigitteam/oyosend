<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import MoneyInput from './MoneyInput.vue';
import PayrollFormDialog from './PayrollFormDialog.vue';
import PercentInput from './PercentInput.vue';
import { slabPayload, slabProblem, type SlabDraft } from './slabs';
import type { RuleOptions, RuleSet } from './types';

/**
 * Create or edit a rule set: a named table of slabs the workspace enters. Every
 * number is the workspace's own; Oyosend ships no tax or contribution law and
 * this is not legal advice.
 */
const props = defineProps<{
    open: boolean;
    ruleSet: RuleSet | null;
    options: RuleOptions;
    currency: string;
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const form = useForm({
    name: '',
    code: '',
    country: '',
    engine: 'slab',
    category: 'income_tax',
    payer: 'employee',
    basis: 'taxable_gross',
    method: 'progressive',
    annualize: false,
    periods_per_year: '12',
    min_amount: null as number | null,
    max_amount: null as number | null,
    employment_types: [] as string[],
    effective_from: '',
    effective_to: '',
    is_active: true,
    notes: '',
});

let counter = 0;
const slabs = ref<SlabDraft[]>([]);
const problem = computed(() => slabProblem(slabs.value));
const periods = computed(() => Number(form.periods_per_year));
const unusable = computed(() =>
    form.name.trim() === '' || form.code.trim() === '' || form.effective_from === '' || problem.value !== null
    || !Number.isInteger(periods.value) || periods.value < 1 || periods.value > 366);

const toOptions = (values: string[], group: string) => values.map((value) => ({ value, label: t(`payroll.${group}.${value}`) }));
const engineOptions = computed(() => props.options.engines.map((value) => ({ value, label: t(`payroll.engines.${value}`) })));
const categoryOptions = computed(() => toOptions(props.options.categories, 'ruleCategories'));
const payerOptions = computed(() => toOptions(props.options.payers, 'rulePayers'));
const basisOptions = computed(() => toOptions(props.options.bases, 'ruleBases'));
const methodOptions = computed(() => toOptions(props.options.methods, 'ruleMethods'));

function blank(from: number | null): SlabDraft {
    counter += 1;

    return { key: counter, from, to: null, rateBps: null, fixed: null };
}

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const rule = props.ruleSet;

    form.defaults({
        name: rule?.name ?? '',
        code: rule?.code ?? '',
        country: rule?.country ?? '',
        engine: rule?.engine ?? props.options.engines[0] ?? 'slab',
        category: rule?.category ?? props.options.categories[0] ?? 'income_tax',
        payer: rule?.payer ?? props.options.payers[0] ?? 'employee',
        basis: rule?.basis ?? props.options.bases[0] ?? 'taxable_gross',
        method: rule?.method ?? props.options.methods[0] ?? 'progressive',
        annualize: rule?.annualize ?? false,
        periods_per_year: String(rule?.periodsPerYear ?? 12),
        min_amount: rule?.minAmount ?? null,
        max_amount: rule?.maxAmount ?? null,
        employment_types: [...(rule?.employmentTypes ?? [])],
        effective_from: rule?.effectiveFrom ?? props.today,
        effective_to: rule?.effectiveTo ?? '',
        is_active: rule?.isActive ?? true,
        notes: rule?.notes ?? '',
    });
    form.reset();
    form.clearErrors();
    counter = 0;
    slabs.value = rule !== null && rule.slabs.length > 0
        ? rule.slabs.map((slab) => ({ ...blank(slab.from), to: slab.to, rateBps: slab.rateBps, fixed: slab.fixed > 0 ? slab.fixed : null }))
        : [blank(0)];
}, { immediate: true });

function addSlab(): void {
    const previous = slabs.value[slabs.value.length - 1];

    slabs.value.push(blank(previous?.to ?? null));
}

function removeSlab(index: number): void {
    slabs.value.splice(index, 1);
}

function move(index: number, delta: -1 | 1): void {
    const target = index + delta;

    if (target < 0 || target >= slabs.value.length) {
        return;
    }

    const [row] = slabs.value.splice(index, 1);
    slabs.value.splice(target, 0, row!);
}

function toggleType(value: string, on: boolean): void {
    form.employment_types = on
        ? [...form.employment_types, value]
        : form.employment_types.filter((type) => type !== value);
}

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        code: data.code.trim(),
        country: data.country.trim() === '' ? null : data.country.trim().toUpperCase(),
        engine: data.engine,
        category: data.category,
        payer: data.payer,
        basis: data.basis,
        method: data.method,
        annualize: data.annualize,
        periods_per_year: Number(data.periods_per_year),
        min_amount: data.min_amount,
        max_amount: data.max_amount,
        employment_types: data.employment_types.length > 0 ? data.employment_types : null,
        effective_from: data.effective_from,
        effective_to: data.effective_to === '' ? null : data.effective_to,
        is_active: data.is_active,
        notes: data.notes.trim() === '' ? null : data.notes.trim(),
        slabs: slabPayload(slabs.value),
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.ruleSet === null) {
        form.post(route('payroll.rules.store'), options);
    } else {
        form.put(route('payroll.rules.update', { ruleSet: props.ruleSet.id }), options);
    }
}
</script>

<template>
    <PayrollFormDialog
        :open="open"
        size="lg"
        :title="ruleSet === null ? t('payroll.rules.create') : t('payroll.rules.edit')"
        :description="t('payroll.rules.disclaimer')"
        :submit-label="ruleSet === null ? t('payroll.rules.create') : t('payroll.common.saveChanges')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.name" :label="t('payroll.fields.name')" :error="form.errors.name" maxlength="120" required autofocus />
            <Input v-model="form.code" :label="t('payroll.fields.code')" :error="form.errors.code" maxlength="40" mono required />
            <Select v-model="form.category" :options="categoryOptions" :label="t('payroll.fields.category')" :error="form.errors.category" required />
            <Select v-model="form.payer" :options="payerOptions" :label="t('payroll.fields.payer')" :hint="t('payroll.rules.payerHint')" :error="form.errors.payer" required />
            <Select v-model="form.basis" :options="basisOptions" :label="t('payroll.fields.basis')" :error="form.errors.basis" required />
            <Select v-model="form.method" :options="methodOptions" :label="t('payroll.fields.ruleMethod')" :error="form.errors.method" required />
            <Select v-if="engineOptions.length > 1" v-model="form.engine" :options="engineOptions" :label="t('payroll.fields.engine')" :error="form.errors.engine" required />
            <Input v-model="form.country" :label="t('payroll.fields.country')" :hint="t('payroll.rules.countryHint')" :error="form.errors.country" maxlength="2" optional />
            <Input v-model="form.effective_from" type="date" :label="t('payroll.fields.effectiveFrom')" :error="form.errors.effective_from" required />
            <Input v-model="form.effective_to" type="date" :label="t('payroll.fields.effectiveTo')" :error="form.errors.effective_to" optional />
            <MoneyInput v-model="form.min_amount" :currency="currency" :label="t('payroll.fields.minAmount')" :hint="t('payroll.rules.minHint')" :error="form.errors.min_amount" optional />
            <MoneyInput v-model="form.max_amount" :currency="currency" :label="t('payroll.fields.maxAmount')" :hint="t('payroll.rules.maxHint')" :error="form.errors.max_amount" optional />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Checkbox v-model="form.annualize" :label="t('payroll.rules.annualize')" :description="t('payroll.rules.annualizeHint')" />
            <Input
                v-if="form.annualize"
                v-model="form.periods_per_year"
                type="number"
                min="1"
                max="366"
                inputmode="numeric"
                :label="t('payroll.fields.periodsPerYear')"
                :error="form.errors.periods_per_year"
                required
            />
        </div>

        <fieldset class="grid gap-2">
            <legend class="eyebrow mb-1">{{ t('payroll.fields.employmentTypes') }}</legend>
            <p class="text-[0.8125rem] text-ink-subtle">{{ t('payroll.rules.employmentHint') }}</p>
            <div class="flex flex-wrap gap-x-5 gap-y-2">
                <Checkbox
                    v-for="type in options.employmentTypes"
                    :key="type"
                    :model-value="form.employment_types.includes(type)"
                    :label="t(`payroll.employmentTypes.${type}`)"
                    @update:model-value="toggleType(type, $event)"
                />
            </div>
        </fieldset>

        <fieldset class="grid gap-3" data-testid="slab-editor">
            <legend class="eyebrow mb-1">{{ t('payroll.rules.slabs') }}</legend>
            <p class="text-[0.8125rem] text-ink-subtle">{{ t('payroll.rules.slabsHint', { currency }) }}</p>
            <ol class="grid gap-3">
                <li v-for="(slab, index) in slabs" :key="slab.key" class="glass-well grid gap-3 rounded-card p-3" :data-testid="`slab-${index}`">
                    <div class="flex items-center justify-between gap-2">
                        <span class="eyebrow">{{ t('payroll.rules.band', { position: index + 1 }) }}</span>
                        <div class="flex gap-1">
                            <Button size="sm" variant="ghost" icon :disabled="index === 0" @click="move(index, -1)">
                                <template #icon><ArrowUp class="size-4" aria-hidden="true" /></template>
                                {{ t('payroll.rules.moveUp') }}
                            </Button>
                            <Button size="sm" variant="ghost" icon :disabled="index === slabs.length - 1" @click="move(index, 1)">
                                <template #icon><ArrowDown class="size-4" aria-hidden="true" /></template>
                                {{ t('payroll.rules.moveDown') }}
                            </Button>
                            <Button size="sm" variant="ghost" icon :disabled="slabs.length === 1" @click="removeSlab(index)">
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                                {{ t('payroll.rules.removeBand') }}
                            </Button>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <MoneyInput v-model="slab.from" :currency="currency" :label="t('payroll.fields.from')" :preview="false" />
                        <MoneyInput v-model="slab.to" :currency="currency" :label="t('payroll.fields.to')" :hint="t('payroll.rules.toHint')" :preview="false" optional />
                        <PercentInput v-model="slab.rateBps" :label="t('payroll.fields.rate')" />
                        <MoneyInput v-model="slab.fixed" :currency="currency" :label="t('payroll.fields.fixed')" :preview="false" optional />
                    </div>
                </li>
            </ol>
            <p v-if="problem !== null" class="text-sm text-danger" role="alert" data-testid="slab-problem">{{ t(`payroll.rules.slabErrors.${problem}`) }}</p>
            <p v-for="(message, key) in form.errors" v-show="String(key).startsWith('slabs')" :key="key" class="text-sm text-danger" role="alert">{{ message }}</p>
            <div>
                <Button size="sm" variant="secondary" @click="addSlab">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('payroll.rules.addBand') }}
                </Button>
            </div>
        </fieldset>

        <Textarea v-model="form.notes" :label="t('payroll.fields.note')" :error="form.errors.notes" maxlength="2000" optional />
        <Checkbox v-model="form.is_active" :label="t('payroll.fields.active')" :description="t('payroll.rules.activeHint')" />
    </PayrollFormDialog>
</template>
