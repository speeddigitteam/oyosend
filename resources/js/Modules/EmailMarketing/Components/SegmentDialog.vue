<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { SegmentRow, SegmentRule } from './types';

/**
 * Create or edit a segment: "all" or "any" of up to ten rules, each a field, an operator and a value picked from
 * fixed lists. There is no expression to type; the server only accepts what these lists contain.
 */
const props = defineProps<{
    open: boolean;
    listId: string;
    segment: SegmentRow | null;
    fields: string[];
    operators: Record<string, string[]>;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

interface DraftRule {
    field: string;
    key: string;
    operator: string;
    value: string;
}

const form = useForm<{ name: string; match: 'all' | 'any'; rules: DraftRule[] }>({ name: '', match: 'all', rules: [] });

const TEXT_OPERATORS = ['equals', 'not_equals', 'contains', 'exists', 'not_exists'];
const NO_VALUE = new Set(['exists', 'not_exists', 'is_true', 'is_false']);

function blank(): DraftRule {
    return { field: props.fields[0] ?? 'city', key: '', operator: (props.operators[props.fields[0] ?? 'city'] ?? TEXT_OPERATORS)[0] ?? 'equals', value: '' };
}

function fromRule(rule: SegmentRule): DraftRule {
    const isProperty = rule.field.startsWith('property:');

    return {
        field: isProperty ? 'property' : rule.field,
        key: isProperty ? rule.field.slice(9) : '',
        operator: rule.operator,
        value: rule.value ?? '',
    };
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.defaults({
                name: props.segment?.name ?? '',
                match: props.segment?.match ?? 'all',
                rules: props.segment ? props.segment.rules.map(fromRule) : [blank()],
            });
            form.reset();
            form.clearErrors();
        }
    },
    { immediate: true },
);

const fieldOptions = computed(() => [
    ...props.fields.map((value) => ({ value, label: t(`emailMarketing.segments.fields.${value}`) })),
    { value: 'property', label: t('emailMarketing.segments.fields.property') },
]);

function operatorsFor(field: string): { value: string; label: string }[] {
    const list = field === 'property' ? TEXT_OPERATORS : (props.operators[field] ?? TEXT_OPERATORS);

    return list.map((value) => ({ value, label: t(`emailMarketing.segments.operators.${value}`) }));
}

function changeField(index: number, field: string): void {
    const rule = form.rules[index];

    if (rule) {
        rule.field = field;
        rule.operator = operatorsFor(field)[0]?.value ?? 'equals';
        rule.value = '';
    }
}

function needsValue(rule: DraftRule): boolean {
    return !NO_VALUE.has(rule.operator);
}

function inputType(rule: DraftRule): string {
    return rule.field === 'subscribed_after' || rule.field === 'subscribed_before' ? 'date' : 'text';
}

const valid = computed(
    () =>
        form.name.trim() !== '' &&
        form.rules.length > 0 &&
        form.rules.every((rule) => (rule.field !== 'property' || /^[a-z][a-z0-9_]{0,31}$/.test(rule.key)) && (!needsValue(rule) || rule.value.trim() !== '')),
);

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        match: data.match,
        rules: data.rules.map((rule) => ({
            field: rule.field === 'property' ? `property:${rule.key}` : rule.field,
            operator: rule.operator,
            value: needsValue(rule) ? rule.value.trim() : null,
        })),
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.segment) {
        form.put(route('email_marketing.segments.update', { segment: props.segment.id }), options);
    } else {
        form.post(route('email_marketing.segments.store', { list: props.listId }), options);
    }
}
</script>

<template>
    <Modal
        :open="open"
        size="lg"
        :title="segment ? t('emailMarketing.segments.edit') : t('emailMarketing.segments.add')"
        :description="t('emailMarketing.segments.description')"
        @update:open="emit('update:open', $event)"
    >
        <form id="segment-form" class="grid gap-4" novalidate @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.name" :label="t('emailMarketing.fields.name')" maxlength="120" required autofocus :error="form.errors.name" />
                <Select
                    v-model="form.match"
                    :label="t('emailMarketing.segments.match')"
                    :options="[
                        { value: 'all', label: t('emailMarketing.segments.matchAll') },
                        { value: 'any', label: t('emailMarketing.segments.matchAny') },
                    ]"
                />
            </div>

            <ul class="grid gap-3" data-testid="segment-rules">
                <li v-for="(rule, index) in form.rules" :key="index" class="glass-well grid gap-3 rounded-card p-3 sm:grid-cols-4">
                    <Select
                        :model-value="rule.field"
                        :label="t('emailMarketing.segments.field')"
                        :options="fieldOptions"
                        @update:model-value="changeField(index, String($event))"
                    />
                    <Input v-if="rule.field === 'property'" v-model="rule.key" :label="t('emailMarketing.segments.propertyName')" maxlength="32" />
                    <Select v-model="rule.operator" :label="t('emailMarketing.segments.operator')" :options="operatorsFor(rule.field)" />
                    <Input
                        v-if="needsValue(rule)"
                        v-model="rule.value"
                        :type="inputType(rule)"
                        :label="t('emailMarketing.segments.value')"
                        maxlength="100"
                    />
                    <div class="flex items-end">
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="form.rules.length === 1"
                            :aria-label="t('emailMarketing.segments.removeRule')"
                            @click="form.rules.splice(index, 1)"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <p v-if="form.errors[`rules.${index}` as keyof typeof form.errors]" class="text-sm text-danger sm:col-span-4" role="alert">
                        {{ form.errors[`rules.${index}` as keyof typeof form.errors] }}
                    </p>
                </li>
            </ul>
            <div>
                <Button size="sm" :disabled="form.rules.length >= 10" @click="form.rules.push(blank())">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('emailMarketing.segments.addRule') }}
                </Button>
            </div>
            <p v-if="form.errors.rules" class="text-sm text-danger" role="alert">{{ form.errors.rules }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" @click="emit('update:open', false)">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button type="submit" form="segment-form" variant="primary" :loading="form.processing" :disabled="!valid">
                {{ t('emailMarketing.common.save') }}
            </Button>
        </template>
    </Modal>
</template>
