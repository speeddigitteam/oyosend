<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import FormDialog from './FormDialog.vue';
import type { ContactRule } from './types';
import { RULE_FIELDS, ruleNeedsValue, ruleValueType } from './whatsappFormat';

/**
 * Add the contacts that match declarative rules to a list, once. The vocabulary of fields and conditions is
 * closed (the server checks it again); nothing typed here is evaluated.
 */
const props = defineProps<{ listId: string }>();

const open = defineModel<boolean>('open', { required: true });

const MAX_RULES = 10;

const { t } = useI18n();
const format = useFormat();

const form = useForm<{ match: 'all' | 'any'; rules: ContactRule[] }>({
    match: 'all',
    rules: [{ field: 'city', operator: 'equals', value: '' }],
});

const matchOptions = computed(() => [
    { value: 'all' as const, label: t('whatsapp.lists.matchAll') },
    { value: 'any' as const, label: t('whatsapp.lists.matchAny') },
]);
const fieldOptions = computed(() => Object.keys(RULE_FIELDS).map((field) => ({ value: field, label: t(`whatsapp.lists.ruleFields.${field}`) })));

function operatorOptions(field: string): { value: string; label: string }[] {
    return (RULE_FIELDS[field] ?? []).map((operator) => ({ value: operator, label: t(`whatsapp.lists.ruleOperators.${operator}`) }));
}

function patch(index: number, change: Partial<ContactRule>): void {
    const rules = [...form.rules];
    const next = { ...rules[index]!, ...change };

    if (change.field !== undefined) {
        next.operator = RULE_FIELDS[change.field]?.[0] ?? '';
        next.value = '';
    }

    rules[index] = next;
    form.rules = rules;
}

function addRule(): void {
    if (form.rules.length < MAX_RULES) {
        form.rules = [...form.rules, { field: 'city', operator: 'equals', value: '' }];
    }
}

function removeRule(index: number): void {
    form.rules = form.rules.filter((_, position) => position !== index);
}

const ready = computed(() => form.rules.length > 0 && form.rules.every((rule) => !ruleNeedsValue(rule.operator) || rule.value.trim() !== ''));

function submit(): void {
    form.post(route('whatsapp_marketing.lists.members.rules', { list: props.listId }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function error(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        size="lg"
        :title="t('whatsapp.lists.rulesTitle')"
        :description="t('whatsapp.lists.rulesDescription')"
        :submit-label="t('whatsapp.lists.rulesSubmit')"
        :processing="form.processing"
        :disabled="!ready"
        @submit="submit"
    >
        <SegmentedControl v-model="form.match" :options="matchOptions" :label="t('whatsapp.lists.match')" />
        <p v-if="error('rules')" class="text-xs text-danger" role="alert">{{ error('rules') }}</p>
        <ol class="grid gap-3">
            <li v-for="(rule, index) in form.rules" :key="index" class="grid gap-2 rounded-control border border-line p-3" data-testid="list-rule">
                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)]">
                    <Select
                        :model-value="rule.field"
                        :options="fieldOptions"
                        :label="t('whatsapp.lists.rule', { number: format.number(index + 1) })"
                        @update:model-value="patch(index, { field: String($event ?? '') })"
                    />
                    <Select
                        :model-value="rule.operator"
                        :options="operatorOptions(rule.field)"
                        :label="t('whatsapp.lists.operator')"
                        @update:model-value="patch(index, { operator: String($event ?? '') })"
                    />
                </div>
                <div class="flex items-end gap-2">
                    <Input
                        v-if="ruleNeedsValue(rule.operator)"
                        class="flex-1"
                        :type="ruleValueType(rule.operator)"
                        :model-value="rule.value"
                        :label="t('whatsapp.lists.value')"
                        :error="error(`rules.${index}`)"
                        maxlength="100"
                        @update:model-value="patch(index, { value: String($event ?? '') })"
                    />
                    <p v-else-if="error(`rules.${index}`)" class="flex-1 text-xs text-danger" role="alert">{{ error(`rules.${index}`) }}</p>
                    <span v-else class="flex-1" />
                    <Button variant="ghost" icon :disabled="form.rules.length <= 1" @click="removeRule(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.lists.removeRule', { number: format.number(index + 1) }) }}
                    </Button>
                </div>
            </li>
        </ol>
        <div v-if="form.rules.length < MAX_RULES">
            <Button size="sm" variant="secondary" @click="addRule">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.lists.addRule') }}
            </Button>
        </div>
    </FormDialog>
</template>
