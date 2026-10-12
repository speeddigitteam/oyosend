<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import { rangeDays, rangeProblem, sharingComplete } from './reportFormat';
import type { Bounds, RangeMode, ReportDefinition, SavedRow, ShareOptions, Visibility } from './types';

/**
 * Save the report setup that is currently in the form, as a new saved report or over an existing
 * one. Only the setup is stored (provider, columns, filters, range, who may open it), never data.
 */
const props = defineProps<{
    open: boolean;
    report: ReportDefinition;
    saved: SavedRow | null;
    /** The setup currently in the form. */
    setup: { from: string; to: string; columns: string[]; filters: Record<string, string> };
    share: ShareOptions;
    bounds: Bounds;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();
const formId = useId();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const updating = computed(() => props.saved !== null);

const form = useForm({
    name: '',
    visibility: 'private' as Visibility,
    role_id: '',
    department_id: '',
    range_mode: 'last_days' as RangeMode,
    range_days: 30,
    range_from: '',
    range_to: '',
});

function reset(): void {
    const saved = props.saved;

    form.clearErrors();
    form.name = saved?.name ?? '';
    form.visibility = saved?.visibility ?? 'private';
    form.role_id = saved?.roleId ?? '';
    form.department_id = saved?.departmentId ?? '';
    form.range_mode = saved?.rangeMode ?? 'last_days';
    form.range_days = saved?.rangeMode === 'last_days'
        ? saved.rangeDays
        : rangeDays(props.setup.from, props.setup.to, props.bounds.maxDays, props.bounds.defaultDays);
    form.range_from = props.setup.from;
    form.range_to = props.setup.to;
}

watch(() => props.open, (open) => { if (open) { reset(); } }, { immediate: true });

const visibilityOptions = computed(() => [
    { value: 'private', label: t('reports.visibility.private') },
    ...(props.share.roles.length > 0 ? [{ value: 'role', label: t('reports.visibility.role') }] : []),
    ...(props.share.departments.length > 0 ? [{ value: 'department', label: t('reports.visibility.department') }] : []),
]);
const rangeOptions = computed(() => [
    { value: 'last_days' as RangeMode, label: t('reports.save.lastDays') },
    { value: 'fixed' as RangeMode, label: t('reports.save.fixed') },
]);
const roleOptions = computed(() => props.share.roles.map((role) => ({ value: role.id, label: role.name })));
const departmentOptions = computed(() => props.share.departments.map((department) => ({ value: department.id, label: department.name })));

const fixedProblem = computed(() => (form.range_mode === 'fixed' ? rangeProblem(form.range_from, form.range_to, props.bounds.maxDays) : null));
const daysValid = computed(() => Number.isInteger(Number(form.range_days)) && Number(form.range_days) >= 1 && Number(form.range_days) <= props.bounds.maxDays);
const rangeValid = computed(() => {
    if (!props.report.usesDateRange) {
        return true;
    }

    return form.range_mode === 'fixed' ? fixedProblem.value === null : daysValid.value;
});
const canSubmit = computed(() => form.name.trim() !== ''
    && sharingComplete(form.visibility, form.role_id, form.department_id)
    && rangeValid.value);

const KNOWN = ['name', 'visibility', 'role_id', 'department_id', 'range_mode', 'range_days', 'range_from', 'range_to'];
const otherErrors = computed(() => Object.entries(form.errors).filter(([key]) => !KNOWN.includes(key)).map(([, message]) => message));

function submit(): void {
    const fixed = props.report.usesDateRange && form.range_mode === 'fixed';

    form.transform((data) => ({
        name: data.name.trim(),
        provider_key: props.report.key,
        columns: props.setup.columns,
        filters: props.setup.filters,
        range_mode: fixed ? 'fixed' : 'last_days',
        range_days: fixed ? null : (props.report.usesDateRange ? Number(data.range_days) : props.bounds.defaultDays),
        range_from: fixed ? data.range_from : null,
        range_to: fixed ? data.range_to : null,
        visibility: data.visibility,
        role_id: data.visibility === 'role' ? data.role_id : null,
        department_id: data.visibility === 'department' ? data.department_id : null,
    }));

    const options = { preserveScroll: true, onSuccess: () => { model.value = false; } };

    if (props.saved !== null) {
        form.put(route('reports.saved.update', { saved: props.saved.id }), options);
    } else {
        form.post(route('reports.saved.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="model"
        :title="updating ? t('reports.save.titleUpdate') : t('reports.save.titleCreate')"
        :description="t('reports.save.description')"
        :persistent="form.processing"
    >
        <form :id="formId" class="grid gap-4" novalidate @submit.prevent="submit">
            <Input v-model="form.name" :label="t('reports.save.name')" :error="form.errors.name" maxlength="120" required />

            <Select v-model="form.visibility" :options="visibilityOptions" :label="t('reports.save.visibility')" :error="form.errors.visibility" />
            <Select
                v-if="form.visibility === 'role'"
                v-model="form.role_id"
                :options="roleOptions"
                :placeholder="t('reports.save.chooseRole')"
                :label="t('reports.save.role')"
                :error="form.errors.role_id"
            />
            <Select
                v-if="form.visibility === 'department'"
                v-model="form.department_id"
                :options="departmentOptions"
                :placeholder="t('reports.save.chooseDepartment')"
                :label="t('reports.save.department')"
                :error="form.errors.department_id"
            />
            <p v-if="form.visibility !== 'private'" class="-mt-2 text-[0.8125rem] text-ink-subtle">{{ t('reports.save.shareHint') }}</p>

            <template v-if="report.usesDateRange">
                <Field :label="t('reports.save.range')">
                    <SegmentedControl v-model="form.range_mode" :options="rangeOptions" :label="t('reports.save.range')" />
                </Field>
                <Input
                    v-if="form.range_mode === 'last_days'"
                    v-model="form.range_days"
                    type="number"
                    min="1"
                    :max="bounds.maxDays"
                    :label="t('reports.save.days')"
                    :hint="t('reports.save.daysHint', { days: bounds.maxDays })"
                    :error="form.errors.range_days"
                />
                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Input v-model="form.range_from" type="date" :label="t('reports.run.from')" :error="form.errors.range_from" />
                        <Input v-model="form.range_to" type="date" :label="t('reports.run.to')" :error="form.errors.range_to" />
                    </div>
                    <p v-if="fixedProblem" class="-mt-2 text-[0.8125rem] text-danger" role="alert">
                        {{ t(`reports.run.range.${fixedProblem}`, { days: bounds.maxDays }) }}
                    </p>
                </template>
            </template>

            <p v-for="message in otherErrors" :key="message" class="text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('reports.common.cancel') }}</Button>
            <Button type="submit" :form="formId" variant="primary" :loading="form.processing" :disabled="!canSubmit">
                {{ updating ? t('reports.save.submitUpdate') : t('reports.save.submitCreate') }}
            </Button>
        </template>
    </Modal>
</template>
