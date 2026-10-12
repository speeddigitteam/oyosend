<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';
import type { Goal, GoalKind, NamedRef, PersonOption } from './types';

/**
 * Creates or edits a goal or KPI. Values are whole numbers with an optional
 * unit; a goal's start value cannot change after it has been created.
 */
const props = defineProps<{
    open: boolean;
    goal: Goal | null;
    kinds: GoalKind[];
    employees: PersonOption[];
    cycles: NamedRef[];
    ownEmployeeId: string | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({
    employee_id: '',
    title: '',
    description: '',
    kind: 'goal' as GoalKind,
    unit: '',
    start_value: '0',
    target_value: '',
    due_on: '',
    cycle_id: '',
});

const kindOptions = computed(() => props.kinds.map((value) => ({ value, label: t(`talent.status.goalKind.${value}`) })));
const employeeOptions = computed(() => props.employees.map((person) => ({ value: person.id, label: `${person.name} (${person.number})` })));
const cycleOptions = computed(() => [{ value: '', label: t('talent.common.none') }, ...props.cycles.map((cycle) => ({ value: cycle.id, label: cycle.name }))]);
const start = computed(() => parseInteger(form.start_value, true));
const target = computed(() => parseInteger(form.target_value, true));
const unusable = computed(() => form.title.trim() === '' || start.value === null || target.value === null);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const goal = props.goal;

    form.defaults({
        employee_id: props.ownEmployeeId ?? props.employees[0]?.id ?? '',
        title: goal?.title ?? '',
        description: goal?.description ?? '',
        kind: goal?.kind ?? 'goal',
        unit: goal?.unit ?? '',
        start_value: String(goal?.startValue ?? 0),
        target_value: goal ? String(goal.targetValue) : '',
        due_on: goal?.dueOn ?? '',
        cycle_id: goal?.cycleId ?? '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form.transform((data) => {
        const base = {
            title: data.title.trim(),
            description: blank(data.description),
            unit: blank(data.unit),
            target_value: target.value,
            due_on: blank(data.due_on),
        };

        if (props.goal) {
            return base;
        }

        return {
            ...base,
            employee_id: data.employee_id === '' ? null : data.employee_id,
            kind: data.kind,
            start_value: start.value,
            cycle_id: blank(data.cycle_id),
        };
    });

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.goal) {
        form.put(route('talent.goals.update', { goal: props.goal.id }), options);
    } else {
        form.post(route('talent.goals.store'), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="goal ? t('talent.goals.edit') : t('talent.goals.add')"
        :description="t('talent.goals.formHint')"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select
            v-if="!goal && employees.length > 0"
            v-model="form.employee_id"
            :options="employeeOptions"
            :label="t('talent.fields.employee')"
            :error="form.errors.employee_id"
        />
        <Input v-model="form.title" :label="t('talent.fields.title')" maxlength="200" :error="form.errors.title" required autofocus />
        <Textarea v-model="form.description" :label="t('talent.fields.description')" maxlength="3000" optional :error="form.errors.description" />
        <div class="grid gap-3 sm:grid-cols-2">
            <Select v-if="!goal" v-model="form.kind" :options="kindOptions" :label="t('talent.fields.type')" :error="form.errors.kind" />
            <Input v-model="form.unit" :label="t('talent.goals.unit')" maxlength="40" optional :error="form.errors.unit" />
            <Input v-if="!goal" v-model="form.start_value" inputmode="numeric" :label="t('talent.goals.startValue')" :error="form.errors.start_value" required />
            <Input v-model="form.target_value" inputmode="numeric" :label="t('talent.goals.targetValue')" :error="form.errors.target_value" required />
            <Input v-model="form.due_on" type="date" :label="t('talent.fields.dueOn')" optional :error="form.errors.due_on" />
            <Select v-if="!goal" v-model="form.cycle_id" :options="cycleOptions" :label="t('talent.goals.cycle')" :error="form.errors.cycle_id" />
        </div>
    </TalentFormDialog>
</template>
