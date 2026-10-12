<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { TOTAL_WEIGHT_BP, bpToPercentText, percentToBp, useTalentFormat } from './talentFormat';
import type { ReviewFormDetail } from './types';

/**
 * Creates or edits a review form: competencies with weights. The weights are
 * typed as percent and sent as basis points; they must add up to exactly 100%
 * and the running total is shown. A form used by a launched cycle is locked:
 * only its name, description and active flag change.
 */
const props = defineProps<{ open: boolean; reviewForm: ReviewFormDetail | null; locked: boolean }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

interface ItemDraft { name: string; description: string; weight: string }

const MAX_ITEMS = 30;

const form = useForm({
    name: '',
    description: '',
    scale_max: '5',
    is_active: true,
    items: [] as ItemDraft[],
});

const errorFor = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

const scaleOptions = computed(() => Array.from({ length: 8 }, (_, index) => ({ value: String(index + 3), label: format.number(index + 3) })));
const weights = computed(() => form.items.map((item) => percentToBp(item.weight)));
const total = computed(() => weights.value.reduce<number>((sum, weight) => sum + (weight ?? 0), 0));
const weightsInvalid = computed(() => weights.value.some((weight) => weight === null || weight < 1));
const balanced = computed(() => total.value === TOTAL_WEIGHT_BP && !weightsInvalid.value);
const unusable = computed(() => {
    if (form.name.trim() === '') {
        return true;
    }

    if (props.locked) {
        return false;
    }

    return form.items.length === 0 || form.items.some((item) => item.name.trim() === '') || !balanced.value;
});

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const source = props.reviewForm;

    form.defaults({
        name: source?.name ?? '',
        description: source?.description ?? '',
        scale_max: String(source?.scaleMax ?? 5),
        is_active: source?.isActive ?? true,
        items: source?.items.map((item) => ({ name: item.name, description: item.description ?? '', weight: bpToPercentText(item.weightBp) }))
            ?? [{ name: '', description: '', weight: '100' }],
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function addItem(): void {
    if (form.items.length < MAX_ITEMS) {
        form.items = [...form.items, { name: '', description: '', weight: '' }];
    }
}

function removeItem(index: number): void {
    form.items = form.items.filter((_, position) => position !== index);
}

function submit(): void {
    form.transform((data) => {
        const base = {
            name: data.name.trim(),
            description: data.description.trim() === '' ? null : data.description.trim(),
            scale_max: Number(data.scale_max),
            is_active: data.is_active,
        };

        if (props.locked) {
            return base;
        }

        return {
            ...base,
            items: data.items.map((item) => ({
                name: item.name.trim(),
                description: item.description.trim() === '' ? null : item.description.trim(),
                weight_bp: percentToBp(item.weight) ?? 0,
            })),
        };
    });

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.reviewForm) {
        form.put(route('talent.forms.update', { form: props.reviewForm.id }), options);
    } else {
        form.post(route('talent.forms.store'), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="lg"
        :title="reviewForm ? t('talent.forms.edit') : t('talent.forms.add')"
        :description="locked ? t('talent.forms.lockedHint') : t('talent.forms.hint')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('talent.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
        <Textarea v-model="form.description" :label="t('talent.fields.description')" maxlength="2000" optional :error="form.errors.description" />
        <div class="grid gap-3 sm:grid-cols-2">
            <Select v-model="form.scale_max" :options="scaleOptions" :disabled="locked" :label="t('talent.forms.scale')" :hint="t('talent.forms.scaleHint')" :error="form.errors.scale_max" />
            <div class="flex items-end pb-1">
                <Checkbox v-model="form.is_active" :label="t('talent.fields.active')" />
            </div>
        </div>

        <fieldset class="grid gap-3" :disabled="locked">
            <legend class="eyebrow mb-1">{{ t('talent.forms.competencies') }}</legend>
            <div v-for="(item, index) in form.items" :key="index" class="glass-well grid gap-2 rounded-control p-3" :data-testid="`competency-${index}`">
                <div class="grid gap-2 sm:grid-cols-[1fr_8rem]">
                    <Input v-model="item.name" :label="t('talent.forms.competency')" maxlength="160" :error="errorFor(`items.${index}.name`)" />
                    <Input
                        v-model="item.weight"
                        inputmode="decimal"
                        :label="t('talent.forms.weight')"
                        :error="errorFor(`items.${index}.weight_bp`)"
                    />
                </div>
                <Input v-model="item.description" :label="t('talent.fields.description')" maxlength="1000" optional :error="errorFor(`items.${index}.description`)" />
                <div v-if="!locked && form.items.length > 1" class="flex justify-end">
                    <Button type="button" size="sm" variant="ghost" @click="removeItem(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.common.remove') }}
                    </Button>
                </div>
            </div>
            <div v-if="!locked" class="flex flex-wrap items-center justify-between gap-2">
                <Button type="button" size="sm" variant="secondary" :disabled="form.items.length >= MAX_ITEMS" @click="addItem">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('talent.forms.addCompetency') }}
                </Button>
                <p
                    class="tabular text-sm"
                    :class="balanced ? 'text-ok' : 'text-warn'"
                    role="status"
                    data-testid="weights-total"
                >
                    {{ t('talent.forms.total', { total: talent.percent(total) }) }}
                    <span v-if="!balanced">- {{ t('talent.forms.mustBe100') }}</span>
                </p>
            </div>
            <p v-if="form.errors.items" class="text-xs text-danger" role="alert">{{ form.errors.items }}</p>
        </fieldset>
    </TalentFormDialog>
</template>
