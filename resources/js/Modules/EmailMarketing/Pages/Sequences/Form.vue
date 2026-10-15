<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BlockEditor from '../../Components/BlockEditor.vue';
import { delayMinutes, delayParts, moveItem, newStep, type DelayUnit } from '../../Components/emailFormat';
import type { Block, EditorOptions, ListOption, SequenceRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    sequence: SequenceRow | null;
    form: { lists: ListOption[]; editor: EditorOptions; maxSteps: number; maxDelayMinutes: number };
}>();

const { t } = useI18n();

interface DraftStep {
    id: string | null;
    value: string;
    unit: DelayUnit;
    subject: string;
    preheader: string;
    blocks: Block[];
}

function draft(step: { id: string | null; delayMinutes: number; subject: string; preheader: string | null; blocks: Block[] }): DraftStep {
    const parts = delayParts(step.delayMinutes);

    return { id: step.id, value: String(parts.value), unit: parts.unit, subject: step.subject, preheader: step.preheader ?? '', blocks: step.blocks };
}

const form = useForm<{ name: string; description: string; list_id: string; steps: DraftStep[] }>({
    name: props.sequence?.name ?? '',
    description: props.sequence?.description ?? '',
    list_id: props.sequence?.listId ?? props.form.lists[0]?.id ?? '',
    steps: (props.sequence?.steps.length ? props.sequence.steps : [newStep(1)]).map(draft),
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const listOptions = computed(() => props.form.lists.map((item) => ({ value: item.id, label: item.name })));
const unitOptions = computed(() => [
    { value: 'minutes', label: t('emailMarketing.sequences.units.minutes') },
    { value: 'hours', label: t('emailMarketing.sequences.units.hours') },
    { value: 'days', label: t('emailMarketing.sequences.units.days') },
]);

function minutes(step: DraftStep): number {
    return delayMinutes(Number(step.value) || 0, step.unit);
}

const invalid = computed(
    () =>
        form.name.trim() === '' ||
        form.list_id === '' ||
        form.steps.length === 0 ||
        form.steps.some((step) => step.subject.trim() === '' || step.blocks.length === 0 || minutes(step) > props.form.maxDelayMinutes),
);

function addStep(): void {
    if (form.steps.length < props.form.maxSteps) {
        form.steps.push(draft(newStep(form.steps.length + 1)));
    }
}

function save(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
        list_id: data.list_id,
        steps: data.steps.map((step) => ({
            id: step.id,
            delay_minutes: minutes(step),
            subject: step.subject.trim(),
            preheader: step.preheader.trim() === '' ? null : step.preheader.trim(),
            blocks: step.blocks,
        })),
    }));

    if (props.sequence) {
        form.put(route('email_marketing.sequences.update', { sequence: props.sequence.id }), { preserveScroll: true });
    } else {
        form.post(route('email_marketing.sequences.store'), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('emailMarketing.eyebrow')"
        :title="sequence ? t('emailMarketing.sequences.edit') : t('emailMarketing.sequences.add')"
        :description="t('emailMarketing.sequences.formDescription')"
    />

    <!-- Full width: the details across the top, then one box per step in order. Each box lays out its own content by
         its own width (container queries): on a wide box the step's settings sit beside its content, like the campaign
         form's two-thirds editor; on a narrow one they stack. One save for the whole sequence below the boxes. -->
    <form class="grid gap-3" novalidate @submit.prevent="save">
        <Card :title="t('emailMarketing.campaigns.details')">
            <div class="@container">
                <div class="grid gap-4 @2xl:grid-cols-2 @5xl:grid-cols-3">
                    <Input v-model="form.name" :label="t('emailMarketing.fields.name')" maxlength="150" required :error="errors.name" />
                    <Select
                        v-model="form.list_id"
                        :options="listOptions"
                        :label="t('emailMarketing.sequences.trigger')"
                        :hint="t('emailMarketing.sequences.triggerHint')"
                        :error="errors.list_id"
                    />
                    <div class="@2xl:col-span-2 @5xl:col-span-1">
                        <Textarea v-model="form.description" :label="t('emailMarketing.fields.description')" maxlength="500" optional :error="errors.description" />
                    </div>
                </div>
            </div>
            <p v-if="errors.sequence" class="mt-3 text-sm text-danger" role="alert">{{ errors.sequence }}</p>
        </Card>

        <Card v-for="(step, index) in form.steps" :key="index" :title="t('emailMarketing.sequences.stepTitle', { number: index + 1 })">
            <template #actions>
                <Button size="sm" variant="ghost" icon :disabled="index === 0" :aria-label="t('emailMarketing.editor.moveUp')" @click="form.steps = moveItem(form.steps, index, -1)">
                    <template #icon><ArrowUp class="size-4" aria-hidden="true" /></template>
                </Button>
                <Button size="sm" variant="ghost" icon :disabled="index === form.steps.length - 1" :aria-label="t('emailMarketing.editor.moveDown')" @click="form.steps = moveItem(form.steps, index, 1)">
                    <template #icon><ArrowDown class="size-4" aria-hidden="true" /></template>
                </Button>
                <Button size="sm" variant="ghost" icon :disabled="form.steps.length === 1" :aria-label="t('emailMarketing.editor.remove')" @click="form.steps.splice(index, 1)">
                    <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                </Button>
            </template>
            <div class="@container">
                <div class="grid gap-4 @4xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <!-- The settings lay out by their own column's width: four across when stacked on a wide box, two in
                         the side column. -->
                    <div class="@container">
                        <div class="grid content-start gap-4 @xs:grid-cols-2 @2xl:grid-cols-4">
                            <Input
                                v-model="step.value"
                                inputmode="numeric"
                                :label="index === 0 ? t('emailMarketing.sequences.delayFirst') : t('emailMarketing.sequences.delayAfter')"
                                :error="errors[`steps.${index}.delay_minutes`] ?? (minutes(step) > props.form.maxDelayMinutes ? t('emailMarketing.sequences.delayTooLong') : undefined)"
                            />
                            <Select v-model="step.unit" :options="unitOptions" :label="t('emailMarketing.sequences.unit')" />
                            <div class="@xs:col-span-2">
                                <Input v-model="step.subject" :label="t('emailMarketing.fields.subject')" maxlength="200" required :error="errors[`steps.${index}.subject`]" />
                            </div>
                            <div class="@xs:col-span-2 @2xl:col-span-4">
                                <Input v-model="step.preheader" :label="t('emailMarketing.fields.preheader')" maxlength="200" optional />
                            </div>
                        </div>
                    </div>
                    <BlockEditor
                        v-model="step.blocks"
                        :variables="props.form.editor.variables"
                        :errors="errors"
                        :error-prefix="`steps.${index}.blocks`"
                        :max="props.form.editor.maxBlocks"
                        :max-text="props.form.editor.maxText"
                    />
                </div>
            </div>
        </Card>

        <!-- Adding a step on the left; the one save for the whole sequence on the right. -->
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <Button :disabled="form.steps.length >= props.form.maxSteps" @click="addStep">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('emailMarketing.sequences.addStep') }}
            </Button>
            <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                <Button variant="ghost" :href="sequence ? route('email_marketing.sequences.show', { sequence: sequence.id }) : route('email_marketing.sequences.index')">
                    {{ t('emailMarketing.common.cancel') }}
                </Button>
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="invalid">{{ t('emailMarketing.common.save') }}</Button>
            </div>
        </div>
    </form>
</template>
