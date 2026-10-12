<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Select from '@/Core/Components/Select.vue';
import { isTemplate, parseLooseValue } from '../lib/schema';
import type { OperatorDefinition } from './types';
import VariableInput from './VariableInput.vue';

/**
 * A condition: match all or any of a list of rules, where a rule can itself be
 * a group (up to four levels, as the server allows). Everything is declarative:
 * a rule is a value from the run, an operator from the registry and a value.
 */
export interface RuleGroup {
    match?: string;
    rules?: unknown[];
    [key: string]: unknown;
}

const props = withDefaults(
    defineProps<{
        group: RuleGroup;
        operators: OperatorDefinition[];
        suggestions: string[];
        disabled?: boolean;
        depth?: number;
        maxDepth?: number;
        removable?: boolean;
    }>(),
    { depth: 1, maxDepth: 4 },
);

const emit = defineEmits<{ 'update:group': [value: RuleGroup]; remove: [] }>();
const { t } = useI18n();

type Rule = { field?: unknown; operator?: unknown; value?: unknown; value2?: unknown; rules?: unknown[]; match?: string; [key: string]: unknown };

const rules = computed<Rule[]>(() => (Array.isArray(props.group.rules) ? (props.group.rules as Rule[]) : []));
const operatorOptions = computed(() => props.operators.map((operator) => ({ value: operator.key, label: t(`automation.operators.${operator.key}`) })));
const matchOptions = computed(() => [
    { value: 'all', label: t('automation.editor.condition.matchAll') },
    { value: 'any', label: t('automation.editor.condition.matchAny') },
]);

const DAY_OPERATORS = new Set(['within_last_days', 'within_next_days']);

function isGroup(rule: Rule): boolean {
    return Array.isArray(rule.rules);
}

function definition(rule: Rule): OperatorDefinition | undefined {
    return props.operators.find((operator) => operator.key === rule.operator);
}

function commit(next: unknown[], match: string = props.group.match === 'any' ? 'any' : 'all'): void {
    emit('update:group', { ...props.group, match, rules: next });
}

function replace(index: number, rule: Rule): void {
    commit(rules.value.map((item, position) => (position === index ? rule : item)));
}

function addRule(): void {
    commit([...rules.value, { field: '', operator: 'equals', value: '' }]);
}

function addGroup(): void {
    commit([...rules.value, { match: 'all', rules: [{ field: '', operator: 'equals', value: '' }] }]);
}

function remove(index: number): void {
    commit(rules.value.filter((_, position) => position !== index));
}

function show(value: unknown): string {
    if (Array.isArray(value)) {
        return value.map((item) => String(item)).join(', ');
    }

    return value === undefined || value === null ? '' : String(value);
}

function typed(rule: Rule, raw: string): unknown {
    const numeric = definition(rule)?.types.includes('number') === true || DAY_OPERATORS.has(String(rule.operator));

    if (numeric && !isTemplate(raw)) {
        return parseLooseValue(raw);
    }

    return raw;
}

function setField(index: number, field: string): void {
    replace(index, { ...rules.value[index], field });
}

function setOperator(index: number, operator: string | number | null | undefined): void {
    const next: Rule = { ...rules.value[index], operator: String(operator ?? 'equals') };
    const arity = props.operators.find((item) => item.key === next.operator)?.arity ?? 'one';

    if (arity === 'none') {
        delete next.value;
        delete next.value2;
    } else {
        if (arity === 'list') {
            next.value = Array.isArray(next.value) ? next.value : [];
        } else {
            next.value = Array.isArray(next.value) ? show(next.value) : (next.value ?? '');
        }

        if (arity === 'two') {
            next.value2 = next.value2 ?? '';
        } else {
            delete next.value2;
        }
    }

    replace(index, next);
}

function setValue(index: number, raw: string): void {
    const rule = rules.value[index];
    const arity = definition(rule)?.arity ?? 'one';

    if (arity === 'list') {
        replace(index, {
            ...rule,
            value: isTemplate(raw) ? raw : raw.split(',').map((item) => item.trim()).filter((item) => item !== ''),
        });

        return;
    }

    replace(index, { ...rule, value: typed(rule, raw) });
}

function setValue2(index: number, raw: string): void {
    const rule = rules.value[index];
    replace(index, { ...rule, value2: typed(rule, raw) });
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-0 flex-1 basis-48">
                <Select
                    :model-value="group.match === 'any' ? 'any' : 'all'"
                    :options="matchOptions"
                    :label="t('automation.editor.condition.match')"
                    :disabled="disabled"
                    @update:model-value="commit(rules, String($event))"
                />
            </div>
            <Button v-if="removable && !disabled" size="sm" variant="ghost" type="button" @click="emit('remove')">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.condition.removeGroup') }}
            </Button>
        </div>

        <p v-if="rules.length === 0" class="text-[0.8125rem] text-ink-subtle">{{ t('automation.editor.condition.emptyGroup') }}</p>

        <ul class="space-y-3">
            <li v-for="(rule, index) in rules" :key="index">
                <div v-if="isGroup(rule)" class="glass-well rounded-card p-3">
                    <p class="eyebrow mb-2">{{ t('automation.editor.condition.group') }}</p>
                    <RuleGroupEditor
                        :group="rule"
                        :operators="operators"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        :depth="depth + 1"
                        :max-depth="maxDepth"
                        removable
                        @update:group="replace(index, $event)"
                        @remove="remove(index)"
                    />
                </div>

                <div v-else class="glass-well space-y-2 rounded-card p-3">
                    <VariableInput
                        :model-value="show(rule.field)"
                        :label="t('automation.editor.condition.field')"
                        :hint="t('automation.editor.condition.fieldHint')"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        :maxlength="200"
                        @update:model-value="setField(index, $event)"
                    />
                    <Select
                        :model-value="String(rule.operator ?? 'equals')"
                        :options="operatorOptions"
                        :label="t('automation.editor.condition.operator')"
                        :disabled="disabled"
                        @update:model-value="setOperator(index, $event)"
                    />
                    <VariableInput
                        v-if="definition(rule)?.arity === 'one' || definition(rule)?.arity === 'two'"
                        :model-value="show(rule.value)"
                        :label="DAY_OPERATORS.has(String(rule.operator)) ? t('automation.editor.condition.days') : t('automation.editor.condition.value')"
                        mode="template"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        @update:model-value="setValue(index, $event)"
                    />
                    <VariableInput
                        v-if="definition(rule)?.arity === 'two'"
                        :model-value="show(rule.value2)"
                        :label="t('automation.editor.condition.value2')"
                        mode="template"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        @update:model-value="setValue2(index, $event)"
                    />
                    <VariableInput
                        v-if="definition(rule)?.arity === 'list'"
                        :model-value="show(rule.value)"
                        :label="t('automation.editor.condition.valueList')"
                        mode="template"
                        :suggestions="suggestions"
                        :disabled="disabled"
                        @update:model-value="setValue(index, $event)"
                    />
                    <div v-if="!disabled" class="flex justify-end">
                        <Button size="sm" variant="ghost" type="button" @click="remove(index)">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            {{ t('automation.editor.condition.removeRule') }}
                        </Button>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="!disabled" class="flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" type="button" @click="addRule">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.condition.addRule') }}
            </Button>
            <Button v-if="depth < maxDepth" size="sm" variant="secondary" type="button" @click="addGroup">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.editor.condition.addGroup') }}
            </Button>
        </div>
    </div>
</template>
